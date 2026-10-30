<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'operations-center',
  8 => 'archive-data',
  1 => 'chat-messages',
  2 => 'notifications-read',
  3 => 'bank-accounts',
  4 => 'db-reset',
  5 => 'email-report',
  6 => 'send-shift-report',
  7 => 'runtime-diagnostics',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'archive-data':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        requireDesktopTabAccess($loggedInStaff,'report',['admin','manager','finance']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        try {
            $from=trim((string)($_GET['from']??date('Y-m-01')));
            $to=trim((string)($_GET['to']??date('Y-m-d')));
            echo tamasyaJsonEncode(['success'=>true,'data'=>getArchiveRangeData($pdo,$loggedInStaff,$from,$to)]);
        } catch(Throwable $archiveError) {
            tamasyaApplyExceptionHttpStatus($archiveError,$archiveError instanceof InvalidArgumentException?422:500);
            echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Gagal memuat arsip',$archiveError)]);
        }
        break;

    case 'runtime-diagnostics':
        requireRoles($loggedInStaff, ['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed','requestId'=>tamasyaRuntimeRequestId()]);
            break;
        }
        try {
            tamasyaRuntimeSetStage('diagnostics:collect');
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $includeSchema = filter_var((string)($_GET['schema'] ?? '0'), FILTER_VALIDATE_BOOLEAN);
            echo tamasyaJsonEncode([
                'success'=>true,
                'requestId'=>tamasyaRuntimeRequestId(),
                'diagnostics'=>tamasyaRuntimeDiagnostics($pdo,$limit,$includeSchema),
            ]);
        } catch (Throwable $diagnosticError) {
            tamasyaRuntimeSetStage('diagnostics:error');
            $status = tamasyaApplyExceptionHttpStatus($diagnosticError, 500);
            tamasyaRuntimePersist($pdo,$status,$diagnosticError,'Runtime diagnostics failed');
            echo tamasyaJsonEncode([
                'success'=>false,
                'error'=>clientExceptionMessage('Gagal membaca runtime diagnostics',$diagnosticError),
                'requestId'=>tamasyaRuntimeRequestId(),
                'stage'=>(string)($GLOBALS['tamasya_runtime_stage'] ?? 'diagnostics:error'),
            ]);
        }
        break;

    case 'operations-center':
        $method = $_SERVER['REQUEST_METHOD'];
        $command = trim((string)($input['command'] ?? ''));
        $allOperationalRoles = ['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'];
        requireRoles($loggedInStaff, $allOperationalRoles);

        if ($method === 'GET') {
            requireDesktopTabAccess($loggedInStaff,'operations',$allOperationalRoles);
            $view = trim((string)($_GET['view'] ?? ''));
            if ($view === 'guest-service-indicator') {
                // Global UI badge needs a small, non-sensitive projection only.
                // Do not poll the entire hotel-data/operations payload just to
                // discover lobby work, and never expose guest contact/finance.
                requireRoles($loggedInStaff,['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
                $rows=[];
                if(tamasyaTableExists($pdo,'guest_service_requests')){
                    $stmt=$pdo->query("SELECT id,request_type,priority,status,needed_at,created_at FROM guest_service_requests WHERE status IN ('open','assigned','in_progress') ORDER BY FIELD(priority,'urgent','high','normal'),COALESCE(needed_at,'9999-12-31 23:59:59'),created_at ASC LIMIT 100");
                    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                }
                echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows),'serverTime'=>date('c')]);
                break;
            }
            echo json_encode(['success'=>true,'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);
            break;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }

        // Perintah operations-center bersifat multiplexer. Heartbeat, logout, dan
        // binding identitas tetap dapat dipakai tanpa membuka seluruh Pusat
        // Operasional, sedangkan perintah bisnis mengikuti desktopTabs terkait.
        $operationCommandTabs = [
            'derived-state-refresh'=>['operations'],
            'shift-open'=>['operations'], 'shift-close'=>['operations'], 'shift-cash-revise'=>['operations'],
            'approval-create'=>['operations'], 'approval-decide'=>['operations'],
            'approval-policy-save'=>['operations'],
            'session-revoke'=>['operations','config'], 'device-disable'=>['operations','config'],
            'reconciliation-import'=>['finance','report','operations'],
            'reconciliation-save'=>['finance','report','operations'],
            'reconciliation-status'=>['finance','report','operations'],
            'tax-rule-save'=>['finance','report','operations'],
            'tax-rule-toggle'=>['finance','report','operations'],
            'vacancy-report-create'=>['operations'], 'vacancy-report-detail-update'=>['operations'], 'vacancy-report-review'=>['operations'],
            'housekeeping-save'=>['operations'], 'housekeeping-status'=>['operations'],
            'maintenance-ticket-save'=>['operations'], 'maintenance-ticket-status'=>['operations'],
            'guest-profile-save'=>['operations'], 'guest-sync'=>['operations'], 'guest-service-open'=>['operations'], 'guest-service-progress'=>['operations'], 'guest-service-close'=>['operations'],
            'lost-found-open'=>['operations'], 'lost-found-secure'=>['operations'], 'lost-found-notify'=>['operations'], 'lost-found-close'=>['operations'], 'maintenance-cancellation-review'=>['operations'], 'operational-incident-open'=>['operations'], 'operational-incident-progress'=>['operations'], 'operational-incident-resolve'=>['operations'], 'room-hold-open'=>['operations'], 'room-hold-release'=>['operations'], 'alert-ack'=>['operations'], 'operational-settings-save'=>['operations','config'], 'night-audit-mode-save'=>['operations','config'], 'schema-doctor'=>['operations'],
            'room-access-save'=>['operations'], 'key-issue'=>['operations'],
            'key-return'=>['operations'], 'room-key-recovered'=>['operations'], 'smart-lock-job-retry'=>['operations'],
            'smart-lock-job-manual-complete'=>['operations'], 'late-checkout-record'=>['operations'],
            'night-audit-start'=>['operations'], 'night-audit-refresh'=>['operations'], 'night-audit-check-room'=>['operations'],
            'night-audit-resolve'=>['operations'], 'night-audit-finalize'=>['operations'],
            'daily-summary'=>['operations','report'],
            'backup-record'=>['operations','report'], 'backup-restore-tested'=>['operations','report'],
        ];
        $requiredCommandTabs = $operationCommandTabs[$command] ?? [];
        if ($requiredCommandTabs) {
            $commandTabAllowed = false;
            foreach ($requiredCommandTabs as $requiredCommandTab) {
                if (hasDesktopTabAccess($loggedInStaff,$requiredCommandTab,$allOperationalRoles)) {
                    $commandTabAllowed = true;
                    break;
                }
            }
            if (!$commandTabAllowed) {
                http_response_code(403);
                echo json_encode(['success'=>false,'error'=>'Forbidden: hak akses menu untuk perintah operasional ini dinonaktifkan.','requiredDesktopTabs'=>$requiredCommandTabs]);
                break;
            }
        }

        try {
            if ($command === 'telegram-binding-code') {
                $requestedStaffId = trim((string)($input['staffId'] ?? ($loggedInStaff['id'] ?? '')));
                if ($requestedStaffId !== (string)($loggedInStaff['id'] ?? '')) requireRoles($loggedInStaff, ['admin','manager']);
                $pdo->beginTransaction();
                $targetStmt=$pdo->prepare("SELECT id,name,role,status,telegram_chat_id FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                $targetStmt->execute([$requestedStaffId]);$targetStaff=$targetStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$targetStaff||(string)($targetStaff['status']??'')!=='active')throw new RuntimeException('Akun staf aktif tidak ditemukan.');
                if(strtolower((string)($loggedInStaff['role']??''))==='manager'&&$requestedStaffId!==(string)($loggedInStaff['id']??'')&&in_array(strtolower((string)($targetStaff['role']??'')),['admin','manager'],true))throw new RuntimeException('Manager tidak dapat mengelola binding Admin/Manager lain.');
                $binding = createTelegramBindingCode($pdo,$requestedStaffId,$loggedInStaff['id'] ?? null);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat kode binding Telegram','telegram_binding_code',(string)$binding['id'],null,['staffId'=>$requestedStaffId,'expiresAt'=>$binding['expiresAt']],'web');
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'binding'=>$binding,'message'=>'Kode binding berlaku 10 menit dan hanya dapat digunakan satu kali.']);
                break;
            }
            if ($command === 'telegram-unbind') {
                $requestedStaffId = trim((string)($input['staffId'] ?? ($loggedInStaff['id'] ?? '')));
                if ($requestedStaffId !== (string)($loggedInStaff['id'] ?? '')) requireRoles($loggedInStaff,['admin','manager']);
                $pdo->beginTransaction();
                $targetStmt=$pdo->prepare("SELECT id,name,role,status,telegram_chat_id,two_factor_active FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                $targetStmt->execute([$requestedStaffId]);$beforeStaff=$targetStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$beforeStaff)throw new RuntimeException('Akun staf tidak ditemukan.');
                if(strtolower((string)($loggedInStaff['role']??''))==='manager'&&$requestedStaffId!==(string)($loggedInStaff['id']??'')&&in_array(strtolower((string)($beforeStaff['role']??'')),['admin','manager'],true))throw new RuntimeException('Manager tidak dapat melepas binding Admin/Manager lain.');
                $bindingStmt=$pdo->prepare("SELECT telegram_user_id,telegram_chat_id,staff_id,status,verified_at FROM telegram_bindings WHERE staff_id=? ORDER BY telegram_user_id FOR UPDATE");
                $bindingStmt->execute([$requestedStaffId]);$beforeBindings=$bindingStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                unbindTelegramIdentity($pdo,$requestedStaffId);
                $afterStmt=$pdo->prepare("SELECT id,name,role,status,telegram_chat_id,two_factor_active FROM staff WHERE id=? LIMIT 1");$afterStmt->execute([$requestedStaffId]);$afterStaff=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                $bindingHashes=array_map(static fn($row)=>['userHash'=>hash('sha256',(string)($row['telegram_user_id']??'')),'chatHash'=>hash('sha256',(string)($row['telegram_chat_id']??'')),'status'=>$row['status']??null,'verifiedAt'=>$row['verified_at']??null],$beforeBindings);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Melepas binding Telegram','staff',$requestedStaffId,['staff'=>tamasyaAuditAttemptSnapshot($beforeStaff),'bindings'=>$bindingHashes],['staff'=>tamasyaAuditAttemptSnapshot($afterStaff),'bindings'=>[]],'web');
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Binding Telegram berhasil dilepas.']);
                break;
            }
            if ($command === 'device-heartbeat') {
                $headerDeviceId=currentDeviceId();
                $requestedDeviceId=trim((string)($input['deviceId']??''));
                if($headerDeviceId===''||$headerDeviceId==='unknown-device')throw new InvalidArgumentException('X-Device-ID wajib tersedia untuk heartbeat.');
                if($requestedDeviceId!==''&&!hash_equals($headerDeviceId,$requestedDeviceId))throw new InvalidArgumentException('Device ID body tidak cocok dengan perangkat request.');
                $deviceId=$headerDeviceId;
                $deviceName=substr(trim((string)($input['deviceName']??'Browser Device')),0,190)?:'Browser Device';
                $pendingCount=max(0,(int)($input['pendingCount']??0));
                $conflictCount=max(0,(int)($input['conflictCount']??0));
                $pdo->beginTransaction();
                $beforeStmt=$pdo->prepare("SELECT device_id,staff_id,device_name,app_version,pending_count,conflict_count,status,last_sync_at,last_seen FROM sync_devices WHERE device_id=? LIMIT 1 FOR UPDATE");
                $beforeStmt->execute([$deviceId]);$beforeDevice=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $stmt=$pdo->prepare("INSERT INTO sync_devices (device_id,staff_id,device_name,app_version,pending_count,conflict_count,status,last_sync_at,last_seen,created_at)
                    VALUES (?,?,?,?,?,?,'registered',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id),device_name=VALUES(device_name),app_version=VALUES(app_version),pending_count=VALUES(pending_count),conflict_count=VALUES(conflict_count),last_sync_at=IF(VALUES(pending_count)=0,CURRENT_TIMESTAMP,last_sync_at),last_seen=CURRENT_TIMESTAMP,status=IF(status='disabled','disabled','registered')");
                $stmt->execute([$deviceId,$loggedInStaff['id']??null,$deviceName,substr((string)($input['appVersion']??currentAppVersion()),0,50),$pendingCount,$conflictCount]);
                $afterStmt=$pdo->prepare("SELECT device_id,staff_id,device_name,app_version,pending_count,conflict_count,status,last_sync_at,last_seen FROM sync_devices WHERE device_id=? LIMIT 1");
                $afterStmt->execute([$deviceId]);$afterDevice=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $ownerChanged=$beforeDevice&&(string)($beforeDevice['staff_id']??'')!==(string)($loggedInStaff['id']??'');
                $conflictChanged=(int)($beforeDevice['conflict_count']??0)!==$conflictCount;
                if($ownerChanged||$conflictChanged){
                    $snapshot=static fn($row)=>$row?[
                        'deviceHash'=>hash('sha256',(string)($row['device_id']??'')),
                        'staffId'=>$row['staff_id']??null,'deviceName'=>$row['device_name']??null,
                        'appVersion'=>$row['app_version']??null,'pendingCount'=>(int)($row['pending_count']??0),
                        'conflictCount'=>(int)($row['conflict_count']??0),'status'=>$row['status']??null,
                    ]:null;
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$ownerChanged?'Mengalihkan perangkat ke akun aktif':'Memperbarui konflik perangkat','sync_device',hash('sha256',$deviceId),$snapshot($beforeDevice),$snapshot($afterDevice),'web');
                }
                if($conflictCount>0){
                    $alertId='alert_sync_'.hash('sha256',$deviceId);
                    $deviceLabel=$deviceName.' #'.substr(hash('sha256',$deviceId),0,8);
                    $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'warning','sync','Konflik sinkronisasi perangkat',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                        ->execute([$alertId,'Perangkat '.$deviceLabel.' memiliki '.$conflictCount.' konflik yang perlu diperiksa.']);
                }
                $expectedRelease=defined('TAMASYA_RELEASE')?(string)TAMASYA_RELEASE:'';
                $reportedRelease=trim((string)($afterDevice['app_version']??''));
                $versionIssueId='issue_device_version_'.substr(hash('sha256',$deviceId),0,32);
                if($reportedRelease!=='' && !hash_equals($expectedRelease,$reportedRelease)){
                    $details='expected='.$expectedRelease.'; appVersion='.$reportedRelease.'; status='.(string)($afterDevice['status']??'registered').'; pending='.$pendingCount.'; conflicts='.$conflictCount;
                    $pdo->prepare("INSERT INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status,detected_at,resolved_at)
                        VALUES (?,'stale_device_app_version','sync_device',?,'warning',?,'open',CURRENT_TIMESTAMP,NULL)
                        ON DUPLICATE KEY UPDATE severity='warning',details=VALUES(details),status='open',detected_at=CURRENT_TIMESTAMP,resolved_at=NULL")
                        ->execute([$versionIssueId,$deviceId,$details]);
                }else{
                    $pdo->prepare("UPDATE data_integrity_issues SET status='resolved',resolved_at=CURRENT_TIMESTAMP,
                        details=CONCAT(details,'; resolvedVersion=',?) WHERE id=? AND issue_type='stale_device_app_version' AND status<>'resolved'")
                        ->execute([$reportedRelease?:$expectedRelease,$versionIssueId]);
                }
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'expectedAppVersion'=>$expectedRelease,'reportedAppVersion'=>$reportedRelease,'appVersionCurrent'=>$reportedRelease!==''&&hash_equals($expectedRelease,$reportedRelease)]);
                break;
            }

            if ($command === 'logout-current') {
                $sessionId=trim((string)($loggedInStaff['session_id']??''));
                if($sessionId!==''){
                    $pdo->beginTransaction();
                    $sessionStmt=$pdo->prepare("SELECT id,staff_id,device_id,device_name,expires_at,revoked_at FROM user_sessions WHERE id=? AND staff_id=? LIMIT 1 FOR UPDATE");
                    $sessionStmt->execute([$sessionId,(string)$loggedInStaff['id']]);$beforeSession=$sessionStmt->fetch(PDO::FETCH_ASSOC)?:null;
                    if($beforeSession&&!$beforeSession['revoked_at']){
                        $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND staff_id=?")->execute([$sessionId,(string)$loggedInStaff['id']]);
                        $afterSession=$beforeSession;$afterSession['revoked_at']=date('Y-m-d H:i:s');
                        foreach([$beforeSession,$afterSession] as &$sessionSnapshot){
                            $sessionSnapshot['idHash']=hash('sha256',(string)$sessionSnapshot['id']);unset($sessionSnapshot['id']);
                            $sessionSnapshot['deviceHash']=hash('sha256',(string)($sessionSnapshot['device_id']??''));unset($sessionSnapshot['device_id']);
                        }unset($sessionSnapshot);
                        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Logout sesi aktif','user_session',hash('sha256',$sessionId),$beforeSession,$afterSession,'web');
                    }
                    tamasyaFinancialCommit($pdo);
                }
                echo json_encode(['success'=>true]);
                break;
            }

            if ($command === 'derived-state-refresh') {
                requireRoles($loggedInStaff,['admin','manager']);
                $pdo->beginTransaction();
                $beforeDerived=operationsDerivedStateSnapshot($pdo);
                refreshOperationalAlerts($pdo,true);
                refreshRoomSecurityState($pdo,true);
                // Reconcile room status cache from canonical blockers. This is not a manual
                // 'make available' bypass: each target is derived from domain state.
                $roomProjectionRepair=tamasyaReconcileAllRoomOperationalProjections($pdo,$loggedInStaff,'derived-state-refresh');
                syncJournalProjections($pdo,true);
                $afterDerived=operationsDerivedStateSnapshot($pdo);
                $afterDerived['roomProjectionRepair']=$roomProjectionRepair;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyegarkan proyeksi Pusat Operasional','operations_projection','system',$beforeDerived,$afterDerived,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'changed'=>$beforeDerived!==$afterDerived,'summary'=>$afterDerived]);
                break;
            }

            if ($command === 'shift-open') {
                requireCapability($loggedInStaff,'receive_payment_access',tamasyaShiftOperatorRoles());
                $id = generateServerId('shift');
                $openingInput=$input['openingCash']??0;
                if(!is_numeric($openingInput)||!is_finite((float)$openingInput)||(float)$openingInput<0)throw new InvalidArgumentException('Kas awal wajib angka valid dan tidak negatif.');
                $opening=round((float)$openingInput,2);
                $companionStaffId=trim((string)($input['companionStaffId']??''));
                // Slot default mengikuti batas jam shift yang dapat diatur properti
                // (R14.1); nilai eksplisit dari klien divalidasi seperti biasa.
                $requestedSlot=trim((string)($input['shiftTime']??''));
                $shiftTime=$requestedSlot!==''?$requestedSlot:tamasyaDefaultShiftSlotForNow($pdo);
                if(!in_array($shiftTime,['pagi','siang','malam','all'],true)) throw new InvalidArgumentException('Jadwal shift tidak valid.');
                $pdo->beginTransaction();
                $primaryLock=$pdo->prepare("SELECT id FROM staff WHERE id=? LIMIT 1 FOR UPDATE");$primaryLock->execute([(string)$loggedInStaff['id']]);
                if(!$primaryLock->fetchColumn())throw new RuntimeException('Akun petugas utama tidak ditemukan.');
                if(getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'],true))throw new RuntimeException('Akun ini sudah terikat pada shift terbuka.');
                $companion=validateOptionalShiftCompanion($pdo,(string)$loggedInStaff['id'],$companionStaffId,true);
                $pdo->prepare("INSERT INTO shift_sessions (id,staff_id,staff_name,companion_staff_id,companion_staff_name,shift_date,shift_time,opening_cash,expected_cash,status,notes,opened_at) VALUES (?,?,?,?,?,CURDATE(),?,?,?,'open',?,CURRENT_TIMESTAMP)")
                    ->execute([$id,$loggedInStaff['id'],$loggedInStaff['name'],$companion['id']??null,$companion['name']??null,$shiftTime,$opening,$opening,trim((string)($input['notes'] ?? ''))]);
                $afterShiftStmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? LIMIT 1");
                $afterShiftStmt->execute([$id]);
                $afterShift=$afterShiftStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>'open','opening_cash'=>$opening,'shift_time'=>$shiftTime];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuka shift kas fleksibel','shift_session',$id,null,$afterShift);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'shift-close') {
                requireCapability($loggedInStaff,'receive_payment_access',tamasyaShiftOperatorRoles());
                $id = trim((string)($input['shiftId'] ?? ''));
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]); $shift = $stmt->fetch();
                if (!$shift) throw new RuntimeException('Shift terbuka tidak ditemukan.');
                $participantIds=getShiftParticipantIds($shift);
                if (($loggedInStaff['role'] ?? '') === 'receptionist' && !in_array((string)$loggedInStaff['id'],$participantIds,true)) throw new RuntimeException('Hanya petugas utama atau pendamping yang boleh menutup shift bersama ini.');
                $operationalSettings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch()?:[];
                assertShiftNightAuditReady($pdo,$shift,$operationalSettings);
                // Hanya transaksi live non-exempt yang boleh diklaim. Backfill historis
                // tetap bebas shift walaupun dicatat saat sesi kas sedang terbuka.
                claimUnassignedLiveTransactionsForShift($pdo,$shift);
                $financials=getShiftFinancials($pdo,$loggedInStaff,(string)$shift['shift_time'],(string)$shift['shift_date'],trim((string)($shift['companion_staff_id']??''))?:'none',true,$id);
                $income=$financials['cashInc'];$expense=$financials['cashExp'];$digital=$financials['digitalInc'];$txCount=$financials['txCount'];
                $expected=$financials['expectedCash'];
                if(!array_key_exists('actualCash',$input) || $input['actualCash']==='' || $input['actualCash']===null || !is_numeric($input['actualCash']))throw new InvalidArgumentException('Kas fisik aktual wajib diisi dengan angka yang valid.');
                $actual=round((float)$input['actualCash'],2);if(!is_finite($actual)||$actual<0)throw new InvalidArgumentException('Kas fisik aktual tidak boleh negatif atau tidak valid.');
                $variance=round($actual-$expected,2);
                $closeNotes=trim((string)($input['notes']??''));
                $policy=tamasyaShiftVariancePolicy($loggedInStaff,$variance,(float)($operationalSettings['cash_variance_tolerance']??0),$closeNotes,trim((string)($input['overrideReason']??'')));
                $overrideReason=$policy['overrideReason'];
                if($closeNotes==='')$closeNotes=$policy['explanation']?:'Serah terima tanpa catatan tambahan.';
                $reviewId=$policy['needsReview']?tamasyaCreateShiftVarianceReview($pdo,$loggedInStaff,$shift,$actual,$expected,$closeNotes,'web'):null;
                $closeStmt=$pdo->prepare("UPDATE shift_sessions SET cash_income=?,cash_expense=?,expected_cash=?,actual_cash=?,variance=?,close_override_reason=?,status='closed',notes=CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' OR ?='' THEN '' ELSE '\n' END, ?),closed_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'");
                $closeStmt->execute([$income,$expense,$expected,$actual,$variance,$overrideReason?:null,$closeNotes,$closeNotes,$id]);
                if($closeStmt->rowCount()!==1) throw new RuntimeException('Sesi shift berubah sebelum penutupan disimpan.');
                $reportId=upsertShiftReportForSession($pdo,$shift,$expected,$actual,$variance,$digital,$txCount,$closeNotes,trim((string)($shift['companion_staff_id']??''))?:null,getShiftDisplayName($shift));
                tamasyaLockTransactionsForShift($pdo,$id,$loggedInStaff);
                $closedShiftStmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? LIMIT 1");
                $closedShiftStmt->execute([$id]);
                $closedShift=$closedShiftStmt->fetch(PDO::FETCH_ASSOC)?:[];
                $closedShift['reportId']=$reportId;
                $closedShift['digitalRevenue']=$digital;
                $closedShift['transactionsCount']=$txCount;
                $closedShift['reviewId']=$reviewId;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menutup shift kas','shift_session',$id,$shift,$closedShift);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'shift-cash-revise') {
                requireRoles($loggedInStaff,['admin','manager']);
                $id=trim((string)($input['shiftId']??''));
                $reason=trim((string)($input['reason']??''));
                if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan revisi kas fisik minimal 5 karakter.');
                if(!isset($input['actualCash'])||!is_numeric($input['actualCash']))throw new InvalidArgumentException('Kas fisik hasil revisi wajib berupa angka.');
                $actual=round((float)$input['actualCash'],2);
                if(!is_finite($actual)||$actual<0)throw new InvalidArgumentException('Kas fisik tidak boleh negatif/tidak valid.');
                $pdo->beginTransaction();
                $q=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='closed' LIMIT 1 FOR UPDATE");$q->execute([$id]);$before=$q->fetch(PDO::FETCH_ASSOC);
                if(!$before)throw new RuntimeException('Hanya kas fisik shift yang sudah ditutup dapat direvisi.');
                if(!isset($input['previousActualCash'])||!is_numeric($input['previousActualCash'])||abs(round((float)$input['previousActualCash']-(float)$before['actual_cash'],2))>0.001)
                    throw new DomainException('Kas shift sudah berubah. Muat ulang sebelum merevisi.');
                $q=$pdo->prepare("SELECT * FROM shift_reports WHERE shiftSessionId=? LIMIT 1 FOR UPDATE");$q->execute([$id]);$report=$q->fetch(PDO::FETCH_ASSOC);
                if(!$report)throw new RuntimeException('Laporan penutupan shift belum tersedia; revisi dibatalkan.');
                $variance=round($actual-(float)$before['expected_cash'],2);
                $line='Revisi kas fisik oleh '.($loggedInStaff['name']??'Admin').': '.$reason;
                $notes=trim((string)($before['notes']??''))."\n".$line;
                $pdo->prepare("UPDATE shift_sessions SET actual_cash=?,variance=?,close_override_reason=?,notes=? WHERE id=? AND status='closed'")
                    ->execute([$actual,$variance,$reason,$notes,$id]);
                upsertShiftReportForSession($pdo,$before,(float)$before['expected_cash'],$actual,$variance,(float)$report['digitalRevenue'],(int)$report['transactionsCount'],$notes,$before['companion_staff_id']??null,getShiftDisplayName($before));
                $after=array_replace($before,['actual_cash'=>$actual,'variance'=>$variance,'close_override_reason'=>$reason,'notes'=>$notes]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Merevisi kas fisik shift tertutup','shift_session',$id,$before,$after,'web');
                $pending=$pdo->prepare("SELECT * FROM approval_requests WHERE request_type='shift_cash_variance' AND entity_type='shift_session' AND entity_id=? AND status='pending' FOR UPDATE");$pending->execute([$id]);
                $selfRequestedReviews=[];
                foreach($pending->fetchAll(PDO::FETCH_ASSOC)?:[] as $review){
                    // Separation of duties: the canonical approval-decide path already
                    // refuses self-approval at :381. This loop approved every pending
                    // variance review for the shift regardless of who requested it, so a
                    // manager who closed their own drawer with a variance could revise
                    // actual_cash and then approve the resulting review themselves.
                    // Leave those reviews pending for an independent approver.
                    if((string)($review['requester_id']??'')===(string)($loggedInStaff['id']??'')){
                        $selfRequestedReviews[]=(string)$review['id'];
                        continue;
                    }
                    $decisionNote='Kas fisik diperiksa dan direvisi: '.$reason;
                    $pdo->prepare("UPDATE approval_requests SET status='approved',approver_id=?,approver_name=?,decision_notes=?,decided_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")
                        ->execute([$loggedInStaff['id'],$loggedInStaff['name'],$decisionNote,$review['id']]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memeriksa selisih melalui revisi kas fisik','approval_request',(string)$review['id'],$review,array_replace($review,['status'=>'approved','decision_notes'=>$decisionNote,'revisedActualCash'=>$actual,'revisedVariance'=>$variance]),'web');
                }
                if($selfRequestedReviews){
                    error_log('[Shift Cash Revise] Permintaan selisih kas milik pemohon sendiri tidak disetujui otomatis dan tetap menunggu approver lain: '.implode(', ',$selfRequestedReviews));
                }
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'approval-create') {
                $id=generateServerId('approval');
                $requestType=substr(trim((string)($input['requestType']??'other'))?:'other',0,80);
                $entityType=substr(trim((string)($input['entityType']??'system'))?:'system',0,80);
                $entityId=trim((string)($input['entityId']??''))?:null;
                $amount=max(0,(float)($input['amount']??0));
                $reason=trim((string)($input['reason']??''));
                if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan permintaan persetujuan minimal 5 karakter.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP)")
                    ->execute([$id,$requestType,$entityType,$entityId,$amount,$reason,json_encode(sanitizeAuditValue($input),JSON_UNESCAPED_UNICODE),$loggedInStaff['id'],$loggedInStaff['name']]);
                $afterStmt=$pdo->prepare("SELECT * FROM approval_requests WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>'pending'];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat permintaan persetujuan','approval_request',$id,null,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'approval-decide') {
                requireCapability($loggedInStaff, 'approve_sensitive_actions', ['admin','manager']);
                $id=trim((string)($input['id']??''));
                $decision=trim((string)($input['decision']??''));
                if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Keputusan persetujuan harus approved atau rejected.');
                $pdo->beginTransaction();
                $request=$pdo->prepare("SELECT * FROM approval_requests WHERE id=? AND status='pending' LIMIT 1 FOR UPDATE");$request->execute([$id]);$requestRow=$request->fetch();
                if (!$requestRow) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false,'error'=>'Permintaan tidak ditemukan atau sudah diputuskan.']); break; }
                if (($requestRow['requester_id']??'')===($loggedInStaff['id']??'')) { $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false,'error'=>'Pemohon tidak boleh menyetujui permintaannya sendiri.']); break; }
                if(($requestRow['request_type']??'')==='shift_cash_variance' && tamasyaStringLength(trim((string)($input['notes']??'')))<5)
                    throw new InvalidArgumentException('Hasil pemeriksaan selisih kas wajib berketerangan minimal 5 karakter.');
                $pdo->prepare("UPDATE approval_requests SET status=?,approver_id=?,approver_name=?,decision_notes=?,decided_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")
                    ->execute([$decision,$loggedInStaff['id'],$loggedInStaff['name'],trim((string)($input['notes']??'')),$id]);
                if($decision==='approved' && ($requestRow['request_type']??'')==='maintenance_expense'){
                    $payload=json_decode((string)($requestRow['payload']??'{}'),true)?:[];
                    if(($requestRow['entity_type']??'')==='maintenance_ticket'){
                        $ticketStmt=$pdo->prepare("SELECT * FROM maintenance_tickets WHERE id=? LIMIT 1 FOR UPDATE");$ticketStmt->execute([$requestRow['entity_id']]);$ticket=$ticketStmt->fetch(PDO::FETCH_ASSOC);
                        if(!$ticket)throw new RuntimeException('Tiket maintenance yang disetujui sudah tidak ditemukan.');
                        $approvedAmount=round((float)($payload['actualCost']??$requestRow['amount']),2);
                        if((string)$ticket['status']!=='completed')throw new RuntimeException('Approval biaya maintenance tidak dapat dipakai: tiket tidak lagi berstatus completed.');
                        if(!empty($ticket['transaction_id']))throw new RuntimeException('Biaya maintenance sudah mempunyai transaksi; approval lama tidak boleh membuat posting kedua.');
                        if((string)($ticket['approval_request_id']??'')!==$id)throw new RuntimeException('Approval biaya maintenance sudah tidak menjadi approval aktif tiket ini.');
                        if(abs(round((float)$ticket['actual_cost'],2)-$approvedAmount)>0.009 || abs(round((float)$requestRow['amount'],2)-$approvedAmount)>0.009)throw new RuntimeException('Nominal tiket berubah setelah approval dibuat; buat permintaan persetujuan baru.');
                        $txId=createMaintenanceExpense($pdo,$ticket,$approvedAmount,$loggedInStaff,(string)($payload['paymentMethod']??'payable'),(string)($payload['bankAccountId']??''),$payload['shiftSessionId']??null);
                        $pdo->prepare("UPDATE maintenance_tickets SET transaction_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$txId,$ticket['id']]);
                    } elseif(($requestRow['entity_type']??'')==='inventory_maintenance') {
                        $maintStmt=$pdo->prepare("SELECT id,cost FROM inventory_maintenance WHERE id=? LIMIT 1 FOR UPDATE");$maintStmt->execute([$requestRow['entity_id']]);$maint=$maintStmt->fetch(PDO::FETCH_ASSOC);
                        if(!$maint)throw new RuntimeException('Log maintenance inventaris yang disetujui sudah tidak ditemukan.');
                        $approvedAmount=round((float)($payload['actualCost']??$requestRow['amount']),2);
                        if(abs(round((float)$maint['cost'],2)-$approvedAmount)>0.009 || abs(round((float)$requestRow['amount'],2)-$approvedAmount)>0.009)throw new RuntimeException('Nominal maintenance inventaris berubah setelah approval dibuat; buat permintaan persetujuan baru.');
                        $existingStmt=$pdo->prepare("SELECT id FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId=? LIMIT 1 FOR UPDATE");$existingStmt->execute([$maint['id']]);
                        if($existingStmt->fetchColumn())throw new RuntimeException('Maintenance inventaris sudah mempunyai transaksi keuangan; approval lama tidak boleh membuat posting kedua.');
                        createInventoryMaintenanceExpense($pdo,(string)$maint['id'],$approvedAmount,$loggedInStaff,$payload['paymentMethod']??'',(string)($payload['bankAccountId']??''),$payload['shiftSessionId']??null);
                    }
                }
                $afterStmt=$pdo->prepare("SELECT * FROM approval_requests WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>$decision];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$decision==='approved'?'Menyetujui permintaan':'Menolak permintaan','approval_request',$id,$requestRow,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'approval-policy-save') {
                requireCapability($loggedInStaff, 'approve_sensitive_actions', ['admin','manager']);
                $required=!empty($input['required'])?1:0; $threshold=max(0,(float)($input['threshold']??0));
                $pdo->beginTransaction();
                $beforeStmt=$pdo->query("SELECT approval_required_sensitive,approval_threshold FROM config WHERE id='system_default' FOR UPDATE");$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $pdo->prepare("UPDATE config SET approval_required_sensitive=?,approval_threshold=? WHERE id='system_default'")->execute([$required,$threshold]);
                $after=['approval_required_sensitive'=>$required,'approval_threshold'=>$threshold];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah kebijakan persetujuan','config','system_default',$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'session-revoke') {
                requireCapability($loggedInStaff, 'manage_sessions', ['admin','manager']);
                $sessionId=trim((string)($input['id']??''));if($sessionId==='')throw new InvalidArgumentException('ID sesi wajib diisi.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT us.*,s.role AS target_role,s.name AS target_name FROM user_sessions us JOIN staff s ON s.id=us.staff_id WHERE us.id=? LIMIT 1 FOR UPDATE");$stmt->execute([$sessionId]);$before=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$before)throw new RuntimeException('Sesi tidak ditemukan.');
                if(($loggedInStaff['role']??'')==='manager' && in_array(strtolower((string)($before['target_role']??'')),['admin','manager'],true) && (string)$before['staff_id']!==(string)$loggedInStaff['id'])throw new RuntimeException('Manager tidak boleh mencabut sesi Admin atau Manager lain.');
                $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL")->execute([$sessionId]);
                $afterStmt=$pdo->prepare("SELECT us.*,s.role AS target_role,s.name AS target_name FROM user_sessions us JOIN staff s ON s.id=us.staff_id WHERE us.id=? LIMIT 1");$afterStmt->execute([$sessionId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$before;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencabut sesi pengguna','user_session',$sessionId,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'device-disable') {
                requireCapability($loggedInStaff, 'manage_sessions', ['admin','manager']);
                $deviceId=trim((string)($input['deviceId']??''));if($deviceId==='')throw new InvalidArgumentException('ID perangkat wajib diisi.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT sd.*,s.role AS target_role,s.name AS target_name FROM sync_devices sd LEFT JOIN staff s ON s.id=sd.staff_id WHERE sd.device_id=? LIMIT 1 FOR UPDATE");$stmt->execute([$deviceId]);$before=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$before)throw new RuntimeException('Perangkat tidak ditemukan.');
                if(($loggedInStaff['role']??'')==='manager' && in_array(strtolower((string)($before['target_role']??'')),['admin','manager'],true) && (string)($before['staff_id']??'')!==(string)$loggedInStaff['id'])throw new RuntimeException('Manager tidak boleh menonaktifkan perangkat Admin atau Manager lain.');
                $pdo->prepare("UPDATE sync_devices SET status='disabled' WHERE device_id=?")->execute([$deviceId]);
                $revoked=$pdo->prepare("SELECT id FROM user_sessions WHERE device_id=? AND revoked_at IS NULL FOR UPDATE");$revoked->execute([$deviceId]);$revokedIds=$revoked->fetchAll(PDO::FETCH_COLUMN)?:[];
                $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE device_id=? AND revoked_at IS NULL")->execute([$deviceId]);
                $afterStmt=$pdo->prepare("SELECT sd.*,s.role AS target_role,s.name AS target_name FROM sync_devices sd LEFT JOIN staff s ON s.id=sd.staff_id WHERE sd.device_id=? LIMIT 1");$afterStmt->execute([$deviceId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$before;$after['revokedSessionIds']=$revokedIds;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menonaktifkan perangkat','sync_device',$deviceId,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'reconciliation-import') {
                requireCapability($loggedInStaff, 'reconcile_payments', ['admin','manager','finance']);
                $items=is_array($input['items']??null)?array_slice($input['items'],0,500):[];
                if (!$items) throw new InvalidArgumentException('Tidak ada baris rekonsiliasi yang valid.');
                $pdo->beginTransaction();
                // Bank reconciliation must compare the actual liquid bank/QRIS leg.
                // A canonical split row stores the gross amount at transaction level,
                // while only splitTransferAmount appears on a bank statement. Cash and
                // pseudo accounts (OTA A/R, guest folio, inventory/AP) are never auto-matched.
                $find=$pdo->prepare("SELECT id,type,amount,`date`,bankAccountId,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId,reconciliationStatus,transactionKind FROM transactions WHERE `date`=? AND COALESCE(reconciliationStatus,'')<>'matched' ORDER BY id LIMIT 250");
                $insert=$pdo->prepare("INSERT INTO reconciliation_items (id,source,reference,transaction_id,amount,transaction_date,status,notes,created_by,matched_by,created_at,matched_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?)");
                $createdIds=[];$matchedTransactionIds=[];
                foreach($items as $row){
                    $date=trim((string)($row['transactionDate']??$row['date']??'')); $amount=abs((float)($row['amount']??0));
                    if(!validIsoDate($date)||$amount<=0)continue;
                    $find->execute([$date]);
                    $candidates=array_values(array_filter($find->fetchAll(PDO::FETCH_ASSOC)?:[],static fn($candidate)=>tamasyaTransactionMatchesBankReconciliation((array)$candidate,$amount,$date)));
                    $txId=count($candidates)===1?(string)$candidates[0]['id']:null; $status=$txId?'matched':'unmatched';
                    $id=generateServerId('rec');
                    $insert->execute([$id,substr((string)($row['source']??'bank'),0,80),trim((string)($row['reference']??''))?:null,$txId,$amount,$date,$status,trim((string)($row['notes']??'Import CSV')),$loggedInStaff['id'],$txId?$loggedInStaff['id']:null,$txId?date('Y-m-d H:i:s'):null]);
                    $createdIds[]=$id;
                    if($txId){tamasyaRecalculateTransactionReconciliation($pdo,$txId,$loggedInStaff);$matchedTransactionIds[]=$txId;}
                }
                if(!$createdIds)throw new InvalidArgumentException('Tidak ada baris rekonsiliasi yang dapat diimpor.');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengimpor rekonsiliasi bank','reconciliation_import',hash('sha256',implode('|',$createdIds)),null,['createdIds'=>$createdIds,'matchedTransactionIds'=>$matchedTransactionIds,'count'=>count($createdIds)],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'reconciliation-save') {
                requireCapability($loggedInStaff, 'reconcile_payments', ['admin','manager','finance']);
                $id=trim((string)($input['id']??''))?:generateServerId('rec');
                $date=trim((string)($input['transactionDate']??date('Y-m-d'))); if(!validIsoDate($date)) throw new InvalidArgumentException('Tanggal rekonsiliasi tidak valid.');
                $amount=abs((float)($input['amount']??0));if($amount<=0)throw new InvalidArgumentException('Nominal rekonsiliasi harus lebih dari nol.');
                $pdo->beginTransaction();
                $beforeStmt=$pdo->prepare("SELECT * FROM reconciliation_items WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $transactionId=trim((string)($input['transactionId']??''))?:null;
                if($transactionId){
                    $txCheck=$pdo->prepare("SELECT id,amount,`date`,bankAccountId,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
                    $txCheck->execute([$transactionId]);$txRow=$txCheck->fetch(PDO::FETCH_ASSOC)?:null;
                    if(!$txRow)throw new InvalidArgumentException('Transaksi rekonsiliasi tidak ditemukan.');
                    $bankLeg=tamasyaTransactionBankReconciliationLeg($txRow);
                    if(!$bankLeg)throw new InvalidArgumentException('Transaksi yang dipilih tidak mempunyai kaki Bank/QRIS. Rekonsiliasi bank tidak boleh ditautkan ke Kas atau akun non-likuid.');
                    if($amount>(float)$bankLeg['amount']+0.01)throw new InvalidArgumentException('Nominal item rekonsiliasi melebihi kaki Bank/QRIS transaksi.');
                }
                $oldTransactionId=trim((string)($before['transaction_id']??''));
                $pdo->prepare("INSERT INTO reconciliation_items (id,source,reference,transaction_id,amount,transaction_date,status,notes,created_by,created_at) VALUES (?,?,?,?,?,?,'unmatched',?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source=VALUES(source),reference=VALUES(reference),transaction_id=VALUES(transaction_id),amount=VALUES(amount),transaction_date=VALUES(transaction_date),status='unmatched',matched_by=NULL,matched_at=NULL,notes=VALUES(notes)")
                    ->execute([$id,substr((string)($input['source']??'other'),0,80),trim((string)($input['reference']??''))?:null,$transactionId,$amount,$date,trim((string)($input['notes']??'')),$loggedInStaff['id']]);
                if($oldTransactionId!=='' && $oldTransactionId!==$transactionId)tamasyaRecalculateTransactionReconciliation($pdo,$oldTransactionId,$loggedInStaff);
                if($transactionId)tamasyaRecalculateTransactionReconciliation($pdo,$transactionId,$loggedInStaff);
                $afterStmt=$pdo->prepare("SELECT * FROM reconciliation_items WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'amount'=>$amount,'transaction_date'=>$date];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Memperbarui item rekonsiliasi':'Membuat item rekonsiliasi','reconciliation_item',$id,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'reconciliation-status') {
                requireCapability($loggedInStaff, 'reconcile_payments', ['admin','manager','finance']);
                $status=in_array($input['status']??'', ['matched','partially_matched','disputed','unmatched'],true)?$input['status']:'unmatched';
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM reconciliation_items WHERE id=? FOR UPDATE");$stmt->execute([$input['id']??'']);$rec=$stmt->fetch();if(!$rec)throw new RuntimeException('Item rekonsiliasi tidak ditemukan.');
                $pdo->prepare("UPDATE reconciliation_items SET status=?,matched_by=?,matched_at=IF(? IN ('matched','partially_matched'),CURRENT_TIMESTAMP,NULL) WHERE id=?")->execute([$status,$loggedInStaff['id'],$status,$rec['id']]);
                if (!empty($rec['transaction_id'])) tamasyaRecalculateTransactionReconciliation($pdo,(string)$rec['transaction_id'],$loggedInStaff);
                $afterStmt=$pdo->prepare("SELECT * FROM reconciliation_items WHERE id=? LIMIT 1");$afterStmt->execute([$rec['id']]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$rec['id'],'status'=>$status];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah status rekonsiliasi','reconciliation_item',(string)$rec['id'],$rec,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'tax-rule-save') {
                requireCapability($loggedInStaff, 'manage_tax_rules', ['admin','manager']);
                $id=trim((string)($input['id']??''))?:generateServerId('tax');
                if(!preg_match('/^[A-Za-z0-9._:-]{3,190}$/',$id))throw new InvalidArgumentException('ID aturan pajak tidak valid.');
                $name=trim((string)($input['name']??''));
                if($name===''||strlen($name)>190)throw new InvalidArgumentException('Nama aturan pajak wajib diisi dan maksimal 190 karakter.');
                $sourcePattern=trim((string)($input['sourcePattern']??'*'))?:'*';
                if(strlen($sourcePattern)>100)throw new InvalidArgumentException('Pola sumber booking maksimal 100 karakter.');
                $allowedKinds=tamasyaTaxRuleKindCatalog();
                $transactionKind=strtolower(trim((string)($input['transactionKind']??'*')));
                if(!in_array($transactionKind,$allowedKinds,true))throw new InvalidArgumentException('Jenis transaksi aturan pajak tidak didukung.');
                $taxable=(int)($input['taxable']??1)?1:0;
                $rawRate=(float)($input['rate']??0);
                if(!is_finite($rawRate)||$rawRate<0||$rawRate>100)throw new InvalidArgumentException('Tarif pajak harus berada di antara 0 sampai 100 persen.');
                $rate=$taxable?$rawRate:0;
                $priority=max(-100000,min(100000,(int)($input['priority']??0)));
                $from=trim((string)($input['effectiveFrom']??''));$until=trim((string)($input['effectiveUntil']??''));
                if($from!==''&&!validIsoDate($from))throw new InvalidArgumentException('Tanggal mulai aturan pajak tidak valid.');
                if($until!==''&&!validIsoDate($until))throw new InvalidArgumentException('Tanggal akhir aturan pajak tidak valid.');
                if($from!==''&&$until!==''&&$until<$from)throw new InvalidArgumentException('Tanggal akhir aturan pajak tidak boleh sebelum tanggal mulai.');
                $isActive=array_key_exists('isActive',$input)?((int)$input['isActive']?1:0):1;

                $pdo->beginTransaction();
                $oldStmt=$pdo->prepare("SELECT * FROM tax_rules WHERE id=? LIMIT 1 FOR UPDATE");
                $oldStmt->execute([$id]);$oldRule=$oldStmt->fetch()?:null;
                if($isActive){
                    tamasyaAssertTaxRuleWindowUnique($pdo,[
                        'id'=>$id,'source_pattern'=>$sourcePattern,'transaction_kind'=>$transactionKind,'priority'=>$priority,
                        'effective_from'=>$from,'effective_until'=>$until
                    ]);
                }
                $pdo->prepare("INSERT INTO tax_rules (id,name,source_pattern,transaction_kind,taxable,rate,priority,effective_from,effective_until,is_active,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE name=VALUES(name),source_pattern=VALUES(source_pattern),transaction_kind=VALUES(transaction_kind),taxable=VALUES(taxable),rate=VALUES(rate),priority=VALUES(priority),effective_from=VALUES(effective_from),effective_until=VALUES(effective_until),is_active=VALUES(is_active),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$id,$name,$sourcePattern,$transactionKind,$taxable,$rate,$priority,$from?:null,$until?:null,$isActive,$loggedInStaff['id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM tax_rules WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$newRule=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'name'=>$name,'source_pattern'=>$sourcePattern,'transaction_kind'=>$transactionKind,'taxable'=>$taxable,'rate'=>$rate,'priority'=>$priority,'effective_from'=>$from?:null,'effective_until'=>$until?:null,'is_active'=>$isActive];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$oldRule?'Memperbarui aturan pajak':'Membuat aturan pajak','tax_rule',$id,$oldRule,$newRule,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'tax-rule-toggle') {
                requireCapability($loggedInStaff, 'manage_tax_rules', ['admin','manager']);
                $id=trim((string)($input['id']??''));
                if($id==='')throw new InvalidArgumentException('ID aturan pajak wajib diisi.');
                $isActive=(int)($input['isActive']??0)?1:0;
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM tax_rules WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$oldRule=$stmt->fetch();
                if(!$oldRule)throw new RuntimeException('Aturan pajak tidak ditemukan.');
                if($isActive){
                    tamasyaAssertTaxRuleWindowUnique($pdo,$oldRule);
                }
                $pdo->prepare("UPDATE tax_rules SET is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$isActive,$id]);
                $afterStmt=$pdo->prepare("SELECT * FROM tax_rules WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$afterRule=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$oldRule;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$isActive?'Mengaktifkan aturan pajak':'Menonaktifkan aturan pajak','tax_rule',$id,$oldRule,$afterRule,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'vacancy-report-create') {
                $payload=$input;
                $payload['operationId']=trim((string)($payload['operationId']??''))?:generateServerId('op_vacancy_web');
                $source=!empty($payload['offlineQueued'])?'web-offline':'web';
                $result=createOrRefreshRoomVacancyReport($pdo,$loggedInStaff,$payload,$source);
                $roomNumber=(string)($result['report']['room_number']??$payload['roomNumber']??'');
                if(empty($result['stale'])&&empty($result['duplicate'])){
                    broadcastTelegramNotification($pdo,"🚪 *KAMAR DILAPORKAN KOSONG*\n\nKamar: *{$roomNumber}*\nPelapor: *".currentStaffLabel($loggedInStaff)."*\nStatus: *MENUNGGU VERIFIKASI CHECKOUT*",false);
                }
            } elseif ($command === 'vacancy-report-detail-update') {
                $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID laporan kamar kosong wajib diisi.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM room_vacancy_reports WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$report=$stmt->fetch(PDO::FETCH_ASSOC);if(!$report)throw new RuntimeException('Laporan kamar kosong tidak ditemukan.');
                if(!in_array(strtolower((string)$report['status']),['pending','verified'],true))throw new RuntimeException('Detail laporan terminal tidak dapat diubah.');
                $role=strtolower((string)($loggedInStaff['role']??''));$isReviewer=in_array($role,['admin','manager','receptionist'],true);$isOwner=(string)($report['reported_by']??'')===(string)$loggedInStaff['id'];
                if(!$isReviewer&&!$isOwner)throw new RuntimeException('Hanya pelapor atau petugas verifikasi yang dapat memperbaiki detail laporan ini.');
                $belongingsStatus=strtolower((string)($report['belongings_status']??'unknown'));$damageStatus=strtolower((string)($report['damage_status']??'unknown'));
                $belongingsDetail=trim((string)($input['belongingsDetail']??($report['belongings_detail']??'')));
                $damageDetail=trim((string)($input['damageDetail']??($report['damage_detail']??'')));
                $notes=trim((string)($input['notes']??($report['notes']??'')));if(tamasyaStringLength($notes)>2000)throw new InvalidArgumentException('Catatan laporan maksimal 2.000 karakter.');
                if($belongingsStatus==='found')$belongingsDetail=tamasyaRequireLostFoundItemDetail($belongingsDetail,'Detail barang Kamar Kosong');else $belongingsDetail='';
                if($damageStatus==='found')$damageDetail=tamasyaRequireTechnicalDamageDetail($damageDetail,'Detail kerusakan Kamar Kosong');else $damageDetail='';
                $pdo->prepare("UPDATE room_vacancy_reports SET belongings_detail=?,damage_detail=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$belongingsDetail?:null,$damageDetail?:null,$notes?:null,$id]);
                $afterStmt=$pdo->prepare("SELECT * FROM room_vacancy_reports WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$report;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbaiki detail observasi kamar kosong','room_vacancy_report',$id,$report,$after,'web');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'vacancy-report-review') {
                requireRoles($loggedInStaff,['admin','manager','receptionist']);
                $id=trim((string)($input['id']??''));
                $decision=strtolower(trim((string)($input['decision']??'')));
                $notes=trim((string)($input['notes']??''));
                if($id===''||!in_array($decision,['verified','rejected'],true))throw new InvalidArgumentException('ID dan keputusan laporan tidak valid.');
                if($decision==='rejected'&&tamasyaStringLength($notes)<5)throw new InvalidArgumentException('Alasan penolakan minimal 5 karakter.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM room_vacancy_reports WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$report=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$report)throw new RuntimeException('Laporan kamar kosong tidak ditemukan.');
                if(!in_array((string)$report['status'],['pending','verified'],true))throw new RuntimeException('Laporan ini sudah selesai dan tidak dapat diubah.');
                if($decision==='verified'){
                    if(strtolower((string)($report['belongings_status']??''))==='found')tamasyaRequireLostFoundItemDetail((string)($report['belongings_detail']??''),'Detail barang sebelum verifikasi');
                    if(strtolower((string)($report['damage_status']??''))==='found')tamasyaRequireTechnicalDamageDetail((string)($report['damage_detail']??''),'Detail kerusakan sebelum verifikasi');
                }
                $pdo->prepare("UPDATE room_vacancy_reports SET status=?,reviewed_by=?,reviewed_by_name=?,reviewed_at=CURRENT_TIMESTAMP,review_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$decision,(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff),$notes?:null,$id]);
                $alertId='alert_vacancy_'.substr(hash('sha256',(string)$report['booking_id']),0,40);
                if($decision==='rejected'){
                    // Jangan menutup alert bersama bila laporan staf lain untuk booking
                    // yang sama masih menunggu/verifikasi.
                    $openStmt=$pdo->prepare("SELECT COUNT(*) FROM room_vacancy_reports WHERE booking_id=? AND id<>? AND status IN ('pending','verified')");
                    $openStmt->execute([(string)$report['booking_id'],$id]);
                    if((int)$openStmt->fetchColumn()===0){
                        $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
                            ->execute([(string)$loggedInStaff['id'],$alertId]);
                    }
                }
                $afterStmt=$pdo->prepare("SELECT * FROM room_vacancy_reports WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>$decision,'review_notes'=>$notes];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$decision==='verified'?'Memverifikasi kamar kosong':'Menolak laporan kamar kosong','room_vacancy_report',$id,$report,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'housekeeping-save') {
                requireCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']);
                $requestedId=trim((string)($input['id']??''));
                $id=$requestedId?:generateServerId('hk');
                $room=trim((string)($input['roomNumber']??'')); if($room==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
                $status=array_key_exists('status',$input)?strtolower(trim((string)$input['status'])):'dirty';
                if(!in_array($status,['dirty','assigned','cleaning','inspection','ready','blocked'],true))throw new InvalidArgumentException('Status housekeeping tidak valid.');
                $priority=in_array($input['priority']??'', ['low','normal','high','urgent'],true)?$input['priority']:'normal';
                $assignedTo=trim((string)($input['assignedTo']??''));
                $assignedStaffId=null;
                if(in_array($status,['assigned','cleaning'],true) && $assignedTo===''){
                    $assignedTo=currentStaffLabel($loggedInStaff);
                    $assignedStaffId=(string)$loggedInStaff['id'];
                }
                $pdo->beginTransaction();
                $roomStmt=$pdo->prepare("SELECT number,status FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomStmt->execute([$room]);if(!$roomStmt->fetch(PDO::FETCH_ASSOC))throw new RuntimeException('Kamar tidak ditemukan.');
                $beforeStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if($before && in_array(strtolower(trim((string)($before['status']??''))),['ready','completed'],true)){
                    throw new RuntimeException('Tugas housekeeping yang sudah siap/selesai adalah histori audit dan tidak boleh dibuka atau diedit kembali. Buat tugas baru untuk siklus berikutnya.');
                }
                if($before && (string)$before['room_number']!==$room)throw new RuntimeException('Nomor kamar pada tugas housekeeping yang sudah ada tidak boleh dipindahkan. Tutup tugas lama lalu gunakan tugas kamar tujuan.');
                if($before){
                    $currentHousekeepingStatus=strtolower(trim((string)($before['status']??'dirty')));
                    $housekeepingTransitions=[
                        'dirty'=>['dirty','assigned','cleaning','blocked'],
                        'assigned'=>['assigned','cleaning','blocked'],
                        'cleaning'=>['cleaning','inspection','ready','blocked'],
                        'inspection'=>['inspection','cleaning','ready','blocked'],
                        'blocked'=>['blocked'],
                    ];
                    if(!in_array($status,$housekeepingTransitions[$currentHousekeepingStatus]??[$currentHousekeepingStatus],true)){
                        throw new RuntimeException('Transisi housekeeping '.$currentHousekeepingStatus.' → '.$status.' tidak diperbolehkan. Task blocked hanya dibuka kembali melalui penyelesaian maintenance.');
                    }
                }
                $activeSiblingStmt=$pdo->prepare("SELECT id,status FROM housekeeping_tasks WHERE room_number=? AND id<>? AND status NOT IN ('ready','completed') ORDER BY created_at,id LIMIT 1 FOR UPDATE");
                $activeSiblingStmt->execute([$room,$id]);$activeSibling=$activeSiblingStmt->fetch(PDO::FETCH_ASSOC);
                if($activeSibling){
                    throw new RuntimeException("Kamar {$room} sudah memiliki tugas housekeeping aktif {$activeSibling['id']} ({$activeSibling['status']}). Gunakan tugas tersebut; sistem tidak mengizinkan tugas aktif ganda.");
                }
                $isHousekeepingSupervisor=in_array(strtolower((string)($loggedInStaff['role']??'')),['admin','manager','receptionist'],true);
                if($before&&!$isHousekeepingSupervisor){
                    $owned=(string)($before['assigned_staff_id']??'')===(string)$loggedInStaff['id']||(string)($before['created_by']??'')===(string)$loggedInStaff['id'];
                    if(!$owned)throw new RuntimeException('Tugas housekeeping milik staf lain tidak dapat diubah.');
                    $assignedTo=currentStaffLabel($loggedInStaff);$assignedStaffId=(string)$loggedInStaff['id'];
                }elseif(!$isHousekeepingSupervisor){
                    $assignedTo=currentStaffLabel($loggedInStaff);$assignedStaffId=(string)$loggedInStaff['id'];
                }
                if(!$before&&$status==='ready')throw new RuntimeException('Tugas baru tidak boleh langsung ready. Buat/mulai tugas pembersihan terlebih dahulu.');
                $lifecycleAction=null;
                if($status==='cleaning')$lifecycleAction='cleaning';
                elseif($status==='blocked')$lifecycleAction='need_maintenance';
                elseif($status==='ready' && (string)($before['status']??'')!=='ready')$lifecycleAction='clean_available';
                $persistedStatus=$lifecycleAction?((string)($before['status']??'dirty')):$status;
                if($persistedStatus==='completed')$persistedStatus='dirty';
                $pdo->prepare("INSERT INTO housekeeping_tasks (id,room_number,assigned_to,assigned_staff_id,priority,status,checklist,last_action_by_staff_id,last_action_source,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE assigned_to=VALUES(assigned_to),assigned_staff_id=COALESCE(VALUES(assigned_staff_id),assigned_staff_id),priority=VALUES(priority),status=VALUES(status),checklist=VALUES(checklist),last_action_by_staff_id=VALUES(last_action_by_staff_id),last_action_source=VALUES(last_action_source),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$id,$room,$assignedTo?:null,$assignedStaffId,$priority,$persistedStatus,trim((string)($input['checklist']??'')),(string)$loggedInStaff['id'],'web',(string)$loggedInStaff['id']]);
                $housekeepingLifecycleResult=null;
                if($lifecycleAction)$housekeepingLifecycleResult=applyHousekeepingAction($pdo,$loggedInStaff,$room,$lifecycleAction,'web',$id,(string)($input['damageDetail']??''));
                $afterStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'room_number'=>$room,'status'=>$status];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Memperbarui tugas housekeeping':'Membuat tugas housekeeping','housekeeping_task',$id,$before,$after,'web');
                if(!$lifecycleAction)bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($housekeepingLifecycleResult){
                    $hkNotify=tamasyaHousekeepingCanonicalNotification($housekeepingLifecycleResult,$loggedInStaff);
                    broadcastTelegramNotification($pdo,(string)$hkNotify['telegram'],false);
                }
            } elseif ($command === 'housekeeping-status') {
                requireCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']);
                $status=strtolower(trim((string)($input['status']??'')));
                if(!in_array($status,['dirty','assigned','cleaning','inspection','ready','blocked'],true))throw new InvalidArgumentException('Status housekeeping tidak valid.');
                $taskId=trim((string)($input['id']??''));if($taskId==='')throw new InvalidArgumentException('ID tugas housekeeping wajib diisi.');
                $pdo->beginTransaction();
                $lockedStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? LIMIT 1 FOR UPDATE");$lockedStmt->execute([$taskId]);$before=$lockedStmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Tugas housekeeping tidak ditemukan.');
                $currentHousekeepingStatus=strtolower(trim((string)($before['status']??'')));
                if(in_array($currentHousekeepingStatus,['ready','completed'],true)){
                    throw new RuntimeException('Tugas housekeeping yang sudah siap/selesai adalah histori audit dan tidak boleh dibuka kembali. Buat tugas baru untuk siklus berikutnya.');
                }
                $housekeepingTransitions=[
                    'dirty'=>['dirty','assigned','cleaning','blocked'],
                    'assigned'=>['assigned','cleaning','blocked'],
                    'cleaning'=>['cleaning','inspection','ready','blocked'],
                    'inspection'=>['inspection','cleaning','ready','blocked'],
                    'blocked'=>['blocked'],
                ];
                if(!in_array($status,$housekeepingTransitions[$currentHousekeepingStatus]??[$currentHousekeepingStatus],true)){
                    throw new RuntimeException('Transisi housekeeping '.$currentHousekeepingStatus.' → '.$status.' tidak diperbolehkan. Task blocked hanya dibuka kembali melalui penyelesaian maintenance.');
                }
                $assignedId=trim((string)($before['assigned_staff_id']??''));
                $isSupervisor=in_array(strtolower((string)($loggedInStaff['role']??'')),['admin','manager'],true);
                if($assignedId!=='' && $assignedId!==(string)$loggedInStaff['id'] && !$isSupervisor)throw new RuntimeException('Tugas sudah diambil staf lain.');
                $housekeepingLifecycleResult=null;
                if(in_array($status,['cleaning','ready','blocked'],true)){
                    $action=$status==='ready'?'clean_available':($status==='blocked'?'need_maintenance':'cleaning');
                    $housekeepingLifecycleResult=applyHousekeepingAction($pdo,$loggedInStaff,(string)$before['room_number'],$action,'web',(string)$before['id'],(string)($input['damageDetail']??''));
                }elseif($status==='assigned'){
                    $pdo->prepare("UPDATE housekeeping_tasks SET status='assigned',assigned_to=?,assigned_staff_id=?,last_action_by_staff_id=?,last_action_source='web',completed_at=NULL,completed_by_staff_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                        ->execute([currentStaffLabel($loggedInStaff),(string)$loggedInStaff['id'],(string)$loggedInStaff['id'],$taskId]);
                }else{
                    $pdo->prepare("UPDATE housekeeping_tasks SET status=?,assigned_to=COALESCE(NULLIF(assigned_to,''),?),assigned_staff_id=COALESCE(assigned_staff_id,?),last_action_by_staff_id=?,last_action_source='web',completed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                        ->execute([$status,currentStaffLabel($loggedInStaff),(string)$loggedInStaff['id'],(string)$loggedInStaff['id'],$taskId]);
                }
                $afterStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? LIMIT 1");$afterStmt->execute([$taskId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$taskId,'status'=>$status];
                $housekeepingAuditAction=$status==='assigned'?'Mengambil tugas housekeeping':($status==='inspection'?'Mengirim tugas housekeeping ke inspeksi':'Memperbarui status housekeeping');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$housekeepingAuditAction,'housekeeping_task',$taskId,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($housekeepingLifecycleResult){
                    $hkNotify=tamasyaHousekeepingCanonicalNotification($housekeepingLifecycleResult,$loggedInStaff);
                    broadcastTelegramNotification($pdo,(string)$hkNotify['telegram'],false);
                }
            } elseif ($command === 'maintenance-ticket-save') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service']);
                $id=trim((string)($input['id']??''))?:generateServerId('ticket');
                $title=trim((string)($input['title']??''));if($title==='')throw new InvalidArgumentException('Judul tiket maintenance wajib diisi.');
                $description=trim((string)($input['description']??''));
                $workType=strtolower(trim((string)($input['workType']??'corrective')));
                if(!in_array($workType,['corrective','preventive','inspection'],true))throw new InvalidArgumentException('Jenis pekerjaan maintenance tidak valid.');
                if($workType==='corrective')tamasyaRequireTechnicalDamageDetail(trim($title.' '.$description),'Detail work order maintenance');
                $priority=in_array($input['priority']??'', ['low','normal','high','urgent'],true)?$input['priority']:'normal';
                $pdo->beginTransaction();
                $beforeStmt=$pdo->prepare("SELECT * FROM maintenance_tickets WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if($before && in_array(strtolower(trim((string)($before['status']??''))),['completed','cancelled'],true)){
                    throw new RuntimeException('Tiket maintenance yang sudah completed/cancelled adalah histori audit terminal dan tidak boleh diedit. Buat tiket baru untuk koreksi atau pekerjaan lanjutan.');
                }
                $maintenanceRole=strtolower((string)($loggedInStaff['role']??''));
                $isMaintenanceSupervisor=in_array($maintenanceRole,['admin','manager','receptionist'],true);
                if($before&&$maintenanceRole==='cleaning_service'){
                    $owned=(string)($before['created_by']??'')===(string)$loggedInStaff['id']||trim((string)($before['assigned_to']??''))===currentStaffLabel($loggedInStaff);
                    if(!$owned)throw new RuntimeException('Tiket maintenance milik staf lain tidak dapat diubah.');
                }
                $assignedToInput=trim((string)($input['assignedTo']??''));
                $assignedTo=$isMaintenanceSupervisor?($assignedToInput?:null):currentStaffLabel($loggedInStaff);
                $estimatedCost=in_array($maintenanceRole,['admin','manager'],true)?max(0,(float)($input['estimatedCost']??0)):(float)($before['estimated_cost']??0);
                $requestedAssetRef=tamasyaR3CanonicalMaintenanceAssetRef($pdo,$input,$before);
                tamasyaR7AssertMaintenanceTargetOwnership($pdo,$requestedAssetRef,'maintenance-ticket-save');
                tamasyaR3AssertMaintenanceRoomOwnershipImmutable($pdo,$before,$requestedAssetRef);
                $originType=trim((string)($before['origin_type']??'manual'))?:'manual';
                $originId=trim((string)($before['origin_id']??''))?:null;
                $pdo->prepare("INSERT INTO maintenance_tickets (id,asset_ref,title,description,priority,status,work_type,origin_type,origin_id,assigned_to,estimated_cost,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'open',?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE asset_ref=VALUES(asset_ref),title=VALUES(title),description=VALUES(description),priority=VALUES(priority),work_type=VALUES(work_type),assigned_to=VALUES(assigned_to),estimated_cost=VALUES(estimated_cost),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$id,$requestedAssetRef,$title,$description,$priority,$workType,$originType,$originId,$assignedTo,$estimatedCost,$loggedInStaff['id']]);
                $maintenanceRoomReconciliation=tamasyaR3ReconcileRoomForActiveMaintenance($pdo,$loggedInStaff,$requestedAssetRef,'maintenance-ticket-save');
                $afterStmt=$pdo->prepare("SELECT * FROM maintenance_tickets WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'title'=>$title,'status'=>'open'];
                if($maintenanceRoomReconciliation!==null)$after['room_reconciliation']=$maintenanceRoomReconciliation;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Memperbarui tiket maintenance':'Membuat tiket maintenance','maintenance_ticket',$id,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'maintenance-ticket-status') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service']);
                $status=trim((string)($input['status']??''));
                if(!in_array($status,['open','assigned','in_progress','waiting_part','completed','cancelled'],true))throw new InvalidArgumentException('Status tiket maintenance tidak valid.');
                $ticketId=trim((string)($input['id']??''));if($ticketId==='')throw new InvalidArgumentException('ID tiket maintenance wajib diisi.');
                $pdo->beginTransaction();
                $ticketStmt=$pdo->prepare("SELECT * FROM maintenance_tickets WHERE id=? LIMIT 1 FOR UPDATE");$ticketStmt->execute([$ticketId]);$ticket=$ticketStmt->fetch(PDO::FETCH_ASSOC);if(!$ticket)throw new RuntimeException('Tiket maintenance tidak ditemukan.');
                if(strtolower((string)($loggedInStaff['role']??''))==='cleaning_service'){
                    $owned=(string)($ticket['created_by']??'')===(string)$loggedInStaff['id']||trim((string)($ticket['assigned_to']??''))===currentStaffLabel($loggedInStaff);
                    if(!$owned)throw new RuntimeException('Tiket maintenance milik staf lain tidak dapat diubah.');
                }
                $currentStatus=strtolower(trim((string)($ticket['status']??'open')));
                // Retry terminal identik tidak boleh mem-posting ulang biaya/waktu. Khusus
                // completed, lakukan rekonsiliasi metadata blocker legacy yang dulu belum
                // ditutup; ini tidak mengubah tiket/biaya dan aman diulang.
                if(in_array($currentStatus,['completed','cancelled'],true) && $status===$currentStatus){
                    $legacyResolvedDamageAlertId='';
                    if($currentStatus==='completed'){
                        $assetRef=trim((string)($ticket['asset_ref']??''));
                        $ticketRoomNumber=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$assetRef);
                        if($ticketRoomNumber!==''){
                            $legacyResolvedDamageAlertId=reconcileCompletedCheckoutDamageAlert($pdo,$loggedInStaff,$ticketId,$ticketRoomNumber,'web-idempotent-reconcile');
                            if($legacyResolvedDamageAlertId!=='')bumpServerRevision($pdo);
                        }
                    }
                    tamasyaFinancialCommit($pdo);
                    echo json_encode(['success'=>true,'idempotent'=>true,'reconciledDamageAlertId'=>$legacyResolvedDamageAlertId?:null,'message'=>$legacyResolvedDamageAlertId!==''?'Status tiket sudah final; blocker kerusakan checkout lama berhasil direkonsiliasi.':'Status tiket maintenance sudah final dan telah diproses sebelumnya.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);
                    break;
                }
                $allowedTransitions=[
                    'open'=>['open','assigned','in_progress','waiting_part','completed','cancelled'],
                    'assigned'=>['assigned','in_progress','waiting_part','completed','cancelled'],
                    'in_progress'=>['in_progress','waiting_part','completed','cancelled'],
                    'waiting_part'=>['waiting_part','assigned','in_progress','completed','cancelled'],
                    'completed'=>['completed'],
                    'cancelled'=>['cancelled'],
                ];
                if(!in_array($status,$allowedTransitions[$currentStatus]??[$currentStatus],true))throw new RuntimeException('Transisi tiket maintenance '.$currentStatus.' → '.$status.' tidak diperbolehkan. Tiket completed/cancelled bersifat terminal; koreksi dilakukan melalui tiket/transaksi baru.');
                $resolutionNote=trim((string)($input['notes']??$input['resolutionNote']??''));
                $cancelReason=trim((string)($input['cancelReason']??$input['notes']??''));
                if($status==='completed' && tamasyaStringLength($resolutionNote)<3)throw new InvalidArgumentException('Catatan penyelesaian maintenance minimal 3 karakter. Jelaskan hasil pekerjaan/hasil pemeriksaan.');
                if($status==='cancelled' && tamasyaStringLength($cancelReason)<5)throw new InvalidArgumentException('Alasan pembatalan maintenance minimal 5 karakter. Cancelled bukan sinonim pekerjaan selesai.');
                $actual=array_key_exists('actualCost',$input)?max(0,(float)$input['actualCost']):(float)$ticket['actual_cost'];
                $transactionId=$ticket['transaction_id']??null;$approvalRequestId=$ticket['approval_request_id']??null;
                $paymentMethod=strtolower(trim((string)($input['paymentMethod']??'payable')));$paymentBank=trim((string)($input['bankAccountId']??''));
                $registeredInventoryAsset=tamasyaR7RegisteredInventoryAssetForMaintenance($pdo,trim((string)($ticket['asset_ref']??'')));
                if($registeredInventoryAsset && $status==='completed' && !empty($input['recordAsExpense'])){
                    throw new RuntimeException('Biaya untuk aset yang terdaftar di Inventory wajib dicatat satu kali melalui Inventory → Pemeliharaan. Tiket legacy ini boleh diselesaikan tanpa posting expense dari Maintenance Ticket.');
                }
                if(!in_array($paymentMethod,['cash','transfer','qris','payable'],true))throw new InvalidArgumentException('Metode biaya maintenance harus payable, cash, transfer, atau qris.');
                $expenseShift=null;
                if($status==='completed' && $actual>0 && !empty($input['recordAsExpense']) && !$transactionId){
                    if($paymentMethod==='cash' && in_array($loggedInStaff['role']??'', ['admin','manager','finance'],true)){
                        $required=(int)$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn()===1;
                        $expenseShift=resolveOpenShiftSessionId($pdo,$loggedInStaff,$required);
                    }
                    if(in_array($loggedInStaff['role']??'', ['admin','manager','finance'],true)){
                        $transactionId=createMaintenanceExpense($pdo,$ticket,$actual,$loggedInStaff,$paymentMethod,$paymentBank,$expenseShift);
                    } else {
                        $approvalRequestId=generateServerId('approval');
                        $payload=['actualCost'=>$actual,'paymentMethod'=>$paymentMethod,'bankAccountId'=>$paymentBank,'shiftSessionId'=>$expenseShift];
                        $pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,'maintenance_expense','maintenance_ticket',?,?,?, ?,?,?,'pending',CURRENT_TIMESTAMP)")
                            ->execute([$approvalRequestId,$ticket['id'],$actual,'Biaya maintenance memerlukan persetujuan finance/manager',tamasyaApprovalPayloadJson($payload),$loggedInStaff['id'],$loggedInStaff['name']]);
                    }
                }
                if($status==='cancelled' && $approvalRequestId){
                    $pdo->prepare("UPDATE approval_requests SET status='rejected',decision_notes=CONCAT(COALESCE(decision_notes,''),CASE WHEN COALESCE(decision_notes,'')='' THEN '' ELSE ' | ' END,'AUTO: tiket maintenance dibatalkan sebelum posting biaya'),decided_at=COALESCE(decided_at,CURRENT_TIMESTAMP) WHERE id=? AND status='pending'")->execute([$approvalRequestId]);
                }
                $pdo->prepare("UPDATE maintenance_tickets SET status=?,actual_cost=?,transaction_id=?,approval_request_id=?,resolution_note=IF(?='completed',?,resolution_note),cancel_reason=IF(?='cancelled',?,cancel_reason),completed_at=IF(?='completed',CURRENT_TIMESTAMP,NULL),updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$status,$actual,$transactionId,$approvalRequestId,$status,$resolutionNote?:null,$status,$cancelReason?:null,$status,$ticket['id']]);
                $roomReconciliation=null;
                if($status==='cancelled' && $currentStatus!=='cancelled'){
                    $assetRef=trim((string)($ticket['asset_ref']??''));
                    $ticketRoomNumber=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$assetRef);
                    if($ticketRoomNumber!==''){
                        $roomLookup=$pdo->prepare("SELECT number,status FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomLookup->execute([$ticketRoomNumber]);$ticketRoom=$roomLookup->fetch(PDO::FETCH_ASSOC);
                        if($ticketRoom){
                            $activeBookingStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");$activeBookingStmt->execute([$ticketRoomNumber]);$activeBookingId=(string)($activeBookingStmt->fetchColumn()?:'');
                            // Review cancellation is itself a domain blocker. Create it first,
                            // then derive the room projection so persisted status and read projection
                            // observe exactly the same blocker set.
                            $reconciledRoomStatus=$activeBookingId!==''?'booked':'maintenance';
                            $cancelBlockers=$activeBookingId!==''
                                ? [['type'=>'active_booking','id'=>$activeBookingId,'status'=>'active'],['type'=>'maintenance_review','ticketId'=>$ticketId,'status'=>'open']]
                                : [['type'=>'maintenance_review','ticketId'=>$ticketId,'status'=>'open']];
                            $alertId='alert_maint_cancel_'.substr(hash('sha256',$ticketId.'|'.$ticketRoomNumber),0,40);
                            $alertSource='maintenance-cancelled:'.$ticketRoomNumber;
                            $alertMessage='Kamar '.$ticketRoomNumber.' terkait tiket '.$ticketId.' yang dibatalkan. Manager/Admin wajib melakukan review eksplisit: nyatakan aman/tidak perlu pekerjaan atau buat pekerjaan lanjutan. Alert hanya notifikasi; keputusan domain review yang menentukan blocker kamar.';
                            $pdo->prepare("INSERT INTO system_alerts(id,severity,source,title,message,created_at) VALUES (?,'warning',?,'Tiket maintenance dibatalkan - kamar perlu keputusan operasional',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source=VALUES(source),message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")->execute([$alertId,$alertSource,$alertMessage]);
                            $review=tamasyaCreateMaintenanceCancellationReview($pdo,$loggedInStaff,$ticket,$ticketRoomNumber,$alertId,'maintenance-ticket-status');
                            if($review)$pdo->prepare("UPDATE system_alerts SET entity_type='maintenance_cancellation_review',entity_id=? WHERE id=?")->execute([(string)$review['id'],$alertId]);
                            $projection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$ticketRoomNumber,'maintenance-cancelled-review-open');
                            $roomReconciliation=['roomNumber'=>$ticketRoomNumber,'roomStatus'=>(string)($projection['roomStatus']??$reconciledRoomStatus),'blockers'=>$projection['blockers']??$cancelBlockers,'alertId'=>$alertId,'reviewId'=>(string)($review['id']??'')?:null,'projection'=>$projection];
                        }
                    }
                }
                if($status==='completed'){
                    $assetRef=trim((string)($ticket['asset_ref']??''));
                    $ticketRoomNumber=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$assetRef);
                    if($ticketRoomNumber!==''){
                        $roomLookup=$pdo->prepare("SELECT number,status FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomLookup->execute([$ticketRoomNumber]);$ticketRoom=$roomLookup->fetch(PDO::FETCH_ASSOC);
                        if($ticketRoom){
                            // Alert kerusakan checkout adalah pasangan dari tiket ini. Begitu
                            // maintenance completed, alert tersebut harus selesai pada transaksi
                            // yang sama; blocker lain (housekeeping/kunci/smart-lock) tetap berlaku.
                            $resolvedDamageAlertId=reconcileCompletedCheckoutDamageAlert($pdo,$loggedInStaff,$ticketId,$ticketRoomNumber,'maintenance-close');
                            // R3: satu tiket selesai tidak boleh membuka housekeeping bila tiket
                            // maintenance lain untuk kamar yang sama masih aktif.
                            $hkResolution=tamasyaR3ResolveHousekeepingAfterMaintenance($pdo,$loggedInStaff,$ticketRoomNumber,'maintenance-close');
                            if(!empty($hkResolution['activeBookingId'])){
                                $roomReconciliation=['roomNumber'=>$ticketRoomNumber,'roomStatus'=>'booked','blockers'=>[['type'=>'active_booking','id'=>$hkResolution['activeBookingId'],'status'=>'active']],'remainingMaintenance'=>$hkResolution['remainingMaintenance'],'inspectionTaskId'=>$hkResolution['inspectionTaskId'],'resolvedDamageAlertId'=>$resolvedDamageAlertId?:null];
                            }else{
                                // R6 R4: rooms.status hanya cache/projection. Setelah maintenance terminal,
                                // resolver housekeeping sudah menentukan apakah masih inspection/ready; blocker
                                // domain lain (key, hold, Night Audit, Lost & Found, dsb.) juga harus ikut
                                // menentukan status. Jangan memaksa string 'maintenance' dari callback tiket.
                                $projection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$ticketRoomNumber,'maintenance-close');
                                $roomReconciliation=['roomNumber'=>$ticketRoomNumber,'roomStatus'=>$projection['roomStatus']??'maintenance','blockers'=>$projection['blockers']??[],'remainingMaintenance'=>$hkResolution['remainingMaintenance'],'inspectionTaskId'=>$hkResolution['inspectionTaskId'],'resolvedDamageAlertId'=>$resolvedDamageAlertId?:null,'projection'=>$projection];
                            }
                        }
                    }
                }
                $afterStmt=$pdo->prepare("SELECT * FROM maintenance_tickets WHERE id=? LIMIT 1");$afterStmt->execute([$ticketId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$ticketId,'status'=>$status,'actual_cost'=>$actual];
                if($roomReconciliation!==null)$after['room_reconciliation']=$roomReconciliation;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah status tiket maintenance','maintenance_ticket',$ticketId,$ticket,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'guest-profile-save') {
                requireCapability($loggedInStaff,'view_guest_identity',['admin','manager','receptionist']);
                $id=trim((string)($input['id']??''))?:generateServerId('guest');
                $name=trim((string)($input['name']??''));if($name==='')throw new InvalidArgumentException('Nama tamu wajib diisi.');
                $email=trim((string)($input['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email tamu tidak valid.');
                $phone=substr(trim((string)($input['phone']??'')),0,100);
                $retentionUntil=trim((string)($input['retentionUntil']??''));
                if($retentionUntil!==''&&!validIsoDate($retentionUntil))throw new InvalidArgumentException('Tanggal retensi data tamu tidak valid.');
                $pdo->beginTransaction();
                $beforeStmt=$pdo->prepare("SELECT * FROM guest_profiles WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $requestedBlacklist=(int)($input['blacklisted']??($before['blacklisted']??0))?1:0;
                if(!hasCapability($loggedInStaff,'approve_sensitive_actions',['admin','manager']) && $requestedBlacklist!==(int)($before['blacklisted']??0))throw new RuntimeException('Status blacklist hanya dapat diubah Admin/Manager.');
                $pdo->prepare("INSERT INTO guest_profiles (id,name,phone,email,identity_number,preferences,notes,blacklisted,retention_until,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone),email=VALUES(email),identity_number=VALUES(identity_number),preferences=VALUES(preferences),notes=VALUES(notes),blacklisted=VALUES(blacklisted),retention_until=VALUES(retention_until),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$id,$name,$phone,$email,trim((string)($input['identityNumber']??''))?:null,trim((string)($input['preferences']??'')),trim((string)($input['notes']??'')),$requestedBlacklist,$retentionUntil?:null,$loggedInStaff['id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM guest_profiles WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'blacklisted'=>(int)($input['blacklisted']??0),'retention_until'=>$retentionUntil?:null];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Memperbarui profil tamu':'Membuat profil tamu','guest_profile',$id,tamasyaGuestProfileAuditSnapshot($before),tamasyaGuestProfileAuditSnapshot($after),'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'guest-sync') {
                requireCapability($loggedInStaff,'view_guest_identity',['admin','manager','receptionist']);
                $pdo->beginTransaction();
                $rows=$pdo->query("SELECT guestName,guestPhone,guestEmail FROM bookings WHERE guestName<>'' GROUP BY guestName,guestPhone,guestEmail")->fetchAll(PDO::FETCH_ASSOC)?:[];
                $stmt=$pdo->prepare("INSERT INTO guest_profiles (id,name,phone,email,created_by,created_at,updated_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                $exists=$pdo->prepare("SELECT id FROM guest_profiles WHERE (phone<>'' AND phone=?) OR (email<>'' AND email=?) LIMIT 1 FOR UPDATE");
                $created=[];
                foreach($rows as $r){
                    $name=trim((string)($r['guestName']??''));if($name==='')continue;
                    $phone=substr(trim((string)($r['guestPhone']??'')),0,100);$email=trim((string)($r['guestEmail']??''));
                    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$email='';
                    if($phone===''&&$email==='')continue;
                    $exists->execute([$phone,$email]);if($exists->fetchColumn())continue;
                    $id=generateServerId('guest');$stmt->execute([$id,$name,$phone,$email,$loggedInStaff['id']]);$created[]=$id;
                }
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Sinkronisasi profil tamu dari booking','guest_profile_sync',hash('sha256',implode('|',$created)),null,['createdIds'=>$created,'createdCount'=>count($created)],'web');
                if($created)bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'guest-service-open') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
                $requestType=trim((string)($input['requestType']??''));$description=(string)($input['description']??'');
                $priority=strtolower(trim((string)($input['priority']??'normal')));$department=trim((string)($input['department']??''));
                $bookingId=trim((string)($input['bookingId']??''));$roomNumber=trim((string)($input['roomNumber']??''));
                $contextType=strtolower(trim((string)($input['contextType']??'')));
                $requesterName=trim((string)($input['requesterName']??''));$requesterContact=trim((string)($input['requesterContact']??''));$locationLabel=trim((string)($input['locationLabel']??''));
                $requestChannel=strtolower(trim((string)($input['requestChannel']??'staff_recorded')));$neededAt=trim((string)($input['neededAt']??''));
                $operationId=trim((string)($input['operationId']??($_SERVER['HTTP_X_TAMASYA_OPERATION_ID']??'')));
                $pdo->beginTransaction();
                $request=tamasyaCreateGuestServiceRequest($pdo,$loggedInStaff,$requestType,$description,$priority,$department,$bookingId,$roomNumber,'operations_center',$operationId,'guest-service-open',[
                    'contextType'=>$contextType,'requesterName'=>$requesterName,'requesterContact'=>$requesterContact,'locationLabel'=>$locationLabel,'requestChannel'=>$requestChannel,'neededAt'=>$neededAt,
                ]);
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                // Telegram mirrors the committed canonical request. It never owns
                // Guest Service state and is skipped on idempotent replay.
                if(empty($request['idempotent']))broadcastTelegramNotification($pdo,tamasyaGuestServiceTelegramMessage($request,'open',$loggedInStaff),false,'operations',tamasyaGuestServiceTelegramReplyMarkup($request,'open'));
            } elseif ($command === 'guest-service-progress') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
                $id=trim((string)($input['id']??''));$status=strtolower(trim((string)($input['status']??'')));if($id==='')throw new InvalidArgumentException('ID permintaan tamu wajib diisi.');
                $assignedTo=trim((string)($input['assignedTo']??''));
                $serviceActorRole=strtolower((string)($loggedInStaff['role']??''));$serviceActorId=(string)($loggedInStaff['id']??'');
                if($assignedTo!==''&&!in_array($serviceActorRole,['admin','manager'],true)&&$assignedTo!==$serviceActorId)throw new RuntimeException('Role ini hanya dapat mengambil permintaan untuk dirinya sendiri.');
                $pdo->beginTransaction();
                $currentService=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);if(!$currentService)throw new RuntimeException('Permintaan tamu tidak ditemukan.');
                $currentAssignee=trim((string)($currentService['assigned_to']??''));
                if($status==='in_progress'&&$currentAssignee!==''&&$currentAssignee!==$serviceActorId&&!in_array($serviceActorRole,['admin','manager'],true))throw new RuntimeException('Permintaan ini sudah ditugaskan ke staf lain. Manager/Admin harus melakukan reassignment bila diperlukan.');
                $result=tamasyaProgressGuestServiceRequest($pdo,$loggedInStaff,$id,$status,$assignedTo,'guest-service-progress');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'guest-service-close') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
                $id=trim((string)($input['id']??''));$status=strtolower(trim((string)($input['status']??'')));$note=trim((string)($input['note']??''));if($id==='')throw new InvalidArgumentException('ID permintaan tamu wajib diisi.');
                if($status==='cancelled')requireRoles($loggedInStaff,['admin','manager','receptionist']);
                $pdo->beginTransaction();
                $currentService=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);if(!$currentService)throw new RuntimeException('Permintaan tamu tidak ditemukan.');
                $serviceActorRole=strtolower((string)($loggedInStaff['role']??''));$serviceActorId=(string)($loggedInStaff['id']??'');$currentAssignee=trim((string)($currentService['assigned_to']??''));
                if($status==='fulfilled'&&$currentAssignee!==''&&$currentAssignee!==$serviceActorId&&!in_array($serviceActorRole,['admin','manager','receptionist'],true))throw new RuntimeException('Permintaan ini ditugaskan ke staf lain dan tidak dapat diselesaikan oleh akun ini.');
                $result=tamasyaCloseGuestServiceRequest($pdo,$loggedInStaff,$id,$status,$note,'guest-service-close');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                if(empty($result['idempotent'])&&!empty($result['request']))broadcastTelegramNotification($pdo,tamasyaGuestServiceTelegramMessage((array)$result['request'],$status,$loggedInStaff),false,'operations');
            } elseif ($command === 'lost-found-open') {
                // Canonical manual intake for a room-related Lost & Found finding.
                // This closes the former resolver-only gap: operators can create the
                // domain record from its owning menu instead of manufacturing an
                // alert or waiting for a checkout report. Room ownership is explicit
                // because the current R7 schema models Lost & Found as a room blocker.
                requireRoles($loggedInStaff, ['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
                $roomNumber=trim((string)($input['roomNumber']??''));
                $itemDescription=trim((string)($input['itemDescription']??''));
                $operationId=trim((string)($input['operationId']??($_SERVER['HTTP_X_TAMASYA_OPERATION_ID']??'')));
                if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar tempat barang ditemukan wajib diisi.');
                $itemDescription=tamasyaRequireLostFoundItemDetail($itemDescription,'Detail barang Lost & Found');
                if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('Operation ID Lost & Found wajib tersedia dan maksimal 100 karakter.');
                $hash=substr(hash('sha256',$operationId),0,40);
                $caseId='lf_manual_'.$hash;$alertId='alert_lost_found_manual_'.$hash;
                $pdo->beginTransaction();
                $roomStmt=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomStmt->execute([$roomNumber]);
                if(!$roomStmt->fetchColumn())throw new RuntimeException('Kamar tidak ditemukan.');
                $existing=tamasyaLostFoundByIdentifier($pdo,$caseId,true);
                if($existing){
                    if((string)($existing['room_number']??'')!==$roomNumber||trim((string)($existing['item_description']??''))!==$itemDescription){
                        throw new RuntimeException('Operation ID Lost & Found sudah digunakan untuk payload berbeda. Gunakan operation ID baru.');
                    }
                    tamasyaSyncLostFoundAlertProjection($pdo,$existing,(string)($loggedInStaff['id']??''));
                    tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'lost-found-open-idempotent');
                    tamasyaFinancialCommit($pdo);
                } else {
                    $case=tamasyaUpsertLostFoundCase($pdo,$loggedInStaff,$caseId,$alertId,'',$roomNumber,$itemDescription,'manual_operations',$operationId,'lost-found-open');
                    $message='Kamar '.$roomNumber.': barang ditemukan dan belum diamankan. Barang: '.$itemDescription.'. Amankan barang dan catat lokasi custody untuk melepas blocker kamar.';
                    $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,entity_type,entity_id,created_at) VALUES (?,'warning',?,'Barang ditemukan - Lost & Found',?,'lost_found_item',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source=VALUES(source),title=VALUES(title),message=VALUES(message),entity_type='lost_found_item',entity_id=VALUES(entity_id),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                        ->execute([$alertId,'lost-found:'.$roomNumber,$message,$caseId]);
                    $case['alert_id']=$alertId;
                    tamasyaSyncLostFoundAlertProjection($pdo,$case,(string)($loggedInStaff['id']??''));
                    tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'lost-found-open');
                    bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                }
            } elseif ($command === 'lost-found-secure') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager']);
                $id=trim((string)($input['id']??''));$note=trim((string)($input['note']??''));$tag=trim((string)($input['custodyTag']??''));
                if($id==='')throw new InvalidArgumentException('ID Lost & Found wajib diisi.');
                if(tamasyaStringLength($note)<3)throw new InvalidArgumentException('Lokasi penyimpanan/catatan pengamanan minimal 3 karakter.');
                $pdo->beginTransaction();
                $item=tamasyaLostFoundByIdentifier($pdo,$id,true);
                if(!$item){
                    // Source-only legacy bridge: an older `lost-found:<room>` alert
                    // can still own a real room blocker even though no canonical
                    // custody row existed when it was created. Materialize that
                    // domain atomically here instead of asking operators to run a
                    // schema migration or acknowledge the alert incorrectly.
                    $legacyStmt=$pdo->prepare("SELECT * FROM system_alerts WHERE id=? AND acknowledged_at IS NULL AND source LIKE 'lost-found:%' LIMIT 1 FOR UPDATE");
                    $legacyStmt->execute([$id]);$legacy=$legacyStmt->fetch(PDO::FETCH_ASSOC)?:null;
                    if($legacy){
                        $legacySource=(string)($legacy['source']??'');
                        $legacyRoom=trim(substr($legacySource,strlen('lost-found:')));
                        if($legacyRoom==='')throw new RuntimeException('Alert Lost & Found legacy tidak memiliki nomor kamar yang valid.');
                        $legacyDescription=trim((string)($legacy['message']??''));
                        if($legacyDescription==='')$legacyDescription=trim((string)($legacy['title']??''));
                        if($legacyDescription==='')$legacyDescription='Barang tertinggal legacy pada kamar '.$legacyRoom;
                        if(tamasyaStringLength($legacyDescription)>1000)$legacyDescription=substr($legacyDescription,0,1000);
                        try{$legacyDescription=tamasyaRequireLostFoundItemDetail($legacyDescription,'Detail Lost & Found legacy');}
                        catch(InvalidArgumentException $legacyDetailError){$legacyDescription='Barang tertinggal legacy pada kamar '.$legacyRoom.'; detail asli: '.trim((string)($legacy['message']??$legacy['title']??''));}
                        $caseId='lf_legacy_'.substr(hash('sha256',(string)$legacy['id']),0,40);
                        $item=tamasyaUpsertLostFoundCase($pdo,$loggedInStaff,$caseId,(string)$legacy['id'],'',$legacyRoom,$legacyDescription,'legacy_alert',(string)$legacy['id'],'lost-found-legacy-bridge');
                        $pdo->prepare("UPDATE system_alerts SET entity_type='lost_found_item',entity_id=? WHERE id=?")->execute([$caseId,(string)$legacy['id']]);
                    }
                }
                if(!$item)throw new RuntimeException('Kasus Lost & Found tidak ditemukan pada domain maupun alert legacy.');
                $state=strtolower(trim((string)($item['custody_status']??'')));
                if(in_array($state,['secured','guest_notified','returned','disposed','donated','authority'],true)){
                    tamasyaSyncLostFoundAlertProjection($pdo,$item,(string)$loggedInStaff['id']);
                    tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$item['room_number'],'lost-found-secure-idempotent');
                    tamasyaFinancialCommit($pdo);
                    echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Barang Lost & Found sudah tidak berada pada fase room-blocking. Proyeksi kamar telah direkonsiliasi.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;
                }
                if($state!=='found')throw new RuntimeException('Status custody Lost & Found tidak dapat diamankan dari state '.$state.'.');
                $itemId=(string)$item['id'];
                $pdo->prepare("UPDATE lost_found_items SET custody_status='secured',storage_location=?,custody_tag=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND custody_status='found'")
                    ->execute([$note,$tag?:null,$itemId]);
                $afterStmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? LIMIT 1");$afterStmt->execute([$itemId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$item;
                tamasyaSyncLostFoundAlertProjection($pdo,$after,(string)$loggedInStaff['id']);
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$after['room_number'],'lost-found-secure');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengamankan barang Lost & Found dan melepas blocker kamar','lost_found_item',$itemId,$item,array_merge($after,['roomProjection'=>$roomProjection]),'lost-found-secure');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'lost-found-notify') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist']);
                $id=trim((string)($input['id']??''));$note=trim((string)($input['note']??''));
                if($id==='')throw new InvalidArgumentException('ID Lost & Found wajib diisi.');
                if($note!==''&&tamasyaStringLength($note)<3)throw new InvalidArgumentException('Catatan notifikasi minimal 3 karakter bila diisi.');
                $pdo->beginTransaction();$item=tamasyaLostFoundByIdentifier($pdo,$id,true);if(!$item)throw new RuntimeException('Domain Lost & Found tidak ditemukan.');
                $state=strtolower(trim((string)($item['custody_status']??'')));
                if($state==='found')throw new RuntimeException('Barang harus diamankan dari kamar sebelum tamu diberi status notified.');
                if(in_array($state,['returned','disposed','donated','authority'],true))throw new RuntimeException('Custody Lost & Found sudah terminal.');
                $itemId=(string)$item['id'];
                $pdo->prepare("UPDATE lost_found_items SET custody_status='guest_notified',guest_notified_at=COALESCE(guest_notified_at,CURRENT_TIMESTAMP),guest_notified_by=COALESCE(guest_notified_by,?),notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' OR ?='' THEN '' ELSE ' | ' END,?),updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([(string)$loggedInStaff['id'],$note,$note,$itemId]);
                $afterStmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? LIMIT 1");$afterStmt->execute([$itemId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$item;
                tamasyaSyncLostFoundAlertProjection($pdo,$after,(string)$loggedInStaff['id']);
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$after['room_number'],'lost-found-notify');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat tamu telah diberi tahu tentang Lost & Found','lost_found_item',$itemId,$item,array_merge($after,['roomProjection'=>$roomProjection]),'lost-found-notify');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'lost-found-close') {
                requireRoles($loggedInStaff, ['admin','manager']);
                $id=trim((string)($input['id']??''));$resolution=strtolower(trim((string)($input['resolution']??'')));$note=trim((string)($input['note']??''));
                $allowed=['returned'=>'Dikembalikan kepada tamu/penerima sah','disposed'=>'Dimusnahkan/dibuang sesuai kebijakan','donated'=>'Didonasikan sesuai kebijakan','authority'=>'Diserahkan kepada pihak berwenang'];
                if($id==='')throw new InvalidArgumentException('ID custody Lost & Found wajib diisi.');if(!isset($allowed[$resolution]))throw new InvalidArgumentException('Penyelesaian custody harus returned, disposed, donated, atau authority.');if(tamasyaStringLength($note)<3)throw new InvalidArgumentException('Catatan penyelesaian custody minimal 3 karakter.');
                $pdo->beginTransaction();$item=tamasyaLostFoundByIdentifier($pdo,$id,true);if(!$item)throw new RuntimeException('Domain Lost & Found tidak ditemukan.');$state=strtolower(trim((string)($item['custody_status']??'')));
                if($state==='found')throw new RuntimeException('Barang masih berada pada fase room-blocking. Gunakan “Barang sudah diamankan” lebih dulu.');
                if(in_array($state,['returned','disposed','donated','authority'],true)){tamasyaSyncLostFoundAlertProjection($pdo,$item,(string)$loggedInStaff['id']);$roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$item['room_number'],'lost-found-close-idempotent');tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Custody Lost & Found sudah terminal. Proyeksi kamar telah direkonsiliasi.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;}
                if(!in_array($state,['secured','guest_notified'],true))throw new RuntimeException('Status custody Lost & Found tidak dapat ditutup dari state '.$state.'.');
                $itemId=(string)$item['id'];$staffId=(string)$loggedInStaff['id'];
                $pdo->prepare("UPDATE lost_found_items SET custody_status=?,resolution=?,resolution_note=?,resolved_at=CURRENT_TIMESTAMP,resolved_by=?,returned_at=IF(?='returned',CURRENT_TIMESTAMP,returned_at),returned_to=IF(?='returned',?,returned_to),disposed_at=IF(? IN ('disposed','donated','authority'),CURRENT_TIMESTAMP,disposed_at),updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$resolution,$resolution,$note,$staffId,$resolution,$resolution,trim((string)($input['returnedTo']??''))?:$note,$resolution,$itemId]);
                $afterStmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? LIMIT 1");$afterStmt->execute([$itemId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$item;
                tamasyaSyncLostFoundAlertProjection($pdo,$after,$staffId);
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$after['room_number'],'lost-found-close');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyelesaikan custody Lost & Found: '.$allowed[$resolution],'lost_found_item',$itemId,$item,array_merge($after,['roomProjection'=>$roomProjection]),'lost-found-close');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'maintenance-cancellation-review') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager']);
                $id=trim((string)($input['id']??''));$decision=strtolower(trim((string)($input['decision']??'')));$note=trim((string)($input['note']??''));
                if($id==='')throw new InvalidArgumentException('ID review pembatalan maintenance wajib diisi.');
                $pdo->beginTransaction();$review=tamasyaMaintenanceCancellationReviewByIdentifier($pdo,$id,true);if(!$review)throw new RuntimeException('Review pembatalan maintenance tidak ditemukan. Jalankan migration/backfill R4 bila ini data legacy.');
                $roomNumber=trim((string)$review['room_number']);$followupTicketId=null;
                if(strtolower(trim((string)($review['status']??'')))==='resolved'){
                    $alertId=trim((string)($review['alert_id']??''));
                    if($alertId!=='')$pdo->prepare("UPDATE system_alerts SET acknowledged_by=COALESCE(acknowledged_by,?),acknowledged_at=COALESCE(acknowledged_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([(string)$loggedInStaff['id'],$alertId]);
                    tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'maintenance-cancellation-review-idempotent');
                    tamasyaFinancialCommit($pdo);
                    echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Review pembatalan maintenance sudah selesai sebelumnya; tidak ada work order duplikat yang dibuat.','storedDecision'=>(string)($review['decision']??''),'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;
                }
                if($decision==='followup_required'){
                    $detail=tamasyaRequireTechnicalDamageDetail((string)($input['damageDetail']??''),'Detail pekerjaan lanjutan');
                    $followupTicketId='ticket_review_followup_'.substr(hash('sha256',(string)$review['id'].'|'.$detail),0,40);
                    $title=tamasyaTechnicalDamageTicketTitle($roomNumber,$detail,'Review');
                    $pdo->prepare("INSERT INTO maintenance_tickets(id,asset_ref,title,description,priority,status,work_type,origin_type,origin_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'open','corrective','maintenance_review',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE id=id")
                        ->execute([$followupTicketId,'room:'.$roomNumber,$title,$detail,'high',(string)$review['id'],(string)$loggedInStaff['id']]);
                    tamasyaOperationalLink($pdo,'maintenance_cancellation_review',(string)$review['id'],'maintenance_ticket',$followupTicketId,'creates_followup_work_order',$loggedInStaff);
                    tamasyaR3ReconcileRoomForActiveMaintenance($pdo,$loggedInStaff,'room:'.$roomNumber,'maintenance-cancellation-review');
                }
                $resolved=tamasyaResolveMaintenanceCancellationReviewRecord($pdo,$loggedInStaff,$id,$decision,$note,'maintenance-cancellation-review');
                $alertId=trim((string)($review['alert_id']??''));$alert=null;
                if($alertId!==''){$a=$pdo->prepare("SELECT * FROM system_alerts WHERE id=? LIMIT 1 FOR UPDATE");$a->execute([$alertId]);$alert=$a->fetch(PDO::FETCH_ASSOC)?:null;if($alert)$pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=? AND acknowledged_at IS NULL")->execute([(string)$loggedInStaff['id'],$alertId]);}
                if($alert)tamasyaR3ResolveMaintenanceCancellationReview($pdo,$loggedInStaff,$alert);
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'operational-incident-open') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','keamanan','cleaning_service','koki','tukang_kebun','lain_lain']);
                $incidentType=trim((string)($input['incidentType']??'security/safety'));
                $description=(string)($input['description']??'');$severity=strtolower(trim((string)($input['severity']??'warning')));
                $roomNumber=trim((string)($input['roomNumber']??''));$areaRef=trim((string)($input['areaRef']??''));$blocksRoom=!empty($input['blocksRoom']);
                $operationId=trim((string)($input['operationId']??''));
                $pdo->beginTransaction();
                if($roomNumber!=='')requireTelegramRoom($pdo,$roomNumber,true);
                $incident=tamasyaCreateOperationalIncident($pdo,$loggedInStaff,$incidentType,$description,$severity,$roomNumber,$areaRef,$blocksRoom,'operations_center',$operationId,'operational-incident-open');
                if($roomNumber!=='')tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'operational-incident-open');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'operational-incident-progress') {
                requireRoles($loggedInStaff, ['admin','manager','receptionist','keamanan']);
                $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID insiden operasional wajib diisi.');
                $pdo->beginTransaction();$result=tamasyaProgressOperationalIncident($pdo,$loggedInStaff,$id,(string)($input['assignedTo']??''),'operational-incident-progress');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'operational-incident-resolve') {
                requireRoles($loggedInStaff, ['admin','manager']);
                $id=trim((string)($input['id']??''));$note=trim((string)($input['note']??''));if($id==='')throw new InvalidArgumentException('ID insiden operasional wajib diisi.');
                $pdo->beginTransaction();$result=tamasyaResolveOperationalIncident($pdo,$loggedInStaff,$id,$note,'operational-incident-resolve');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'room-hold-open') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager']);
                $roomNumber=trim((string)($input['roomNumber']??''));$holdType=trim((string)($input['holdType']??''));$reason=trim((string)($input['reason']??''));$severity=strtolower(trim((string)($input['severity']??'critical')));
                if(!in_array($severity,['info','warning','critical'],true))$severity='critical';
                $pdo->beginTransaction();requireTelegramRoom($pdo,$roomNumber,true);$hold=tamasyaOpenRoomOperationalHold($pdo,$loggedInStaff,$roomNumber,$holdType,$reason,(string)($input['sourceEntityType']??''),(string)($input['sourceEntityId']??''),$severity,'room-hold-open');
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'room-hold-open');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuka operational hold kamar','room_operational_hold',(string)$hold['id'],null,array_merge($hold,['roomProjection'=>$roomProjection]),'room-hold-open');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'room-hold-release') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager']);
                $id=trim((string)($input['id']??''));$note=trim((string)($input['note']??''));if($id===''||tamasyaStringLength($note)<5)throw new InvalidArgumentException('ID hold dan catatan pelepasan minimal 5 karakter wajib diisi.');
                $pdo->beginTransaction();$stmt=$pdo->prepare("SELECT * FROM room_operational_holds WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Operational hold tidak ditemukan.');
                if(strtolower((string)$before['status'])!=='open'){$roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$before['room_number'],'room-hold-release-idempotent');tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Operational hold sudah dilepas sebelumnya. Proyeksi kamar telah direkonsiliasi.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;}
                $pdo->prepare("UPDATE room_operational_holds SET status='released',released_by=?,released_at=CURRENT_TIMESTAMP,release_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(string)$loggedInStaff['id'],$note,$id]);
                $after=$before;$after['status']='released';$after['release_note']=$note;$roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$before['room_number'],'room-hold-release');$after['roomProjection']=$roomProjection;writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Melepas operational hold kamar','room_operational_hold',$id,$before,$after,'room-hold-release');bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            } elseif ($command === 'alert-ack') {
                requireRoles($loggedInStaff, ['admin','manager']);
                $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID peringatan wajib diisi.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM system_alerts WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Peringatan tidak ditemukan.');
                $alertSource=(string)($before['source']??'');
                $entityType=strtolower(trim((string)($before['entity_type']??'')));
                if(str_starts_with($alertSource,'lost-found:')||str_starts_with($alertSource,'lost-found-custody:')||$entityType==='lost_found_item'){
                    throw new RuntimeException('Lost & Found memiliki lifecycle custody sendiri. Gunakan aksi Lost & Found, bukan “Tandai selesai” alert.');
                }
                if(str_starts_with($alertSource,'maintenance-cancelled:')||$entityType==='maintenance_cancellation_review'){
                    throw new RuntimeException('Pembatalan maintenance membutuhkan keputusan operasional eksplisit. Gunakan “Review pembatalan” dan pilih kondisi aman atau pekerjaan lanjutan.');
                }
                $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$loggedInStaff['id'],$id]);
                $maintenanceReview=null;
                $after=$before;$after['acknowledged_by']=$loggedInStaff['id'];$after['acknowledged_at']=date('Y-m-d H:i:s');
                if($maintenanceReview!==null)$after['maintenanceCancellationReview']=$maintenanceReview;
                $alertAuditAction=$maintenanceReview!==null?'Mereview pembatalan maintenance dan merekonsiliasi housekeeping':'Mengakui peringatan sistem';
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$alertAuditAction,'system_alert',$id,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'schema-doctor') {
                // Read-only schema drift report. Exists because the migration runners
                // have no callers, so a property database could miss columns silently,
                // and shared hosting has no terminal to inspect it. Reports only: it never
                // executes DDL, so it is safe to open at any time.
                requireCapability($loggedInStaff,'view_audit_log',['admin','manager']);
                $report=tamasyaSchemaDoctorInspect($pdo);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memeriksa drift skema database','schema_doctor','system_default',null,
                    ['missingTables'=>count($report['missingTables']),'missingColumns'=>count($report['missingColumns']),'ok'=>$report['ok']],'web');
                echo json_encode(['success'=>true,'data'=>$report],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                break;
            } elseif ($command === 'night-audit-mode-save') {
                // R16.4: avoid overloading "Night Audit required to close shift".
                // Admin-only BY DESIGN (dipin oleh night-audit-mode-regression.php):
                // mematikan Night Audit mengubah kontrol finansial, jadi tetap tertutup.
                requireRoles($loggedInStaff,['admin']);
                if (!array_key_exists('enabled',$input) || !in_array((string)$input['enabled'],['0','1'],true))
                    throw new InvalidArgumentException('Mode Night Audit wajib 0 (OFF) atau 1 (ON).');
                $enabled=(int)$input['enabled'];
                $reason=trim((string)($input['reason']??''));
                if (tamasyaStringLength($reason)<12 || tamasyaStringLength($reason)>500)
                    throw new InvalidArgumentException('Alasan perubahan Night Audit wajib 12–500 karakter.');
                $pdo->beginTransaction();
                $q=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' FOR UPDATE");
                $before=$q->fetch(PDO::FETCH_ASSOC);
                if (!$before) throw new RuntimeException('Pengaturan properti tidak ditemukan.');
                if (!array_key_exists('night_audit_enabled',$before))
                    throw new RuntimeException('Migrasi R16.4 Night Audit belum dijalankan. Perubahan ditolak.');
                $old=(int)$before['night_audit_enabled'];
                if ($old!==$enabled) {
                    if ($enabled===0) {
                        $opened=(int)$pdo->query("SELECT COUNT(*) FROM night_audit_runs WHERE status='open'")->fetchColumn();
                        if ($opened>0) throw new RuntimeException("Ada {$opened} Night Audit terbuka. Selesaikan pemeriksaan sebelum menonaktifkan fitur.");
                    }
                    $pdo->prepare("UPDATE hotel_operational_settings SET night_audit_enabled=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                        ->execute([$enabled,(string)$loggedInStaff['id']]);
                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$enabled?'Mengaktifkan Night Audit':'Menonaktifkan Night Audit',
                        'hotel_operational_settings','system_default',
                        ['night_audit_enabled'=>$old],['night_audit_enabled'=>$enabled,'reason'=>$reason],'web');
                    bumpServerRevision($pdo);
                }
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'modeChanged'=>$old!==$enabled,'nightAuditEnabled'=>(bool)$enabled,
                    'message'=>$enabled?'Night Audit aktif untuk properti ini.':'Night Audit nonaktif untuk properti ini. Riwayat tetap tersimpan; kontrol kas/kunci lain tetap berlaku.']);
                break;
            } elseif ($command === 'operational-settings-save') {
                requireCapability($loggedInStaff,'manage_operational_settings',['admin','manager']);
                // R7: endpoint ini bersifat PATCH-like. Field yang tidak dikirim harus
                // mempertahankan policy tersimpan agar client lama/parsial tidak mereset
                // check-in, shift, key-control, Night Audit, atau smart-lock diam-diam.
                $pdo->beginTransaction();
                $beforeStmt=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' FOR UPDATE");$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$before)throw new RuntimeException('Pengaturan operasional hotel belum tersedia.');
                $timeValue=static function(array $payload,string $key,string $stored,string $label): string {
                    if(!array_key_exists($key,$payload))return substr($stored,0,8);
                    $raw=trim((string)$payload[$key]);
                    if(!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/',$raw))throw new InvalidArgumentException($label.' tidak valid. Gunakan format HH:MM.');
                    return $raw.':00';
                };
                $boolValue=static function(array $payload,string $key,int $stored): int {
                    return array_key_exists($key,$payload)?(!empty($payload[$key])?1:0):($stored?1:0);
                };
                $intValue=static function(array $payload,string $key,int $stored,int $min,int $max): int {
                    return max($min,min($max,array_key_exists($key,$payload)?(int)$payload[$key]:$stored));
                };
                $floatValue=static function(array $payload,string $key,float $stored,float $min=0.0): float {
                    return max($min,array_key_exists($key,$payload)?(float)$payload[$key]:$stored);
                };
                $checkinTime=$timeValue($input,'checkinTime',(string)($before['checkin_time']??'14:00:00'),'Jam check-in');
                $checkoutTime=$timeValue($input,'checkoutTime',(string)($before['checkout_time']??'12:00:00'),'Jam checkout');
                // R14.1: batas slot shift harus menutup 24 jam tanpa celah/tumpang
                // tindih (pagi=day..siang, siang=siang..malam, malam=malam..day).
                $shiftDayStart=$timeValue($input,'shiftDayStart',(string)($before['shift_day_start']??'07:00:00'),'Jam mulai shift pagi');
                $shiftSiangStart=$timeValue($input,'shiftSiangStart',(string)($before['shift_siang_start']??'15:00:00'),'Jam mulai shift siang');
                $shiftMalamStart=$timeValue($input,'shiftMalamStart',(string)($before['shift_malam_start']??'23:00:00'),'Jam mulai shift malam');
                $boundaries=tamasyaShiftBoundariesMinutes(['shift_day_start'=>$shiftDayStart,'shift_siang_start'=>$shiftSiangStart,'shift_malam_start'=>$shiftMalamStart]);
                $requestedMinutes=static function(string $hhmmss): int {
                    preg_match('/^(\d{1,2}):([0-5]\d)/',trim($hhmmss),$m);
                    return ((int)$m[1])*60+(int)$m[2];
                };
                if(!($requestedMinutes($shiftDayStart)<$requestedMinutes($shiftSiangStart) && $requestedMinutes($shiftSiangStart)<$requestedMinutes($shiftMalamStart)))throw new InvalidArgumentException('Urutan jam shift wajib pagi < siang < malam agar menutup 24 jam tanpa celah.');
                $checkoutReminderMinutes=$intValue($input,'checkoutReminderMinutes',(int)($before['checkout_reminder_minutes']??30),0,180);
                $lateGraceMinutes=$intValue($input,'lateGraceMinutes',(int)($before['late_grace_minutes']??60),0,360);
                $allowEarlyCheckin=$boolValue($input,'allowEarlyCheckin',(int)($before['allow_early_checkin']??1));
                $requireOpenShiftForSale=$boolValue($input,'requireOpenShiftForSale',(int)($before['require_open_shift_for_sale']??1));
                $requireKeyControl=$boolValue($input,'requireKeyControl',(int)($before['require_key_control']??1));
                $requirePaymentBeforeKeyIssue=$boolValue($input,'requirePaymentBeforeKeyIssue',(int)($before['require_payment_before_key_issue']??0));
                $requireNightAuditForNightShift=$boolValue($input,'requireNightAuditForNightShift',(int)($before['require_night_audit_for_night_shift']??1));
                $cashVarianceTolerance=$floatValue($input,'cashVarianceTolerance',(float)($before['cash_variance_tolerance']??0),0.0);
                $bridgeUrl=array_key_exists('smartLockBridgeUrl',$input)?trim((string)$input['smartLockBridgeUrl']):trim((string)($before['smart_lock_bridge_url']??''));
                if($bridgeUrl!=='' && !filter_var($bridgeUrl,FILTER_VALIDATE_URL))throw new InvalidArgumentException('URL smart-lock bridge tidak valid.');
                if($bridgeUrl!=='' && strtolower((string)parse_url($bridgeUrl,PHP_URL_SCHEME))!=='https')throw new InvalidArgumentException('Smart-lock bridge production wajib memakai HTTPS.');
                $tokenInput=array_key_exists('smartLockBridgeToken',$input)?(string)$input['smartLockBridgeToken']:'[TERSIMPAN]';
                $storedToken=$before['smart_lock_bridge_token']??null;
                if($tokenInput!=='' && $tokenInput!=='[TERSIMPAN]')$storedToken=encryptStoredSecret($tokenInput);
                $smartLockBridgeTimeout=$intValue($input,'smartLockBridgeTimeout',(int)($before['smart_lock_bridge_timeout']??8),3,30);
                $pdo->prepare("UPDATE hotel_operational_settings SET checkin_time=?,checkout_time=?,shift_day_start=?,shift_siang_start=?,shift_malam_start=?,checkout_reminder_minutes=?,late_grace_minutes=?,allow_early_checkin=?,require_open_shift_for_sale=?,require_key_control=?,require_payment_before_key_issue=?,require_night_audit_for_night_shift=?,cash_variance_tolerance=?,smart_lock_bridge_url=?,smart_lock_bridge_token=?,smart_lock_bridge_timeout=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                    ->execute([$checkinTime,$checkoutTime,$shiftDayStart,$shiftSiangStart,$shiftMalamStart,$checkoutReminderMinutes,$lateGraceMinutes,$allowEarlyCheckin,$requireOpenShiftForSale,$requireKeyControl,$requirePaymentBeforeKeyIssue,$requireNightAuditForNightShift,$cashVarianceTolerance,$bridgeUrl?:null,$storedToken,$smartLockBridgeTimeout,$loggedInStaff['id']]);
                $afterStmt=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default'");$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                if($before)$before['smart_lock_bridge_token']=empty($before['smart_lock_bridge_token'])?null:'[REDACTED]';
                if($after)$after['smart_lock_bridge_token']=empty($after['smart_lock_bridge_token'])?null:'[REDACTED]';
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah kontrol operasional hotel','hotel_operational_settings','system_default',$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'room-access-save') {
                requireCapability($loggedInStaff,'manage_operational_settings',['admin','manager']);
                $room=trim((string)($input['roomNumber']??''));if($room==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
                $mode=in_array($input['accessMode']??'', ['physical','smart','hybrid'],true)?$input['accessMode']:'physical';
                $pdo->beginTransaction();
                $exists=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$exists->execute([$room]);if(!$exists->fetchColumn())throw new RuntimeException('Kamar tidak ditemukan.');
                $beforeStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$room]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $activeBookingStmt=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=? AND status='active' FOR UPDATE");$activeBookingStmt->execute([$room]);$activeBookingCount=(int)$activeBookingStmt->fetchColumn();
                $pendingJobStmt=$pdo->prepare("SELECT COUNT(*) FROM smart_lock_jobs WHERE room_number=? AND status NOT IN ('completed','cancelled') FOR UPDATE");$pendingJobStmt->execute([$room]);$pendingSmartJobs=(int)$pendingJobStmt->fetchColumn();
                $keyOutstanding=$before && ((string)($before['physical_key_status']??'secured')!=='secured' || trim((string)($before['current_booking_id']??''))!=='');
                if($activeBookingCount>0||$pendingSmartJobs>0||$keyOutstanding)throw new RuntimeException('Konfigurasi akses kamar tidak boleh diubah saat ada booking aktif, kunci/PIN masih terikat, atau job smart-lock belum selesai. Selesaikan/revoke akses lalu coba lagi.');
                $physicalKeyRef=trim((string)($input['physicalKeyRef']??''))?:('KEY-'.$room);
                $provider=trim((string)($input['smartLockProvider']??''))?:null;$deviceId=trim((string)($input['smartLockDeviceId']??''))?:null;
                if(in_array($mode,['smart','hybrid'],true)&&($provider===null||$deviceId===null))throw new InvalidArgumentException('Provider dan device ID smart-lock wajib diisi untuk mode smart/hybrid.');
                $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,smart_lock_provider,smart_lock_device_id,smart_lock_enabled,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),physical_key_ref=VALUES(physical_key_ref),smart_lock_provider=VALUES(smart_lock_provider),smart_lock_device_id=VALUES(smart_lock_device_id),smart_lock_enabled=VALUES(smart_lock_enabled),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$room,$mode,$physicalKeyRef,$provider,$deviceId,in_array($mode,['smart','hybrid'],true)?1:0,$loggedInStaff['id']]);
                insertRoomKeyEvent($pdo,$loggedInStaff,$room,null,'configuration_changed',$mode,$physicalKeyRef,null,'configured','Konfigurasi akses kamar diperbarui.');
                $afterStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1");$afterStmt->execute([$room]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['room_number'=>$room,'access_mode'=>$mode];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah konfigurasi akses kamar','room_access_control',$room,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'key-issue') {
                $bookingId=trim((string)($input['bookingId']??''));
                $reason=trim((string)($input['reason']??'Kunci/PIN diserahkan kepada tamu.'));
                $issuance=tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId,$reason,'web');
                $jobId=$issuance['jobId'];$code=$issuance['code'];$last4=$issuance['last4'];
                $mode=$issuance['mode'];$booking=$issuance['booking'];
                $bridgeResult=$issuance['bridgeResult'];$confirmed=$issuance['confirmed'];
                $msg=$confirmed
                    ? "🔐 *AKSES KAMAR DISERAHKAN*\n\nKamar: *{$booking['roomNumber']}*\nTamu: *{$booking['guestName']}*\nMode: *".strtoupper($mode)."*\nPetugas: *".currentStaffLabel($loggedInStaff)."*"
                    : "⚠️ *AKSES SMART-LOCK MENUNGGU KONFIRMASI*\n\nKamar: *{$booking['roomNumber']}*\nJob: `{$jobId}`\nStatus: *".strtoupper((string)($bridgeResult['status']??'pending'))."*";
                broadcastTelegramNotification($pdo,$msg,false);
                echo json_encode([
                    'success'=>true,'credential'=>$confirmed?$code:null,'credentialLast4'=>$last4,'bridge'=>$bridgeResult,
                    'pendingExternalConfirmation'=>!$confirmed,
                    'message'=>$confirmed?($code?'PIN hanya ditampilkan sekali dan bridge telah mengonfirmasi akses.':'Penyerahan kunci fisik tercatat.'):'Permintaan akses tersimpan aman, tetapi smart-lock belum mengonfirmasi. Jangan menyerahkan PIN sebagai aktif sebelum status completed.',
                    'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)
                ]);break;
            } elseif ($command === 'key-return') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist']);
                $bookingId=trim((string)($input['bookingId']??''));
                if($bookingId==='')throw new InvalidArgumentException('ID booking wajib diisi.');
                $reason=trim((string)($input['reason']??'Kunci dikembalikan / PIN dicabut.'));
                $jobId=null;$bridgeResult=null;$booking=null;$mode='physical';
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT b.*,rac.access_mode,rac.physical_key_ref,rac.smart_lock_provider,rac.smart_lock_device_id,rac.physical_key_status,rac.current_booking_id FROM bookings b LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber WHERE b.id=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
                $beforeBooking=$booking;
                $mode=tamasyaResolveRoomAccessMode($booking);
                tamasyaAssertRoomAccessReturnState($booking,$bookingId);
                if(in_array($mode,['smart','hybrid'],true)){
                    $jobId=queueSmartLockBridgeJob($pdo,$booking,[
                        'action'=>'revoke','roomNumber'=>$booking['roomNumber'],'bookingId'=>$bookingId,
                        'deviceId'=>$booking['smart_lock_device_id']??null,'accessMode'=>$mode,
                        'physicalKeyRef'=>$booking['physical_key_ref']?:('KEY-'.$booking['roomNumber']),'physicalKeyDisposition'=>$mode==='hybrid'?'returned':'not_required',
                        'reason'=>$reason,'source'=>'web','actor'=>tamasyaSmartLockActorSnapshot($loggedInStaff)
                    ]);
                    $pdo->prepare("UPDATE bookings SET keyControlStatus='pending_smart_revoke',keyReturnedAt=NULL,keyReturnedBy=? WHERE id=?")
                        ->execute([$loggedInStaff['id'],$bookingId]);
                    $pdo->prepare("UPDATE room_access_control SET physical_key_status='secured',current_booking_id=?,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                        ->execute([$bookingId,$loggedInStaff['id'],$booking['roomNumber']]);
                    insertRoomKeyEvent($pdo,$loggedInStaff,$booking['roomNumber'],$bookingId,'smart_revoke_queued',$mode,$booking['physical_key_ref']??null,$booking['smartLockCodeLast4']??null,'pending',$reason);
                }else{
                    $pdo->prepare("UPDATE bookings SET keyControlStatus='returned',keyReturnedAt=CURRENT_TIMESTAMP,keyReturnedBy=?,smartLockCodeHash=NULL,smartLockCodeLast4=NULL,smartLockValidFrom=NULL,smartLockValidUntil=NULL WHERE id=?")
                        ->execute([$loggedInStaff['id'],$bookingId]);
                    $pdo->prepare("UPDATE room_access_control SET physical_key_status='secured',current_booking_id=NULL,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                        ->execute([$loggedInStaff['id'],$booking['roomNumber']]);
                    insertRoomKeyEvent($pdo,$loggedInStaff,$booking['roomNumber'],$bookingId,'returned','physical',$booking['physical_key_ref']??null,null,'returned',$reason);
                }
                $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$afterStmt->execute([$bookingId]);$afterBooking=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$booking['roomNumber'],'key-return');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengembalikan akses kamar','booking',$bookingId,$beforeBooking,$afterBooking,'web',[
                    'smartLockJobId'=>$jobId,'accessMode'=>$mode,'externalStatus'=>$jobId?'pending':'not_required','roomProjection'=>$roomProjection
                ]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($jobId!==null)$bridgeResult=tamasyaSafelyProcessSmartLockJob($pdo,$jobId,$loggedInStaff,'web');
                $confirmed=$jobId===null || (($bridgeResult['status']??'')==='completed');
                echo json_encode([
                    'success'=>true,'bridge'=>$bridgeResult,'pendingExternalConfirmation'=>!$confirmed,
                    'message'=>$confirmed?'Pengembalian kunci/pencabutan PIN telah terkonfirmasi.':'Pengembalian fisik tercatat, tetapi pencabutan smart-lock masih menunggu konfirmasi.',
                    'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)
                ]);break;
            } elseif ($command === 'room-key-recovered') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist']);
                $room=trim((string)($input['roomNumber']??''));$reason=trim((string)($input['reason']??''));
                if($room==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
                if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Catatan penemuan/pengamanan kunci minimal 5 karakter.');
                $pdo->beginTransaction();
                $roomStmt=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomStmt->execute([$room]);if(!$roomStmt->fetchColumn())throw new RuntimeException('Kamar tidak ditemukan.');
                $activeStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");$activeStmt->execute([$room]);$activeBookingId=(string)($activeStmt->fetchColumn()?:'');
                if($activeBookingId!=='')throw new RuntimeException('Kamar masih memiliki booking aktif. Gunakan workflow pengembalian kunci pada booking tersebut.');
                $accessStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");$accessStmt->execute([$room]);$beforeAccess=$accessStmt->fetch(PDO::FETCH_ASSOC);
                if(!$beforeAccess)throw new RuntimeException('Register akses kamar tidak ditemukan.');
                $keyStatus=strtolower(trim((string)($beforeAccess['physical_key_status']??'secured')));
                if($keyStatus==='secured'){
                    $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$room,'room-key-recovered-idempotent');
                    tamasyaFinancialCommit($pdo);
                    echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Kunci kamar sudah tercatat aman/secured. Proyeksi kamar telah direkonsiliasi.','roomProjection'=>$roomProjection,'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);
                    break;
                }
                if(!in_array($keyStatus,['issued','missing','override'],true))throw new RuntimeException('Status register kunci tidak dapat diselesaikan melalui workflow ini.');
                $pdo->prepare("UPDATE room_access_control SET physical_key_status='secured',current_booking_id=NULL,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                    ->execute([(string)$loggedInStaff['id'],$room]);
                $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE acknowledged_at IS NULL AND source=?")
                    ->execute([(string)$loggedInStaff['id'],'key-missing:'.$room]);
                insertRoomKeyEvent($pdo,$loggedInStaff,$room,null,'physical_key_recovered',(string)($beforeAccess['access_mode']??'physical'),$beforeAccess['physical_key_ref']??('KEY-'.$room),null,'secured',$reason);
                $afterStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1");$afterStmt->execute([$room]);$afterAccess=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['room_number'=>$room,'physical_key_status'=>'secured'];
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$room,'room-key-recovered');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengamankan kembali kunci fisik kamar','room_access_control',$room,$beforeAccess,['access'=>$afterAccess,'reason'=>$reason,'roomProjection'=>$roomProjection],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Kunci fisik tercatat ditemukan/diamankan. Status kamar dihitung ulang dari seluruh blocker domain.','roomProjection'=>$roomProjection,'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);
                break;
            } elseif ($command === 'smart-lock-job-retry') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist']);
                $jobId=trim((string)($input['jobId']??$input['id']??''));
                if($jobId==='')throw new InvalidArgumentException('ID pekerjaan smart-lock wajib diisi.');
                $result=processDeferredCheckoutSmartLockJob($pdo,$jobId,$loggedInStaff,'web');
                if(!$result || ($result['status']??'')==='missing')throw new RuntimeException((string)($result['message']??'Pekerjaan smart-lock tidak ditemukan.'));
                if(($result['status']??'')==='invalid')throw new RuntimeException((string)($result['message']??'Pekerjaan smart-lock tidak dapat diproses ulang.'));
                echo json_encode(['success'=>true,'result'=>$result,'message'=>$result['message']??'Pekerjaan smart-lock diproses ulang.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;
            } elseif ($command === 'smart-lock-job-manual-complete') {
                requireCapability($loggedInStaff,'override_key_checkout',['admin','manager']);
                $jobId=trim((string)($input['jobId']??$input['id']??''));
                $reason=trim((string)($input['reason']??''));
                $result=completeSmartLockRevokeJobManually($pdo,$loggedInStaff,$jobId,$reason,'web');
                echo json_encode(['success'=>true,'result'=>$result,'message'=>$result['message']??'Pekerjaan smart-lock dikonfirmasi manual.','data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);break;
            } elseif ($command === 'late-checkout-record') {
                requireCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist']);
                $bookingId=trim((string)($input['bookingId']??''));if($bookingId==='')throw new InvalidArgumentException('ID booking wajib diisi.');
                $action=in_array($input['lateAction']??'', ['reminded','approved','charged','extended'],true)?$input['lateAction']:'reminded';
                $reason=trim((string)($input['reason']??''));$fee=max(0,(float)($input['fee']??0));
                if(in_array($action,['approved','charged','extended'],true)&&tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan late check-out minimal 5 karakter.');
                $smartLockRefreshJobId=null;$smartLockRefreshResult=null;
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND status='active' LIMIT 1 FOR UPDATE");$stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                $newCheckout=trim((string)($input['newCheckOut']??''));
                if($action==='extended'){
                    if(!validIsoDate($newCheckout) || $newCheckout<=$booking['checkOut'])throw new InvalidArgumentException('Tanggal perpanjangan harus lebih besar dari check-out lama.');
                    $stayExtension=tamasyaR3ExtendStay($pdo,$loggedInStaff,$bookingId,$newCheckout,'late-checkout');
                    $smartLockRefreshJobId=$stayExtension['smartLockRefreshJobId']??null;
                    $pdo->prepare("UPDATE bookings SET lateCheckoutStatus='extended',lateCheckoutReason=?,lateCheckoutApprovedBy=?,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource='late-checkout' WHERE id=?")
                        ->execute([$reason,$loggedInStaff['id'],$loggedInStaff['id'],$bookingId]);
                }else{
                    $pdo->prepare("UPDATE bookings SET lateCheckoutStatus=?,lateCheckoutReason=?,lateCheckoutFee=?,lateCheckoutApprovedBy=? WHERE id=?")->execute([$action,$reason,$fee,$loggedInStaff['id'],$bookingId]);
                }
                $lateFeeExtraId=null;
                if($fee>0){
                    if($action!=='charged')throw new InvalidArgumentException('Biaya late check-out hanya boleh diisi saat tindakan charged.');
                    $requestOperationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
                    if($requestOperationId==='')throw new RuntimeException('Operation ID wajib untuk biaya late check-out.');
                    $lateFeeExtraId='ex_late_'.substr(hash('sha256',$requestOperationId),0,28);
                    $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
                    $already=false;
                    foreach($extras as $extra){if((string)($extra['id']??'')===$lateFeeExtraId){$already=true;break;}}
                    if(!$already){
                        $feeDate=date('Y-m-d');
                        $feeCategory=getRoomRentalCategoryName($pdo);
                        $feeSubcategory=trim((string)($booking['roomType']??''));
                        if($feeSubcategory==='')throw new RuntimeException('Tipe kamar booking kosong. Perbaiki master/booking sebelum mencatat biaya late check-out.');
                        $rule=resolveConfiguredTaxRule($pdo,(string)($booking['bookingSource']??'Direct'),'extension',$feeDate);
                        if(empty($rule['matched'])||!is_numeric($rule['rate']??null))throw new RuntimeException('Rule PBJT perpanjangan/late check-out tidak ditemukan. Biaya dibatalkan.');
                        $feeTax=calculateInclusiveTaxBreakdown($fee,(float)$rule['rate']);
                        $extras[]=[
                            'id'=>$lateFeeExtraId,'name'=>'Biaya Late Check-out','price'=>$fee,'qty'=>1,'total'=>$fee,
                            'baseAmount'=>$feeTax['baseAmount'],'taxAmount'=>$feeTax['taxAmount'],'taxRate'=>$feeTax['taxRate'],
                            'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,'taxRuleName'=>$rule['ruleName']??null,
                            'taxKind'=>'extension','allocationType'=>'room','revenueType'=>'room',
                            'category'=>$feeCategory,'subcategory'=>$feeSubcategory,'paymentStatus'=>'unpaid',
                            'createdAt'=>date('c'),'operationId'=>$requestOperationId
                        ];
                        $oldGross=max(0.0,(float)($booking['totalAmount']??0));
                        $oldVat=max(0.0,(float)($booking['vatAmount']??0));
                        $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
                        $newRate=$oldGross<=0.0001?(float)$feeTax['taxRate']:(($oldRate!==null&&abs($oldRate-(float)$feeTax['taxRate'])<0.0001)?$oldRate:null);
                        $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=ROUND(COALESCE(totalAmount,0)+?,2),vatAmount=ROUND(COALESCE(vatAmount,0)+?,2),vatRate=?,paymentStatus=IF(paymentStatus='paid','partial',paymentStatus),version=COALESCE(version,0)+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource='late-checkout' WHERE id=?")
                            ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$fee,$feeTax['taxAmount'],$newRate,$loggedInStaff['id'],$bookingId]);
                    }
                    recalculateBookingFinancials($pdo,$bookingId,true);
                    assertBookingLedgerInvariant($pdo,$bookingId,$loggedInStaff,'late-checkout',false);
                }
                $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$afterStmt->execute([$bookingId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$bookingId,'lateCheckoutStatus'=>$action];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat late check-out','booking',$bookingId,$booking,['booking'=>$after,'lateFeeExtraId'=>$lateFeeExtraId,'smartLockRefreshJobId'=>$smartLockRefreshJobId],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($smartLockRefreshJobId){
                    try{$smartLockRefreshResult=processSmartLockBridgeJobById($pdo,(string)$smartLockRefreshJobId,$loggedInStaff,'late-checkout-refresh');}
                    catch(Throwable $smartLockError){$smartLockRefreshResult=['jobId'=>$smartLockRefreshJobId,'status'=>'error','message'=>clientExceptionMessage('Refresh smart-lock late check-out belum selesai',$smartLockError)];}
                }
                broadcastTelegramNotification($pdo,"⏰ *LATE CHECK-OUT*\nKamar: *{$booking['roomNumber']}*\nTamu: *{$booking['guestName']}*\nTindakan: *".strtoupper($action)."*\nBiaya: *Rp ".number_format($fee,0,',','.')."*\nPetugas: *".currentStaffLabel($loggedInStaff)."*",false);
            } elseif ($command === 'daily-summary') {
        requireCapability($loggedInStaff,'daily_summary',['admin','manager','receptionist']);
        $sumDate = trim((string)($input['date'] ?? date('Y-m-d')));
        if (!function_exists('tamasyaDailySummary')) {
            $__f = tamasyaResolveDomainSupportFile('110_daily_summary.php');
            if ($__f) { require_once $__f; } unset($__f);
        }
        $payload = tamasyaDailySummary($pdo, $sumDate);
        echo json_encode($payload);
        exit;
} elseif ($command === 'night-audit-start') {
                requireNightAuditCapability($loggedInStaff);
                $pdo->beginTransaction();
                tamasyaRequireNightAuditEnabled($pdo);
                $openShift=null;$requestedShiftId=trim((string)($input['shiftSessionId']??''));
                if($requestedShiftId!==''){
                    $q=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");
                    $q->execute([$requestedShiftId]);$openShift=$q->fetch(PDO::FETCH_ASSOC)?:null;
                    if(!$openShift)throw new RuntimeException('Shift yang dipilih tidak ditemukan atau sudah ditutup.');
                }
                if(!$openShift && ($loggedInStaff['role']??'')!=='keamanan')$openShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'],true);
                if(!$openShift){
                    // Baris lama tanpa shift_time diklasifikasikan dengan batas jam
                    // shift yang dapat diatur properti, bukan pagar 18:00-05:00 mati.
                    $boundaries=tamasyaShiftBoundariesMinutes(getOperationalSettings($pdo));
                    $q=$pdo->prepare("SELECT * FROM shift_sessions WHERE status='open' AND (LOWER(COALESCE(shift_time,'')) IN ('malam','all') OR ((shift_time IS NULL OR shift_time='') AND (HOUR(opened_at)*60+MINUTE(opened_at)>=? OR HOUR(opened_at)*60+MINUTE(opened_at)<?))) ORDER BY opened_at DESC LIMIT 1 FOR UPDATE");
                    $q->execute([$boundaries['malam_start'],$boundaries['day_start']]);
                    $openShift=$q->fetch(PDO::FETCH_ASSOC)?:null;
                }
                if(!$openShift)throw new RuntimeException('Tidak ada shift malam terbuka yang dapat dikaitkan dengan Night Audit.');
                if(!isNightShiftRow($openShift,tamasyaShiftBoundariesMinutes(getOperationalSettings($pdo))))throw new RuntimeException('Night Audit hanya dapat dikaitkan dengan shift Malam atau Satu Hari Penuh.');

                $existing=$pdo->prepare("SELECT * FROM night_audit_runs WHERE shift_session_id=? ORDER BY started_at DESC LIMIT 1 FOR UPDATE");
                $existing->execute([$openShift['id']]);$latest=$existing->fetch(PDO::FETCH_ASSOC)?:null;
                if($latest && ($latest['status']??'')==='completed')throw new RuntimeException('Night Audit untuk shift ini sudah selesai. Silakan tutup shift kas.');
                $before=$latest && ($latest['status']??'')==='open' ? $latest : null;
                $auditId=(string)($before['id']??'');
                if($auditId===''){
                    $auditId=generateServerId('nightaudit');
                    $pdo->prepare("INSERT INTO night_audit_runs (id,audit_date,shift_session_id,staff_id,staff_name,status,total_rooms,started_at) VALUES (?,CURDATE(),?,?,?,'open',0,CURRENT_TIMESTAMP)")
                        ->execute([$auditId,$openShift['id'],$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
                }
                $repair=reconcileNightAuditItems($pdo,$auditId,true);
                $afterStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE id=? LIMIT 1");$afterStmt->execute([$auditId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$auditId,'status'=>'open','shift_session_id'=>$openShift['id']];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$before?'Merekonsiliasi night audit terbuka':'Memulai night audit','night_audit_run',$auditId,$before,['run'=>$after,'repair'=>$repair],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'night-audit-refresh') {
                requireNightAuditCapability($loggedInStaff);
                $auditId=trim((string)($input['auditId']??''));if($auditId==='')throw new InvalidArgumentException('ID night audit wajib diisi.');
                $pdo->beginTransaction();
                tamasyaRequireNightAuditEnabled($pdo);
                $repair=reconcileNightAuditItems($pdo,$auditId,true);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyegarkan master kamar dan booking Night Audit','night_audit_run',$auditId,null,$repair,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'night-audit-check-room') {
                requireNightAuditCapability($loggedInStaff);
                $auditId=trim((string)($input['auditId']??''));$room=trim((string)($input['roomNumber']??''));if($auditId===''||$room==='')throw new InvalidArgumentException('Night audit dan nomor kamar wajib diisi.');
                $occupancy=in_array($input['physicalOccupancy']??'', ['occupied','vacant','unknown'],true)?$input['physicalOccupancy']:'unknown';
                $key=in_array($input['observedKeyStatus']??'', ['issued','secured','missing','unknown'],true)?$input['observedKeyStatus']:'unknown';
                $pdo->beginTransaction();
                tamasyaRequireNightAuditEnabled($pdo);
                reconcileNightAuditItems($pdo,$auditId,true);
                $stmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE audit_id=? AND room_number=? LIMIT 1 FOR UPDATE");$stmt->execute([$auditId,$room]);$item=$stmt->fetch(PDO::FETCH_ASSOC);if(!$item)throw new RuntimeException('Item night audit tidak ditemukan setelah rekonsiliasi master kamar.');
                $snapshot=getNightAuditRoomSnapshot($pdo,$room,true);
                $registeredKey=(string)($snapshot['registered_key_status']??'secured');
                $accessMode=in_array((string)($snapshot['access_mode']??'physical'),['physical','smart','hybrid'],true)?(string)$snapshot['access_mode']:'physical';
                $physicalKeyRequired=in_array($accessMode,['physical','hybrid'],true);
                $isComplete=$occupancy!=='unknown'&&(!$physicalKeyRequired||$key!=='unknown');
                $types=[];$hasBooking=!empty($snapshot['active_booking_id']);
                if($isComplete){
                    if((int)($snapshot['active_booking_count']??0)>1)$types[]='multiple_active_bookings';
                    if($occupancy==='occupied'&&!$hasBooking)$types[]='occupied_without_booking';
                    if($occupancy==='vacant'&&$hasBooking)$types[]='booking_but_room_vacant';
                    if($physicalKeyRequired){
                        if($key==='issued'&&!$hasBooking)$types[]='key_out_without_booking';
                        if($key==='secured'&&$hasBooking)$types[]='booking_without_key_out';
                        if($key==='missing')$types[]='physical_key_missing';
                        if(in_array($key,['issued','secured'],true)&&$registeredKey!==$key)$types[]='key_register_mismatch';
                    }elseif($hasBooking && !in_array((string)($snapshot['booking_key_status']??'not_issued'),['issued','pending_smart_issue'],true)){
                        $types[]='smart_access_not_active';
                    }
                }
                $discrepancy=$isComplete?(implode(',',array_values(array_unique($types)))?:null):null;
                $resolutionStatus=$isComplete&&$discrepancy===null?'resolved':'open';
                // Smart-only rooms do not require a physical-key observation. Store an explicit
                // not_required marker so the UI completion counter and the backend agree.
                $storedKeyStatus=$physicalKeyRequired?$key:'not_required';
                $update=$pdo->prepare("UPDATE night_audit_items SET system_room_status=?,active_booking_id=?,guest_name=?,physical_occupancy=?,observed_key_status=?,discrepancy_type=?,resolution_status=?,notes=?,checked_by=?,checked_at=CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END,resolved_by=NULL,resolved_at=NULL WHERE audit_id=? AND room_number=?");
                $update->execute([$snapshot['system_room_status'],$snapshot['active_booking_id'],$snapshot['guest_name'],$occupancy,$storedKeyStatus,$discrepancy,$resolutionStatus,trim((string)($input['notes']??'')),$loggedInStaff['id'],$isComplete?1:0,$auditId,$room]);
                $alertId='alert_night_'.hash('sha256',$auditId.'|'.$room);
                if($discrepancy){
                    $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'critical','night-audit','Ketidaksesuaian kamar pada night audit',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                        ->execute([$alertId,"Kamar {$room}: {$discrepancy}. Dilaporkan oleh ".currentStaffLabel($loggedInStaff)]);
                }else{
                    $pdo->prepare("UPDATE system_alerts SET acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$alertId]);
                }
                $counts=reconcileNightAuditItems($pdo,$auditId,false);
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$room,'night-audit-check');
                $afterStmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE audit_id=? AND room_number=? LIMIT 1");$afterStmt->execute([$auditId,$room]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['audit_id'=>$auditId,'room_number'=>$room,'discrepancy_type'=>$discrepancy];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$isComplete?'Memeriksa kamar pada night audit':'Menyimpan pemeriksaan sebagian kamar','night_audit_item',(string)($item['id']??($auditId.'_'.$room)),$item,['item'=>$after,'counts'=>$counts,'roomProjection'=>$roomProjection],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($discrepancy)broadcastTelegramNotification($pdo,"🚨 *NIGHT AUDIT DISCREPANCY*
Kamar: *{$room}*
Temuan: *{$discrepancy}*
Petugas: *".currentStaffLabel($loggedInStaff)."*",false);
            } elseif ($command === 'night-audit-resolve') {
                requireCapability($loggedInStaff,'resolve_night_audit',['admin','manager']);
                $id=trim((string)($input['id']??''));$reason=trim((string)($input['reason']??''));if($id==='')throw new InvalidArgumentException('ID item night audit wajib diisi.');if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan penyelesaian minimal 5 karakter.');
                $pdo->beginTransaction();
                tamasyaRequireNightAuditEnabled($pdo);
                $lookup=$pdo->prepare("SELECT audit_id FROM night_audit_items WHERE id=? LIMIT 1");$lookup->execute([$id]);$auditId=(string)($lookup->fetchColumn()?:'');if($auditId==='')throw new RuntimeException('Item night audit tidak ditemukan.');
                reconcileNightAuditItems($pdo,$auditId,true);
                $stmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Item night audit tidak ditemukan.');
                if(empty($before['discrepancy_type']))throw new RuntimeException('Item ini tidak memiliki temuan yang perlu diselesaikan.');
                if(str_contains((string)$before['discrepancy_type'],'source_state_changed'))throw new RuntimeException('Data kamar atau booking berubah. Kamar wajib diperiksa ulang sebelum temuan dapat diselesaikan.');
                if(($before['resolution_status']??'')==='resolved')throw new RuntimeException('Temuan night audit sudah diselesaikan.');
                $pdo->prepare("UPDATE night_audit_items SET resolution_status='resolved',notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE ' | ' END,'RESOLUSI: ',?),resolved_by=?,resolved_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$reason,$loggedInStaff['id'],$id]);
                $alertId='alert_night_'.hash('sha256',(string)$before['audit_id'].'|'.(string)$before['room_number']);
                $pdo->prepare("UPDATE system_alerts SET acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$alertId]);
                $counts=reconcileNightAuditItems($pdo,$auditId,false);
                $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$before['room_number'],'night-audit-resolve');
                $afterStmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'resolution_status'=>'resolved'];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyelesaikan temuan night audit','night_audit_item',$id,$before,['item'=>$after,'counts'=>$counts,'roomProjection'=>$roomProjection],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'night-audit-finalize') {
                requireNightAuditCapability($loggedInStaff);
                $auditId=trim((string)($input['auditId']??''));if($auditId==='')throw new InvalidArgumentException('ID night audit wajib diisi.');
                $pdo->beginTransaction();
                tamasyaRequireNightAuditEnabled($pdo);
                $runStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE id=? LIMIT 1 FOR UPDATE");$runStmt->execute([$auditId]);$before=$runStmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Night audit tidak ditemukan.');if(($before['status']??'')==='completed')throw new RuntimeException('Night audit sudah diselesaikan.');
                $counts=reconcileNightAuditItems($pdo,$auditId,true);
                if((int)$counts['invalidated']>0)throw new RuntimeException($counts['invalidated'].' kamar berubah di sistem setelah diperiksa. Periksa ulang kamar tersebut sebelum finalisasi.');
                if((int)$counts['total']===0)throw new RuntimeException('Night audit tidak mempunyai item kamar.');
                if((int)$counts['checked']<(int)$counts['total'])throw new RuntimeException('Semua kamar harus diperiksa lengkap (kondisi fisik dan kunci) sebelum Night Audit diselesaikan.');
                if((int)$counts['unresolved']>0)throw new RuntimeException('Semua temuan Night Audit harus diselesaikan sebelum finalisasi.');
                $pdo->prepare("UPDATE night_audit_runs SET status='completed',checked_rooms=?,occupancy_discrepancies=?,key_discrepancies=?,notes=?,completed_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([(int)$counts['checked'],(int)$counts['discrepancies'],(int)$counts['keyDiscrepancies'],trim((string)($input['notes']??'')),$auditId]);
                if(!empty($before['shift_session_id']))$pdo->prepare("UPDATE shift_sessions SET night_audit_id=?,occupancy_discrepancy_count=?,key_discrepancy_count=? WHERE id=?")
                    ->execute([$auditId,(int)$counts['discrepancies'],(int)$counts['keyDiscrepancies'],$before['shift_session_id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE id=? LIMIT 1");$afterStmt->execute([$auditId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$auditId,'status'=>'completed'];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyelesaikan night audit','night_audit_run',$auditId,$before,['run'=>$after,'counts'=>$counts],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'backup-record') {
                requireCapability($loggedInStaff, 'manage_backup', ['admin']);
                $id=generateServerId('backup');$status=strtolower(trim((string)($input['status']??'completed')));if(!in_array($status,['completed','failed','partial','running'],true))throw new InvalidArgumentException('Status backup tidak valid.');
                $fileName=trim((string)($input['fileName']??''));
                $sizeBytes=max(0,(int)($input['sizeBytes']??0));
                $checksum=strtolower(trim((string)($input['checksum']??'')));
                if($status==='completed'){
                    if($fileName==='' || $sizeBytes<=0 || !preg_match('/^[a-f0-9]{64}$/',$checksum)){
                        throw new InvalidArgumentException('Backup berstatus completed wajib mempunyai nama file, sizeBytes > 0, dan SHA256 64 hex. Catatan manual tanpa bukti tidak boleh dianggap backup selesai.');
                    }
                }elseif($checksum!=='' && !preg_match('/^[a-f0-9]{64}$/',$checksum)){
                    throw new InvalidArgumentException('Checksum backup harus SHA256 64 hex bila diisi.');
                }
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO backup_runs (id,backup_type,file_name,status,size_bytes,checksum,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")->execute([$id,substr((string)($input['backupType']??'manual'),0,50),$fileName?:null,$status,$sizeBytes?:null,$checksum?:null,trim((string)($input['notes']??'')),$loggedInStaff['id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM backup_runs WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>$status];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat hasil backup','backup_run',$id,null,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } elseif ($command === 'backup-restore-tested') {
                requireCapability($loggedInStaff, 'manage_backup', ['admin']);
                $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID backup wajib diisi.');
                // A readable file is NOT a restore test. Marking restore-tested is
                // deliberately high-friction and requires evidence from an actual
                // isolated restore drill so dashboards cannot create false confidence.
                $confirmation=trim((string)($input['confirmation']??''));
                $restoredDatabase=trim((string)($input['restoredDatabase']??''));
                $verifiedChecksum=strtolower(trim((string)($input['verifiedChecksum']??'')));
                $verificationNotes=trim((string)($input['verificationNotes']??''));
                $restoreEvidenceToken=trim((string)($input['restoreEvidenceToken']??''));
                if(!hash_equals('RESTORE-VERIFIED',$confirmation))throw new InvalidArgumentException('Konfirmasi wajib persis RESTORE-VERIFIED setelah uji restore nyata ke database terisolasi.');
                if($restoreEvidenceToken==='')throw new InvalidArgumentException('restoreEvidenceToken dari restore_drill_verify.php wajib diisi. Attestation manual saja tidak cukup.');
                if($restoredDatabase==='' || strlen($restoredDatabase)>128)throw new InvalidArgumentException('Nama database terisolasi hasil restore wajib diisi.');
                if($verificationNotes==='' || tamasyaStringLength($verificationNotes)<12)throw new InvalidArgumentException('Catatan verifikasi restore minimal 12 karakter.');
                $currentDb=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
                if($currentDb!=='' && strcasecmp($currentDb,$restoredDatabase)===0)throw new RuntimeException('Database uji restore harus terisolasi dan tidak boleh sama dengan database operasional aktif.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM backup_runs WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Catatan backup tidak ditemukan.');
                if(($before['status']??'')!=='completed')throw new RuntimeException('Uji pemulihan hanya dapat dicatat untuk backup yang selesai.');
                $storedChecksum=strtolower(trim((string)($before['checksum']??'')));
                $storedSize=(int)($before['size_bytes']??0);
                if(!preg_match('/^[a-f0-9]{64}$/',$storedChecksum) || $storedSize<=0)throw new RuntimeException('Catatan backup completed belum mempunyai SHA256/ukuran restore-grade yang valid. Buat ulang backup dengan backup_now.php atau maintenance cron.');
                if(!preg_match('/^[a-f0-9]{64}$/',$verifiedChecksum))throw new RuntimeException('SHA256 file backup yang benar-benar direstore wajib diisi (64 hex).');
                if(!hash_equals($storedChecksum,$verifiedChecksum))throw new RuntimeException('Checksum file yang diuji tidak cocok dengan catatan backup.');
                require_once dirname(__DIR__,2).'/restore_evidence_support.php';
                require_once dirname(__DIR__,2).'/baseline_support.php';
                $manifest=tamasyaCanonicalBaselineManifest();
                $currentIdentity=tamasyaDatabasePropertyIdentity($pdo,true);
                $currentDbIdentity=is_array($currentIdentity['database']??null)?$currentIdentity['database']:[];
                $evidenceClaims=tamasyaRestoreEvidenceVerify($restoreEvidenceToken,[
                    'propertyId'=>(string)($currentDbIdentity['property_id']??''),
                    'backupSha256'=>$verifiedChecksum,
                    'restoredDatabase'=>$restoredDatabase,
                    'canonicalSqlSha256'=>(string)($manifest['sqlSha256']??''),
                ],172800);
                $currentCompany=strtolower(trim((string)($currentDbIdentity['company_id']??'')));
                $evidenceCompany=strtolower(trim((string)($evidenceClaims['companyId']??'')));
                if(!hash_equals($currentCompany,$evidenceCompany))throw new RuntimeException('Restore evidence berasal dari company affiliation yang berbeda.');
                $evidence='RESTORE DRILL VERIFIED: db='.$restoredDatabase.'; checksum='.$verifiedChecksum.'; evidenceIssuedAt='.(int)($evidenceClaims['issuedAt']??0).'; notes='.$verificationNotes;
                $priorNotes=trim((string)($before['notes']??''));
                $updatedNotes=substr(($priorNotes!==''?$priorNotes." | ":'').$evidence,0,4000);
                $pdo->prepare("UPDATE backup_runs SET restore_tested_at=CURRENT_TIMESTAMP,restore_tested_by=?,notes=? WHERE id=?")->execute([$loggedInStaff['id'],$updatedNotes,$id]);
                $afterStmt=$pdo->prepare("SELECT * FROM backup_runs WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'restore_tested_by'=>$loggedInStaff['id']];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat uji pemulihan backup nyata','backup_run',$id,$before,$after,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
            } else {
                http_response_code(400);
                echo json_encode(['success'=>false,'error'=>'Command operations-center tidak dikenal.']);
                break;
            }
            echo json_encode(['success'=>true,'data'=>getRoleScopedOperationsData($pdo,$loggedInStaff)]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if($command==='shift-cash-revise')tamasyaApplyExceptionHttpStatus($e,500);else http_response_code(400);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Operasi gagal', $e)]);
        }
        break;

    case 'chat-messages':
        $legacyStaffChatEnabled=filter_var(getenv('INTERNAL_STAFF_HELP_CHAT_ENABLED')?:'0',FILTER_VALIDATE_BOOLEAN);
        if(!$legacyStaffChatEnabled){
            http_response_code(410);
            echo json_encode(['success'=>false,'error'=>'Chat internal legacy dinonaktifkan. Gunakan menu Support untuk chat website atau Telegram.']);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $sender = strtolower(trim((string)($input['sender'] ?? 'guest')));
        $msg = trim((string)($input['message'] ?? $input['text'] ?? ''));

        if (!in_array($sender, ['guest','staff'], true)) {
            http_response_code(422);
            echo json_encode(["success" => false, "message" => "Jenis pengirim chat tidak valid."]);
            break;
        }
        if ($msg === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Pesan tidak boleh kosong!"]);
            break;
        }
        $messageLength = tamasyaStringLength($msg);
        if ($messageLength > 2000) {
            http_response_code(413);
            echo json_encode(["success" => false, "message" => "Pesan terlalu panjang. Maksimal 2.000 karakter."]);
            break;
        }

        try {
            $msgId = generateServerId('chat');
            $now = date("Y-m-d H:i:s");
            $chatStaffId = trim((string)($loggedInStaff['id'] ?? ''));
            if ($chatStaffId === '') throw new RuntimeException('Session staf untuk chat tidak valid.');

            // Semua pembacaan grounding dan panggilan AI dilakukan tanpa
            // transaksi terbuka. Transaksi database baru dimulai setelah
            // side effect eksternal selesai agar lock tidak ditahan selama
            // network timeout atau retry provider.
            $replyText = "";

            if ($sender === 'guest') {
                // Ambil konfigurasi untuk Gemini API Key
                $stmtConf = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
                $confRow = $stmtConf->fetch();
                $geminiKey = $confRow ? trim(decryptStoredSecret($confRow['gemini_api_key'] ?? '')) : '';

                // Ambil daftar kamar & harga terupdate secara dinamis untuk pencocokan offline / grounding AI
                $rooms_stmt = $pdo->query("SELECT DISTINCT type, price FROM rooms ORDER BY price ASC");
                $rooms_list = $rooms_stmt->fetchAll();
                
                $roomPricesText = "";
                $roomPricesArr = [];
                foreach ($rooms_list as $r) {
                    $roomPricesArr[] = htmlspecialchars($r['type']) . " (Rp " . number_format((int)$r['price'], 0, ',', '.') . "/malam)";
                }
                if (!empty($roomPricesArr)) {
                    $roomPricesText = implode(", ", $roomPricesArr);
                } else {
                    $roomPricesText = "Standard (Rp 350.000/malam), Deluxe (Rp 550.000/malam), Suite (Rp 950.000/malam), Family (Rp 1.200.000/malam)";
                }

                // Ambil rekening bank yang aktif secara dinamis
                $bank_stmt = $pdo->query("SELECT name, accountNumber, accountHolder FROM bank_accounts WHERE isActive = 1");
                $bank_list = $bank_stmt->fetchAll();
                $banks_text = "";
                foreach ($bank_list as $b) {
                    $banks_text .= "- Bank " . htmlspecialchars($b['name']) . ": " . htmlspecialchars($b['accountNumber']) . " a/n " . htmlspecialchars($b['accountHolder']) . "\n";
                }

                $hotelName=tamasyaPropertyDisplayName($pdo);

                if (!empty($geminiKey)) {
                    // Panggil Gemini API secara aman untuk memberikan jawaban interaktif aseli resepsionis
                    $systemPrompt = "Anda adalah Resepsionis AI {$hotelName} yang ramah, profesional, membantu, dan berbahasa Indonesia yang baik.\n" .
                                    "Anda bertugas menjawab obrolan live chat bantuan dari tamu website yang ingin menyewa kamar, menanyakan fasilitas, atau cara pembayaran.\n\n" .
                                    "Berikut adalah Data Hotel Real-time Terbaru dari Database saat ini:\n" .
                                    "- Tipe Kamar & Tarif: " . $roomPricesText . "\n" .
                                    "- Rekening Pembayaran Aktif:\n" . (empty($banks_text) ? "- Rekening pembayaran belum dikonfigurasi. Hubungi resepsionis melalui kanal resmi.\n" : $banks_text) . "\n" .
                                    "- Fasilitas: WiFi Kecepatan Tinggi gratis di seluruh area hotel, AC, Smart TV, Shower Air Panas, perlengkapan mandi lengkap, dan sarapan pagi gratis (untuk tipe Deluxe ke atas).\n\n" .
                                    "Jawab pertanyaan tamu dengan ramah, sopan, singkat (cukup 1 sampai 2 kalimat saja), dan langsung menjawab poin pertanyaan mereka.";

                    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
                    $payload = [
                        "contents" => [
                            [
                                "role" => "user",
                                "parts" => [
                                    ["text" => $systemPrompt . "\n\nPertanyaan Tamu: " . $msg]
                                ]
                            ]
                        ],
                        "generationConfig" => [
                            "temperature" => 0.6
                        ]
                    ];

                    $geminiApiKeyHeader = preg_replace('/[\r\n]+/', '', $geminiKey);
                    $geminiResRaw = sendHttpPost($url, $payload, ['x-goog-api-key: '.$geminiApiKeyHeader]);
                    $geminiRes = json_decode($geminiResRaw, true);
                    if ($geminiRes && isset($geminiRes['candidates'][0]['content']['parts'][0]['text'])) {
                        $replyText = trim($geminiRes['candidates'][0]['content']['parts'][0]['text']);
                    }
                }

                // Fallback otomatis cerdas jika Gemini tidak diisi atau mengalami kendala
                if (empty($replyText)) {
                    $replyText = "Terima kasih telah menghubungi {$hotelName}. Staf kami akan segera merespon pesan Anda secara langsung.";
                    
                    $lower = strtolower($msg);
                    if (strpos($lower, 'harga') !== false || strpos($lower, 'tarif') !== false || strpos($lower, 'berapa') !== false || strpos($lower, 'kamar') !== false) {
                        $replyText = "Kamar {$hotelName} yang tersedia saat ini: " . $roomPricesText . ". Semua tipe kamar sudah termasuk layanan kebersihan harian.";
                    } elseif (strpos($lower, 'fasilitas') !== false || strpos($lower, 'wifi') !== false || strpos($lower, 'wi-fi') !== false || strpos($lower, 'sarapan') !== false) {
                        $replyText = "Fasilitas kami meliputi WiFi kecepatan tinggi gratis di seluruh area hotel, AC, Smart TV, air panas, serta sarapan pagi gratis untuk tipe Deluxe, Suite, dan Family.";
                    } elseif (strpos($lower, 'rekening') !== false || strpos($lower, 'bayar') !== false || strpos($lower, 'transfer') !== false || strpos($lower, 'no rek') !== false) {
                        $replyText = "Pembayaran dapat dilakukan melalui transfer bank resmi kami berikut:\n" . (empty($banks_text) ? "Rekening pembayaran belum dikonfigurasi. Hubungi resepsionis melalui kanal resmi." : $banks_text);
                    }
                }

            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO chat_messages (id, staff_id, sender, text, timestamp) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$msgId, $chatStaffId, $sender, $msg, $now]);
            $replyId = null;
            if ($sender === 'guest') {
                // Simpan balasan staf/AI bersama pesan asal dalam satu commit.
                $replyId = generateServerId('chat_reply');
                $stmt = $pdo->prepare("INSERT INTO chat_messages (id, staff_id, sender, text, timestamp) VALUES (?, ?, 'staff', ?, ?)");
                $stmt->execute([$replyId, $chatStaffId, $replyText, date("Y-m-d H:i:s", time() + 1)]);
            }
            writeRequiredEnterpriseAudit(
                $pdo,
                $loggedInStaff,
                'Menyimpan percakapan bantuan aplikasi',
                'chat_conversation',
                $msgId,
                null,
                [
                    'sender'=>$sender,
                    'messageHash'=>hash('sha256',$msg),
                    'messageLength'=>$messageLength,
                    'replyId'=>$replyId,
                    'replyHash'=>$replyId !== null ? hash('sha256',$replyText) : null,
                    'replyLength'=>$replyId !== null ? tamasyaStringLength($replyText) : 0,
                ]
            );
            tamasyaFinancialCommit($pdo);

            // Help Chat di dalam aplikasi bersifat pribadi per staf. Pesan tidak
            // disiarkan ke Telegram agar pertanyaan internal tidak bocor ke akun lain.
            $stmt = $pdo->prepare("SELECT id,staff_id,sender,text,timestamp FROM chat_messages WHERE staff_id=? ORDER BY timestamp DESC LIMIT 100");
            $stmt->execute([$chatStaffId]);
            $scopedChatMessages = array_reverse($stmt->fetchAll() ?: []);
            foreach ($scopedChatMessages as &$scopedMessage) {
                $scopedMessage['message'] = $scopedMessage['text'] ?? '';
            }
            unset($scopedMessage);

            echo json_encode([
                "success" => true,
                "chatMessages" => $scopedChatMessages
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal mengirim chat", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/notifications/read ATAU api.php?action=notifications-read
    // Tandai Notifikasi Telah Dibaca
    // ----------------------------------------------------------------
    case 'notifications-read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $id = trim((string)($input['id'] ?? ''));
        $staffId = (string)($loggedInStaff['id'] ?? '');

        try {
            if ($staffId === '') throw new RuntimeException('Session staf tidak valid.');
            $canFinanceNotifications=tamasyaCanSeeFinancialNotifications($loggedInStaff)?1:0;
            $canRoomNotifications=tamasyaCanSeeRoomNotifications($loggedInStaff)?1:0;
            $canPublicReservationNotifications=($canFinanceNotifications===1||$canRoomNotifications===1)?1:0;
            $canPublicSupportNotifications=tamasyaCanSeePublicSupportNotifications($loggedInStaff)?1:0;
            $visibilitySql="(?=1 OR LOWER(COALESCE(n.type,'system'))<>'finance') AND (?=1 OR LOWER(COALESCE(n.type,'system'))<>'booking') AND (?=1 OR LOWER(COALESCE(n.type,'system'))<>'public_reservation') AND (?=1 OR LOWER(COALESCE(n.type,'system'))<>'public_support')";
            $pdo->beginTransaction();
            $beforeSql="SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.staff_id=? WHERE nr.notification_id IS NULL AND {$visibilitySql}".($id!==''?" AND n.id=?":"");
            $beforeCountStmt=$pdo->prepare($beforeSql);
            $beforeParams=[$staffId,$canFinanceNotifications,$canRoomNotifications,$canPublicReservationNotifications,$canPublicSupportNotifications];
            if($id!=='')$beforeParams[]=$id;
            $beforeCountStmt->execute($beforeParams);
            $beforeUnread=(int)$beforeCountStmt->fetchColumn();
            if ($id !== '') {
                $visibleStmt=$pdo->prepare("SELECT id FROM notifications n WHERE n.id=? AND {$visibilitySql} LIMIT 1 FOR UPDATE");
                $visibleStmt->execute([$id,$canFinanceNotifications,$canRoomNotifications,$canPublicReservationNotifications,$canPublicSupportNotifications]);
                if(!$visibleStmt->fetchColumn())throw new RuntimeException('Notifikasi tidak ditemukan atau tidak tersedia untuk role ini.');
                $stmt = $pdo->prepare("INSERT INTO notification_reads (notification_id, staff_id, read_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)");
                $stmt->execute([$id,$staffId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO notification_reads (notification_id, staff_id, read_at)
                    SELECT n.id, ?, NOW() FROM notifications n WHERE {$visibilitySql}
                    ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)");
                $stmt->execute([$staffId,$canFinanceNotifications,$canRoomNotifications,$canPublicReservationNotifications,$canPublicSupportNotifications]);
            }
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menandai notifikasi dibaca','notification_reads',$id!==''?$id:$staffId,['unreadCount'=>$beforeUnread],['unreadCount'=>0,'scope'=>$id!==''?'single':'all'],'web');
            tamasyaFinancialCommit($pdo);

            echo json_encode([
                "success" => true,
                "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal memperbarui notifikasi", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/bank-accounts ATAU api.php?action=bank-accounts
    // Sinkronisasi/Update Rekening Bank & EDC
    // ----------------------------------------------------------------
    case 'bank-accounts':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $bankAccounts = $input['bankAccounts'] ?? null;
        if (!is_array($bankAccounts)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "bankAccounts harus berupa array!"]);
            break;
        }

        try {
            $pdo->beginTransaction();
            if(count($bankAccounts)>100)throw new InvalidArgumentException('Maksimal 100 rekening dapat disimpan sekaligus.');
            $before=$pdo->query("SELECT * FROM bank_accounts ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC)?:[];
            $seen=[];$incomingIds=[];
            $stmt = $pdo->prepare("INSERT INTO bank_accounts (id, name, accountNumber, accountHolder, type, isActive) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name),accountNumber=VALUES(accountNumber),accountHolder=VALUES(accountHolder),type=VALUES(type),isActive=VALUES(isActive)");
            foreach ($bankAccounts as $ba) {
                if(!is_array($ba))throw new InvalidArgumentException('Format rekening tidak valid.');
                $id = trim((string)($ba['id'] ?? ''));if($id==='')$id=generateServerId('ba');
                if(!preg_match('/^[A-Za-z0-9._:-]{2,50}$/',$id)||isset($seen[$id]))throw new InvalidArgumentException('ID rekening tidak valid atau duplikat.');
                $seen[$id]=true;$incomingIds[]=$id;
                $name = trim((string)($ba['name'] ?? ''));
                if($name===''||tamasyaStringLength($name)>100)throw new InvalidArgumentException('Nama rekening wajib diisi maksimal 100 karakter.');
                $accountNumber = trim((string)($ba['accountNumber'] ?? ''))?:null;
                $accountHolder = trim((string)($ba['accountHolder'] ?? ''))?:null;
                $type = trim((string)($ba['type'] ?? 'bank'));
                if(!in_array($type,tamasyaPhysicalPaymentAccountTypes(),true))throw new InvalidArgumentException('Tipe rekening tidak valid.');
                $isActive = !empty($ba['isActive']) ? 1 : 0;

                $stmt->execute([$id, $name, $accountNumber, $accountHolder, $type, $isActive]);
            }
            if($incomingIds){
                $ph=implode(',',array_fill(0,count($incomingIds),'?'));
                $pdo->prepare("UPDATE bank_accounts SET isActive=0 WHERE id NOT IN ($ph)")->execute($incomingIds);
            }else{
                $pdo->exec("UPDATE bank_accounts SET isActive=0");
            }
            $after=$pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui rekening pembayaran','bank_accounts','all',array_map('tamasyaBankAccountAuditSnapshot',$before),array_map('tamasyaBankAccountAuditSnapshot',$after),'web');
            logActivity($pdo,'BANK_ACCOUNTS_UPDATE','Memperbarui daftar rekening pembayaran; rekening yang dihapus dari formulir dinonaktifkan agar histori transaksi tetap dapat dibaca.',(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);

            echo json_encode([
                "success" => true,
                "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal menyimpan rekening bank", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/db/reset ATAU api.php?action=db-reset
    // Reset Data Database MySQL ke Awal / Default
    // ----------------------------------------------------------------
    case 'db-reset':
        requireRoles($loggedInStaff, ['admin']);
        http_response_code(410);
        echo json_encode([
            'success'=>false,
            'error'=>'Reset database dari aplikasi dinonaktifkan karena tidak dapat menjamin penghapusan konsisten pada seluruh ledger, audit, shift, OTA, sinkronisasi, dan tabel enterprise. Gunakan backup/restore pada staging atau Pembersihan Data Masa Implementasi yang terkontrol.'
        ]);
        break;

    // ----------------------------------------------------------------
    // POST /api/email-report ATAU api.php?action=email-report
    // Kirim Laporan Keuangan ke Email (Menggunakan mail() PHP asli)
    // ----------------------------------------------------------------
    case 'email-report':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $email = strtolower(trim((string)($input['email'] ?? '')));
        $requestedMonthName = trim((string)($input['monthName'] ?? ''));
        $year = (int)($input['year'] ?? date('Y'));
        $monthMap = ['januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12];
        $monthNames = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        $month = (int)($input['month'] ?? ($monthMap[strtolower($requestedMonthName)] ?? date('n')));
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(['success'=>false, 'message'=>'Periode laporan tidak valid.']);
            break;
        }
        // Nama bulan tidak dipercaya dari payload agar subject/body tidak dapat
        // dimanipulasi dan selalu konsisten dengan angka periode yang di-query.
        $monthName = $monthNames[$month];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Alamat email penerima tidak valid."]);
            break;
        }
        $emailDisplay = tamasyaMaskEmailAddress($email);
        $emailHash = hash('sha256',$email);
        $emailOperationId = trim((string)($GLOBALS['tamasya_request_operation_id'] ?? ''));
        if ($emailOperationId === '') {
            http_response_code(428);
            echo json_encode(['success'=>false,'message'=>'Operation ID pengiriman laporan wajib tersedia.']);
            break;
        }
        $emailDeliveryId = 'email_report_'.substr(hash('sha256',$emailOperationId),0,32);
        $emailNotificationId = 'n_'.substr(hash('sha256',$emailDeliveryId),0,32);

        try {
            // Ambil konfigurasi SMTP
            $stmtConf = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmtConf->fetch();
            $smtp_host = $confRow ? trim($confRow['smtp_host'] ?? '') : '';

            // Ambil transaksi sesuai periode laporan, bukan seluruh riwayat.
            $periodPrefix = sprintf('%04d-%02d-', $year, $month);
            $stmt = $pdo->prepare("SELECT id, type, category, categoryId, categorySystemKey, subcategory, subcategoryId, subcategorySystemKey, amount, baseAmount, taxAmount, date, description, bankAccountId, transactionKind, sourceEntity, taxSnapshotStatus, updatedSource FROM transactions WHERE `date` LIKE ? ORDER BY `date` DESC");
            $stmt->execute([$periodPrefix . '%']);
            $allTx = $stmt->fetchAll();

            // Laporan email memakai klasifikasi yang sama dengan laporan utama:
            // laba operasional sebelum PPh, PBJT sebagai pelunasan kewajiban, dan
            // refund booking sebagai kontra-pendapatan (bukan beban operasional).
            $recognizedIncome = 0.0;
            $recognizedExpense = 0.0;
            $pbjtPaid = 0.0;
            $incomeTaxPaid = 0.0;
            $liquidIncome = 0.0;
            $liquidExpense = 0.0;
            $otaReceivableMovement = 0.0;
            $txHtmlRows = "";
            $idx = 1;

            foreach ($allTx as $t) {
                $amt = round((float)$t['amount'], 2);
                $sem=tamasyaTransactionSemantics((array)$t);
                $isInc=(bool)$sem['isIncome'];
                $bankAccountId=(string)$sem['bank'];
                $transactionKind=(string)$sem['kind'];
                $categoryLower=(string)$sem['category'];
                $isInternalTransfer=(bool)$sem['isInternalTransfer'];
                $isOtaTransfer=(bool)$sem['isOtaTransfer'];
                $isOtaReceivable=$bankAccountId==='ota_receivable';
                $isTechnicalNonLiquid=(bool)$sem['isTechnicalNonLiquid'];
                $isDepositReceipt=(bool)$sem['isDepositReceipt'];
                $isDepositRefund=(bool)$sem['isDepositRefund'];
                $isDepositForfeit=(bool)$sem['isDepositForfeit'];
                $isOpeningBalance=(bool)$sem['isOpeningBalance'];
                $isTaxUnresolvedReceipt=(bool)$sem['isTaxUnresolvedReceipt'];
                $isExpenseReversal=(bool)$sem['isExpenseReversal'];
                $isBookingRefund=(bool)$sem['isRevenueRefund'];
                $isPbjtSettlement=(bool)$sem['isPbjtSettlement'];
                $isIncomeTaxSettlement=(bool)$sem['isIncomeTaxSettlement'];
                $isApPayment=(bool)$sem['isApPayment'];
                $isNonPnlSupplierAccrual=(bool)$sem['isNonPnlSupplierAccrual'];
                $recognizedRevenueAmount=(float)$sem['recognizedRevenue'];

                // Laba-rugi operasional sebelum PPh: deposito diterima/refund adalah
                // pergerakan kewajiban; PBJT adalah pelunasan utang; PPh ditampilkan
                // terpisah karena pembayaran kas dapat terkait periode pajak berbeda.
                // Pendapatan/refund memakai DPP/base snapshot agar konsisten dengan jurnal;
                // arus kas di bawah tetap memakai nominal bruto yang benar-benar bergerak.
                // P&L delta berasal langsung dari semantic classifier canonical.
                // PBJT/PPh tetap ditampilkan terpisah sebagai settlement kewajiban pajak.
                $recognizedIncome += (float)$sem['incomeDelta'];
                $recognizedExpense += (float)$sem['expenseDelta'];
                if ($isPbjtSettlement) $pbjtPaid += $amt;
                if ($isIncomeTaxSettlement) $incomeTaxPaid += $amt;

                // Piutang OTA harus turun ketika payout memakai kaki sumber receivable.
                if ($isOtaReceivable) {
                    $otaReceivableMovement += $isInc ? $amt : -$amt;
                }
                // Arus kas likuid mengecualikan akun teknis/non-kas dan forfeit.
                // OTA payout hanya menghitung kaki tujuan bank; mutasi internal biasa
                // dikeluarkan dari total penerimaan/pengeluaran operasional.
                $includeLiquid = (!$isInternalTransfer || $isOtaTransfer) && !$isTechnicalNonLiquid && !$isDepositForfeit && !$isOpeningBalance;
                if ($includeLiquid) {
                    if ($isInc) $liquidIncome += $amt;
                    else $liquidExpense += $amt;
                }

                $signClass = $isInc ? 'income' : 'expense';
                if ($isOpeningBalance) $signText = 'SALDO PEMBUKA / NERACA';
                elseif ($isTaxUnresolvedReceipt) $signText = 'PENERIMAAN MENUNGGU REKONSILIASI PAJAK';
                elseif ($isDepositForfeit) $signText = 'PENDAPATAN NON-KAS';
                elseif ($isPbjtSettlement) $signText = 'PELUNASAN UTANG PBJT';
                elseif ($isIncomeTaxSettlement) $signText = 'PEMBAYARAN PPh';
                elseif ($isBookingRefund) $signText = 'KONTRA PENDAPATAN / REFUND';
                elseif ($bankAccountId === 'inventory_asset') $signText = 'NON-KAS / PERSEDIAAN';
                elseif ($bankAccountId === 'guest_receivable') $signText = 'NON-KAS / PIUTANG TAMU';
                elseif ($bankAccountId === 'accounts_payable') $signText = 'NON-KAS / UTANG USAHA';
                elseif ($isApPayment) $signText = 'PELUNASAN UTANG USAHA';
                elseif ($isExpenseReversal) $signText = 'PEMBALIK BEBAN';
                else $signText = $isInternalTransfer
                    ? 'MUTASI INTERNAL'
                    : ($isOtaReceivable ? ($isInc ? 'PIUTANG OTA +' : 'PIUTANG OTA -') : ($isInc ? 'PEMASUKAN' : 'PENGELUARAN'));
                $formattedAmt = "Rp " . number_format($amt, 0, ',', '.');

                $txHtmlRows .= "<tr>
                    <td>{$idx}</td>
                    <td>" . htmlspecialchars((string)$t['date'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td class='{$signClass}'>" . htmlspecialchars($signText, ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars((string)$t['description'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td class='{$signClass}'>{$formattedAmt}</td>
                </tr>";
                $idx++;
            }

            $liquidCashflow = $liquidIncome - $liquidExpense;
            $incomeText = number_format($recognizedIncome, 0, ',', '.');
            $expenseText = number_format($recognizedExpense, 0, ',', '.');
            $operatingProfitText = number_format($recognizedIncome - $recognizedExpense, 0, ',', '.');
            $pbjtPaidText = number_format($pbjtPaid, 0, ',', '.');
            $incomeTaxPaidText = number_format($incomeTaxPaid, 0, ',', '.');
            $liquidIncomeText = number_format($liquidIncome, 0, ',', '.');
            $liquidExpenseText = number_format($liquidExpense, 0, ',', '.');
            $otaReceivableText = number_format($otaReceivableMovement, 0, ',', '.');
            $cashflowText = number_format($liquidCashflow, 0, ',', '.');

            // Buat body email HTML
            $propertyName=tamasyaPropertyDisplayName($pdo);
            $safePropertyName=htmlspecialchars($propertyName,ENT_QUOTES,'UTF-8');
            $subject = "Laporan Keuangan Otomatis {$propertyName} - $monthName $year";
            $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $message = "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <title>Laporan Keuangan - $safePropertyName</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1e293b; line-height: 1.5; padding: 20px; background-color: #f8fafc; }
        .container { max-width: 650px; margin: 0 auto; background-color: #ffffff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        h2 { color: #4f46e5; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; font-size: 18px; }
        .summary-box { background-color: #f1f5f9; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #cbd5e1; }
        .summary-item { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .summary-label { font-weight: bold; color: #475569; }
        .income { color: #10b981; font-weight: bold; }
        .expense { color: #ef4444; font-weight: bold; }
        .cashflow { color: #3b82f6; font-weight: bold; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        th { background-color: #f1f5f9; color: #1e293b; font-weight: bold; }
        .footer { font-size: 10px; color: #64748b; margin-top: 25px; text-align: center; border-top: 1px dashed #cbd5e1; padding-top: 10px; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>=== LAPORAN KEUANGAN $safePropertyName ===</h2>
        <div class='summary-box'>
            <div class='summary-item'><span class='summary-label'>Bulan:</span> <span>$monthName $year</span></div>
            <div class='summary-item'><span class='summary-label'>Email Penerima:</span> <span>$safeEmail</span></div>
            <div class='summary-item'><span class='summary-label'>Pendapatan Operasional Diakui:</span> <span class='income'>Rp $incomeText</span></div>
            <div class='summary-item'><span class='summary-label'>Beban Operasional Diakui (sebelum PPh):</span> <span class='expense'>Rp $expenseText</span></div>
            <div class='summary-item'><span class='summary-label'>Laba/Rugi Operasional Sebelum PPh:</span> <span>Rp $operatingProfitText</span></div>
            <div class='summary-item'><span class='summary-label'>Pembayaran PBJT (pelunasan utang):</span> <span class='expense'>Rp $pbjtPaidText</span></div>
            <div class='summary-item'><span class='summary-label'>Pembayaran PPh (rekonsiliasi terpisah):</span> <span class='expense'>Rp $incomeTaxPaidText</span></div>
            <div class='summary-item'><span class='summary-label'>Penerimaan Likuid:</span> <span class='income'>Rp $liquidIncomeText</span></div>
            <div class='summary-item'><span class='summary-label'>Pengeluaran Likuid:</span> <span class='expense'>Rp $liquidExpenseText</span></div>
            <div class='summary-item'><span class='summary-label'>Pergerakan Piutang OTA:</span> <span>Rp $otaReceivableText</span></div>
            <div class='summary-item'><span class='summary-label'>Arus Kas Likuid Bersih:</span> <span class='cashflow'>Rp $cashflowText</span></div>
        </div>
        
        <h3>Rincian Transaksi Terkini</h3>
        <table>
            <thead>
                <tr>
                    <th style='width: 30px;'>No</th>
                    <th>Tanggal</th>
                    <th>Tipe</th>
                    <th>Deskripsi</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                $txHtmlRows
            </tbody>
        </table>
        
        <div class='footer'>
            Laporan ini dikirimkan secara otomatis oleh TAMASYA Hotel System untuk $safePropertyName.<br>
            Silakan amankan salinan digital ini untuk pembukuan bulanan Anda.
        </div>
    </div>
</body>
</html>";

            // R3 Canonical Report Engine: legacy tombol email keuangan tetap
            // kompatibel, tetapi attachment PDF + Excel sekarang berasal dari
            // snapshot server yang sama dengan download resmi.
            $reportFrom = sprintf('%04d-%02d-01', $year, $month);
            $reportTo = (new DateTimeImmutable($reportFrom))->modify('last day of this month')->format('Y-m-d');
            $canonicalEmailSnapshot = tamasyaCanonicalReportSnapshot($pdo,$loggedInStaff,'financial_summary',$reportFrom,$reportTo);
            $canonicalPdf = tamasyaCanonicalReportRender($canonicalEmailSnapshot,'pdf');
            $canonicalXlsx = tamasyaCanonicalReportRender($canonicalEmailSnapshot,'xlsx');
            $emailAttachments = [
                ['filename'=>$canonicalPdf['filename'],'mime'=>$canonicalPdf['mime'],'content'=>$canonicalPdf['body']],
                ['filename'=>$canonicalXlsx['filename'],'mime'=>$canonicalXlsx['mime'],'content'=>$canonicalXlsx['body']],
            ];
            $message = tamasyaCanonicalReportEmailBody($canonicalEmailSnapshot);
            $subject = $canonicalEmailSnapshot['meta']['propertyName'].' - Laporan Keuangan - '.$monthName.' '.$year;

            $mailSent = false;
            $sendMethod = "";
            $errorDetail = "";

            // Defense in depth: even if this action is invoked through an
            // unexpected route, SMTP/PHP mail may execute only on the active
            // Primary Writer with a valid leadership lease.
            if (!tamasyaExternalSideEffectsAllowed()) {
                http_response_code(423);
                echo json_encode(['success'=>false,'message'=>'Pengiriman email hanya boleh dijalankan oleh Primary aktif dengan leadership lease yang valid.']);
                break;
            }

            // Catat intent secara durable sebelum side effect email. Jika tahap ini
            // gagal, email belum pernah dikirim sehingga retry tetap aman.
            $pdo->beginTransaction();
            $pendingNotification = $pdo->prepare("SELECT id,message,type FROM notifications WHERE id=? LIMIT 1 FOR UPDATE");
            $pendingNotification->execute([$emailNotificationId]);
            $existingPendingNotification = $pendingNotification->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$existingPendingNotification) {
                $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
                    ->execute([$emailNotificationId,"Pengiriman laporan keuangan {$monthName} {$year} ke {$emailDisplay}: MENUNGGU PROSES"]);
            }
            writeRequiredEnterpriseAudit(
                $pdo,$loggedInStaff,'Menjadwalkan pengiriman laporan keuangan','email_report_delivery',$emailDeliveryId,
                null,
                ['period'=>sprintf('%04d-%02d',$year,$month),'recipientHash'=>$emailHash,'recipientMasked'=>$emailDisplay,'operationId'=>$emailOperationId],
                'web',['outcome'=>'pending']
            );
            tamasyaFinancialCommit($pdo);

            if (!empty($smtp_host)) {
                // Gunakan SMTP Client Kustom yang handal
                try {
                    $mailSent = sendSmtpMail($email, $subject, $message, $confRow, $emailAttachments);
                    $sendMethod = "SMTP ($smtp_host)";
                } catch (Exception $ex) {
                    $mailSent = false;
                    $errorDetail = clientExceptionMessage("SMTP gagal", $ex);
                    $sendMethod = $errorDetail;
                }
            }

            // Jika SMTP tidak diisi atau gagal, coba mail() bawaan PHP
            if (!$mailSent) {
                $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (string)(parse_url((string)(getenv('APP_URL')?:'https://localhost'),PHP_URL_HOST)?:'localhost');
                // Bersihkan port host
                if (($pos = strpos($host, ':')) !== false) {
                    $host = substr($host, 0, $pos);
                }
                if (empty($host) || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
                    $host = (string)(parse_url((string)(getenv('APP_URL')?:'https://localhost'),PHP_URL_HOST)?:'localhost');
                }
                $from_email = $confRow ? ($confRow['smtp_from'] ?? "no-reply@$host") : "no-reply@$host";
                if (empty($from_email)) {
                    $from_email = "no-reply@$host";
                }

                // PHP mail() fallback memakai multipart yang sama sehingga
                // PDF/XLSX tidak hilang hanya karena hosting tidak memakai SMTP.
                $mailSent = tamasyaPhpMailWithAttachments($email, $subject, $message, $emailAttachments, $confRow ?: ['smtp_from'=>$from_email]);
                if ($mailSent) {
                    $sendMethod = empty($sendMethod) ? "PHP mail()" : "$sendMethod, Fallback ke PHP mail() Sukses";
                } else {
                    $sendMethod = empty($sendMethod) ? "PHP mail() Gagal" : "$sendMethod, Fallback ke PHP mail() juga Gagal";
                }
            }

            $sendMethodLabel = $mailSent
                ? (str_contains(strtolower($sendMethod),'mail()') ? 'PHP mail()' : 'SMTP')
                : 'Tidak terkirim';
            $finalizationWarnings = [];
            try {
                $pdo->beginTransaction();
                $notificationLock = $pdo->prepare("SELECT id,message,type FROM notifications WHERE id=? LIMIT 1 FOR UPDATE");
                $notificationLock->execute([$emailNotificationId]);
                $beforeNotification = $notificationLock->fetch(PDO::FETCH_ASSOC) ?: null;
                $statusLabel = $mailSent ? 'SUKSES' : 'GAGAL';
                $notificationMessage = "Pengiriman laporan keuangan {$monthName} {$year} ke {$emailDisplay}: {$statusLabel} [{$sendMethodLabel}]";
                if ($beforeNotification) {
                    $pdo->prepare("UPDATE notifications SET message=?,timestamp=CURRENT_TIMESTAMP,`read`=0,type='system' WHERE id=?")
                        ->execute([$notificationMessage,$emailNotificationId]);
                } else {
                    $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
                        ->execute([$emailNotificationId,$notificationMessage]);
                }
                writeRequiredEnterpriseAudit(
                    $pdo,$loggedInStaff,
                    $mailSent ? 'Pengiriman laporan keuangan berhasil' : 'Pengiriman laporan keuangan gagal',
                    'email_report_delivery',$emailDeliveryId,
                    ['status'=>'pending','notification'=>$beforeNotification?['id'=>$emailNotificationId,'messageHash'=>hash('sha256',(string)$beforeNotification['message'])]:null],
                    ['status'=>$mailSent?'sent':'failed','period'=>sprintf('%04d-%02d',$year,$month),'recipientHash'=>$emailHash,'recipientMasked'=>$emailDisplay,'method'=>$sendMethodLabel,'providerErrorHash'=>$errorDetail!==''?hash('sha256',$errorDetail):null],
                    'web',['outcome'=>$mailSent?'success':'failed']
                );
                tamasyaFinancialCommit($pdo);
            } catch (Throwable $finalizeError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log(clientExceptionMessage('[email-report] finalisasi lokal memerlukan perhatian manual',$finalizeError));
                $finalizationWarnings[] = 'Status pengiriman belum dapat difinalkan pada audit lokal; periksa Pusat Operasional sebelum mengirim ulang.';
            }

            if ($mailSent) {
                echo json_encode([
                    'success'=>true,
                    'message'=>"Laporan berhasil dikirim ke {$emailDisplay} menggunakan {$sendMethodLabel}.",
                    'mailSent'=>true,
                    'deliveryId'=>$emailDeliveryId,
                    'warnings'=>$finalizationWarnings,
                    'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
                ]);
            } else {
                http_response_code(502);
                echo json_encode([
                    'success'=>false,
                    'message'=>"Laporan tidak berhasil dikirim ke {$emailDisplay}. Periksa konfigurasi SMTP atau layanan mail hosting sebelum mencoba dengan operation ID baru.",
                    'mailSent'=>false,
                    'deliveryId'=>$emailDeliveryId,
                    'warnings'=>$finalizationWarnings,
                    'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
                ]);
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal memproses email", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/send-shift-report ATAU api.php?action=send-shift-report
    // Kirim Laporan Shift Jaga & Rekonsiliasi Kas Ke Telegram Bot
    // ----------------------------------------------------------------
    case 'send-shift-report':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); echo json_encode(['message'=>'Method Not Allowed']); break;
        }
        try {
            $staffId=trim((string)($input['staffId']??''));
            $companionStaffId=trim((string)($input['companionStaffId']??''));
            $shiftDate=trim((string)($input['shiftDate']??date('Y-m-d')));
            $shiftTime=trim((string)($input['shiftTime']??'all'));
            if(!isset($input['actualPhysicalCash'])||!is_numeric($input['actualPhysicalCash'])||!is_finite((float)$input['actualPhysicalCash'])||(float)$input['actualPhysicalCash']<0)throw new InvalidArgumentException('Kas fisik aktual wajib angka valid dan tidak negatif.');
            $actualPhysicalCash=round((float)$input['actualPhysicalCash'],2);
            $notes=trim((string)($input['notes']??''));
            $operationId=trim((string)($input['operationId']??''));
            if($staffId==='' || !validIsoDate($shiftDate) || !in_array($shiftTime,['pagi','siang','malam','all'],true)) throw new InvalidArgumentException('Staf, tanggal, dan jadwal shift wajib valid.');
            if($operationId==='' || strlen($operationId)>100) throw new InvalidArgumentException('Operation ID laporan wajib tersedia.');
            $staffStmt=$pdo->prepare("SELECT id,name,username,role,status FROM staff WHERE id=? LIMIT 1");$staffStmt->execute([$staffId]);$staff=$staffStmt->fetch();
            if(!$staff) throw new InvalidArgumentException('Staf laporan tidak ditemukan.');
            $companion=null;
            if($companionStaffId!==''){
                if($companionStaffId===$staffId) throw new InvalidArgumentException('Staf pendamping harus berbeda.');
                $staffStmt->execute([$companionStaffId]);$companion=$staffStmt->fetch();
                if(!$companion) throw new InvalidArgumentException('Staf pendamping tidak ditemukan.');
            }
            $reportId='sr_manual_'.substr(hash('sha256',$operationId),0,32);
            $pdo->beginTransaction();
            $existing=$pdo->prepare("SELECT * FROM shift_reports WHERE id=? LIMIT 1 FOR UPDATE");$existing->execute([$reportId]);$existingRow=$existing->fetch(PDO::FETCH_ASSOC);
            if($existingRow){
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'message'=>'Laporan yang sama sudah pernah disimpan.','reportId'=>$reportId,'idempotent'=>true,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);break;
            }
            $financials=getShiftFinancials($pdo,$staff,$shiftTime,$shiftDate,$companionStaffId!==''?$companionStaffId:'none',true);
            $expectedCash=(float)$financials['expectedCash'];$variance=$actualPhysicalCash-$expectedCash;
            $displayName=(string)$staff['name'].($companion?' & '.(string)$companion['name']:'');
            $pdo->prepare("INSERT INTO shift_reports (id,staffId,companionStaffId,shiftSessionId,staffName,shiftDate,shiftTime,startingCash,expectedCash,actualPhysicalCash,variance,digitalRevenue,transactionsCount,notes,createdAt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
                ->execute([$reportId,$staffId,$companionStaffId!==''?$companionStaffId:null,null,$displayName,$shiftDate,$shiftTime,(float)$financials['startingCash'],$expectedCash,$actualPhysicalCash,$variance,(float)$financials['digitalInc'],(int)$financials['txCount'],$notes]);
            $afterReportStmt=$pdo->prepare("SELECT * FROM shift_reports WHERE id=? LIMIT 1");$afterReportStmt->execute([$reportId]);$afterReport=$afterReportStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$afterReport)throw new RuntimeException('Laporan shift gagal diverifikasi setelah penyimpanan.');
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat rekonsiliasi shift manual','shift_report',$reportId,null,['id'=>$reportId,'staffId'=>$staffId,'companionStaffId'=>$companionStaffId?:null,'shiftDate'=>$shiftDate,'shiftTime'=>$shiftTime,'expectedCash'=>$expectedCash,'actualCash'=>$actualPhysicalCash,'variance'=>$variance,'digitalRevenue'=>(float)$financials['digitalInc'],'transactionsCount'=>(int)$financials['txCount'],'operationId'=>$operationId,'notesHash'=>$notes!==''?hash('sha256',$notes):null,'notesLength'=>tamasyaStringLength($notes)],'web');
            tamasyaFinancialCommit($pdo);
            $postCommitWarnings=[];
            $varianceText=$variance==0.0?'PAS / COCOK (Rp 0)':($variance>0?'LEBIH (+Rp '.number_format($variance,0,',','.').')':'KURANG (Rp '.number_format(abs($variance),0,',','.').')');
            $tgMessage="🔔 *REKONSILIASI SHIFT MANUAL*

👤 *Staf:* {$displayName}
📅 *Tanggal:* {$shiftDate}
⏰ *Jadwal:* {$shiftTime}

💵 *Modal awal:* Rp ".number_format((float)$financials['startingCash'],0,',','.')."
📊 *Kas seharusnya:* Rp ".number_format($expectedCash,0,',','.')."
🔍 *Kas aktual:* Rp ".number_format($actualPhysicalCash,0,',','.')."
⚠️ *Selisih:* {$varianceText}
💳 *Digital:* Rp ".number_format((float)$financials['digitalInc'],0,',','.')."
📈 *Transaksi:* ".(int)$financials['txCount']."

📝 ".($notes!==''?$notes:'Tanpa catatan.');
            try{broadcastTelegramNotification($pdo,$tgMessage,true);}
            catch(Throwable $broadcastError){
                $postCommitWarnings[]='Rekonsiliasi tersimpan, tetapi broadcast Telegram gagal. Data shift tidak dibatalkan dan dapat dilihat dari Pusat Operasional.';
                error_log(clientExceptionMessage('[send-shift-report] broadcast gagal setelah commit',$broadcastError));
            }
            echo json_encode(['success'=>true,'message'=>$postCommitWarnings?'Rekonsiliasi manual tersimpan; broadcast Telegram memerlukan pemeriksaan.':'Rekonsiliasi manual dihitung ulang oleh server, disimpan, dan disiarkan. Penutupan sesi shift tetap dilakukan dari Pusat Operasional.','reportId'=>$reportId,'warnings'=>$postCommitWarnings,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal menyimpan rekonsiliasi shift',$e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/categories ATAU api.php?action=categories
    // Tambah Kategori Baru
    // ----------------------------------------------------------------
}
