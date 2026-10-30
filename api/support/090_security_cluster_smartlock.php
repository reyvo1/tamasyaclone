<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 6957: validateApplicationSchema */
function validateApplicationSchema(PDO $pdo): array {
    $required=[
      'config'=>['id','server_revision','telegram_webhook_secret','telegram_webhook_url','telegram_webhook_last_error','telegram_webhook_checked_at','cleanup_mode_until','cleanup_mode_enabled_by','cleanup_mode_note'],
      'staff'=>['id','name','username','password','role','status','permissions'],
      'rooms'=>['id','number','type','price','status','floor','version'],
      'bookings'=>['id','guestName','roomNumber','roomType','checkIn','checkOut','stayMode','scheduledCheckInAt','scheduledCheckOutAt','totalAmount','status','paymentStatus','paymentMethod','bankAccountId','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId','vatRate','vatAmount','ktpPhoto','extras','bookingSource','version','isOpenEnded','securityDepositRequired','securityDepositRequiredAmount','securityDepositReceived','securityDepositRefunded','securityDepositForfeited','securityDepositHeld','securityDepositStatus','financialClosureStatus','financialClosureBalance','financialClosureReason','financialClosureAt','financialClosureBy','financialClosureSource','financialClosureOperationId'],
      'transactions'=>['id','type','category','categoryId','categorySystemKey','subcategoryId','subcategorySystemKey','amount','date','serviceDate','description','bookingId','bankAccountId','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId','baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','transactionKind','operationId','recordOrigin','shiftExempt','reportingPeriod','periodImpactStatus','requiresTaxAmendment','historicalReviewStatus','version'],
      'transaction_allocations'=>['id','operation_id','transaction_id','booking_id','allocation_type','amount','status','created_at'],
      'financial_reporting_periods'=>['period_key','cash_status','tax_status','status_source','created_at','updated_at'],
      'historical_backfill_adjustments'=>['id','operation_id','transaction_id','period_key','impact_status','review_status','created_at'],
      'guest_security_deposits'=>['id','booking_id','required_amount','received_amount','refunded_amount','forfeited_amount','held_balance','status','version'],
      'guest_security_deposit_ledger'=>['id','operation_id','deposit_id','booking_id','entry_type','amount','transaction_id','created_at'],
      'salary_slips'=>['id','staff_id','period','net_salary','status','payment_method','bank_account_id','paid_at','updated_at'],
      'staff_leave_requests'=>['id','operation_id','staff_id','staff_name','leave_type','start_date','end_date','days_requested','reason','status','requested_at','decided_by','decided_at','updated_at'],
      'tax_rules'=>['id','source_pattern','transaction_kind','taxable','rate','priority','effective_from','effective_until','is_active'],
      'chat_messages'=>['id','staff_id','sender','text','timestamp'],
      'room_vacancy_reports'=>['id','operation_id','booking_id','room_number','status','key_observation','belongings_status','damage_status','reported_by','source','reported_at'],
      'telegram_messages'=>['id','sender','text','timestamp','telegram_user_id','staff_id','command'],
      'telegram_bindings'=>['telegram_user_id','telegram_chat_id','staff_id','status'],
      'telegram_update_log'=>['update_id','status','attempts'],
      'telegram_callback_tokens'=>['token','staff_id','callback_data','created_at','expires_at'],
      'communication_channels'=>['id','provider_key','display_name','enabled','inbound_enabled','outbound_enabled','managed_by','config_json','health_status'],
      'communication_identities'=>['id','channel_id','provider_user_id','staff_id','status'],
      'communication_binding_codes'=>['id','channel_id','staff_id','code_hash','expires_at','used_at'],
      'communication_sessions'=>['id','channel_identity_id','state','context_json','expires_at'],
      'communication_webhook_events'=>['id','channel_id','provider_event_id','payload_hash','status','attempts'],
      'communication_outbox'=>['id','event_type','recipient_staff_id','preferred_channel_id','message_json','status','attempts'],
      'communication_delivery_attempts'=>['id','outbox_id','channel_id','status','attempt_number'],
      'communication_inbox'=>['id','staff_id','channel_id','message','read_at','created_at'],
      'communication_preferences'=>['staff_id','event_type','primary_channel_id','enabled'],
      'communication_templates'=>['id','event_type','channel_id','language','body_template','status'],
      'public_site_settings'=>['id','hotel_name','section_config_json','theme_json','updated_at'],
      'public_room_types'=>['id','slug','name','price_from','capacity','public_status','updated_at'],
      'public_promotions'=>['id','slug','title','status','updated_at'],
      'promotion_codes'=>['id','code','title','status','valid_from','valid_until','updated_at'],
      'promotion_code_usages'=>['id','promotion_code_id','usage_type','actor_id','amount','operation_id','created_at'],
      'public_site_media'=>['id','media_kind','file_url','status','updated_at'],
      'public_reservation_requests'=>['id','public_request_id','operation_id','guest_name','check_in','check_out','room_type_id','status','created_at'],
      'public_support_conversations'=>['id','public_code','visitor_token_hash','status','last_message_at'],
      'public_support_messages'=>['id','conversation_id','sender','message','created_at'],
      'user_sessions'=>['id','staff_id','token_hash','device_id','expires_at','revoked_at'],
      'sync_operations'=>['operation_id','staff_id','device_id','entity_type','entity_id','action','status'],
      'sync_devices'=>['device_id','staff_id','status','last_seen'],
      'node_cluster_state'=>['id','cluster_id','current_primary_node_id','leadership_epoch','fencing_token','lease_owner_node_id','lease_id','lease_expires_at','transfer_state'],
      'node_cluster_members'=>['node_id','cluster_id','effective_role','member_status','leadership_epoch'],
      'node_cluster_events'=>['id','cluster_id','event_type','leadership_epoch','created_at'],
      'categories'=>['id','name','type','system_key','is_system','is_active','created_at','updated_at'], 'subcategories'=>['id','category_id','category_name','name','system_key','is_system','is_active','created_at','updated_at'],
      'data_integrity_issues'=>['id','issue_type','entity_type','entity_id','severity','status','detected_at'],
      'bank_accounts'=>['id','name','accountNumber','accountHolder','type','isActive'], 'notifications'=>['id','message','timestamp'], 'notification_reads'=>['notification_id','staff_id','read_at'],
      'activity_logs'=>['id','staff_id','action_type','description','timestamp'],
      'audit_logs'=>['id','action','entity_type','source','created_at'],
      'inventory'=>['id','code','name','quantity','price','version'],
      'inventory_maintenance'=>['id','inventory_id','maintenance_date','cost','version'],
      'shift_reports'=>['id','staffId','companionStaffId','shiftSessionId','staffName','shiftDate','startingCash','expectedCash','actualPhysicalCash'],
      'shift_sessions'=>['id','staff_id','companion_staff_id','opening_cash','expected_cash','status','opened_at'],
      'attendance'=>['id','staff_id','staff_name','date','clock_in','clock_out','method','location','status','notes','verification_id','device_id','verification_score','evidence_hash','source_event_id','verified_at','clock_out_verification_id','clock_out_device_id','clock_out_verification_score','clock_out_verified_at','clock_out_source_event_id','clock_out_location','created_at'],
      'biometric_devices'=>['id','serial_number','device_type','connection_mode','status','updated_at'],
      'biometric_device_users'=>['id','device_id','device_user_id','staff_id','biometric_type','enrollment_status','updated_at'],
      'staff_biometric_profiles'=>['id','staff_id','biometric_type','provider','provider_profile_id','status','updated_at'],
      'biometric_verifications'=>['id','staff_id','method','source','device_id','status','verified_at','consumed_at'],
      'housekeeping_tasks'=>['id','room_number','status','priority','assigned_staff_id','completed_by_staff_id','last_action_by_staff_id','last_action_source'],
      'room_access_control'=>['room_number','access_mode','physical_key_status','current_booking_id'],
      'room_key_events'=>['id','room_number','event_type','created_at'],
      'night_audit_runs'=>['id','audit_date','staff_id','status'],
      'night_audit_items'=>['id','audit_id','room_number','resolution_status'],
      'approval_requests'=>['id','request_type','status','created_at'],
      'reconciliation_items'=>['id','source','amount','transaction_date','status'],
      'journal_entries'=>['id','transaction_id','entry_date','status'],
      'journal_lines'=>['id','journal_entry_id','debit','credit'],
      'pos_categories'=>['id','name','sort_order','is_active','version'],
      'pos_products'=>['id','sku','name','stock_quantity','sale_price','is_active','version'],
      'pos_sales'=>['id','receipt_number','status','payment_method','gross_amount','tax_amount','sale_date','version'],
      'pos_sale_items'=>['id','sale_id','product_id','quantity','gross_amount','tax_amount'],
      'pos_stock_movements'=>['id','product_id','sale_id','movement_type','quantity_delta','quantity_after'],
      'pos_print_logs'=>['id','sale_id','print_type','paper_width','requested_copies','operation_id','printed_by','created_at'],
      'pos_delivery_events'=>['id','sale_id','from_status','to_status','operation_id','acted_by','created_at'],
      'guest_profiles'=>['id','name','identity_number','retention_until'],
      'maintenance_tickets'=>['id','asset_ref','status','estimated_cost','actual_cost'],
      'app_documents'=>['id','title','content','is_active'],
      'backup_runs'=>['id','backup_type','status','created_at'],
      'system_alerts'=>['id','severity','message','created_at'],
      'test_data_purge_logs'=>['id','entity_type','entity_id','reason','snapshot_hash','purged_by','purged_at'],
      'data_purge_batches'=>['id','status','reason_category','reason','backup_confirmed','force_mode','booking_count','transaction_count','gross_income','snapshot_hash','created_by','created_at'],
      'data_purge_batch_items'=>['id','batch_id','entity_type','entity_id','item_hash'],
      'sync_tombstones'=>['id','entity_type','entity_id','purge_batch_id','deleted_at'],
      'ota_disbursements'=>['id','operation_id','disbursement_date','bank_account_id','amount','source_transaction_id','destination_transaction_id','status'],
      'ota_disbursement_items'=>['id','disbursement_id','receivable_transaction_id','booking_id','allocated_amount'],
      'staff_savings_accounts'=>['staff_id','balance','status','version','updated_at'],
      'staff_savings_requests'=>['id','operation_id','staff_id','amount','purpose','payout_method','status','requested_by','ledger_entry_id'],
      'staff_savings_ledger'=>['id','operation_id','receipt_number','staff_id','entry_type','amount','balance_before','balance_after','request_id','created_by'],
    ];
    $issues=[];
    foreach($required as $table=>$columns){
        if(!tamasyaTableExists($pdo,$table)){ $issues[]="table:{$table}"; continue; }
        foreach($columns as $column){ if(tamasyaColumnMeta($pdo,$table,$column)===null) $issues[]="column:{$table}.{$column}"; }
    }
    foreach([['bookings','vatRate','decimal(7,3)'],['transactions','taxRate','decimal(7,3)'],['tax_rules','rate','decimal(7,3)']] as $typeRequirement){
        $meta=tamasyaColumnMeta($pdo,$typeRequirement[0],$typeRequirement[1]);
        $actual=strtolower((string)($meta['COLUMN_TYPE']??''));
        if(!str_starts_with($actual,$typeRequirement[2])) $issues[]="type:{$typeRequirement[0]}.{$typeRequirement[1]}={$actual}";
    }
    return ['ready'=>count($issues)===0,'issues'=>$issues];
}

/** Source line 7023: getOperationalSettings */
function getOperationalSettings(PDO $pdo): array {
    // Operational settings drive checkout due-time, shift/key controls, and
    // room-security policy. A query failure must never be mistaken for an
    // empty/default policy because that could silently relax a production gate.
    $row=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch() ?: [];
    if (!$row) throw new RuntimeException('Pengaturan operasional hotel tidak tersedia; operasi sensitif dibatalkan agar policy tidak jatuh ke default diam-diam.');
    if (!empty($row['smart_lock_bridge_token'])) $row['smart_lock_bridge_token']='[TERSIMPAN]';
    return $row;
}

/** R16.4: feature availability and shift policy are independent controls.
 * Legacy properties without the migration remain ENABLED, never fail-open OFF.
 * Each property's PDO connection is its own policy authority. */
function tamasyaNightAuditIsEnabled(array $settings): bool {
    return (int)($settings['night_audit_enabled'] ?? 1) === 1;
}
function tamasyaRequireNightAuditEnabled(PDO $pdo): void {
    if (!$pdo->inTransaction()) throw new RuntimeException('Night Audit wajib dalam transaksi DB untuk kontrol perubahan mode.');
    $row = $pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Pengaturan Night Audit tidak tersedia; operasi ditolak.');
    if (!tamasyaNightAuditIsEnabled($row)) throw new RuntimeException('Night Audit dinonaktifkan oleh Admin pada properti ini.');
}

/** Source line 7031: enforceOpenShiftForRoomSale */
function enforceOpenShiftForRoomSale(PDO $pdo, ?array $user): void {
    if(!$user || ($user['role']??'')!=='receptionist')return;
    $settings=$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn();
    if((int)$settings!==1)return;
    if(!getOpenShiftForStaff($pdo,(string)($user['id']??''),false))throw new RuntimeException('Buka shift kas terlebih dahulu sebelum menjual/check-in kamar, atau pastikan akun sudah dipilih sebagai pendamping shift.');
}

/** Source line 7038: isNightShiftRow */
function isNightShiftRow(array $shift,?array $boundaries=null): bool {
    // Jadwal yang dipilih petugas adalah sumber utama. Jam pembukaan hanya
    // dipakai untuk data lama yang belum mempunyai shift_time, lalu dinilai
    // dengan batas jam shift yang dapat diatur properti (R14.1).
    $shiftTime=strtolower(trim((string)($shift['shift_time']??'')));
    if($shiftTime!=='')return in_array($shiftTime,['malam','all'],true);
    $opened=strtotime((string)($shift['opened_at']??''));
    if(!$opened)return false;
    $b=$boundaries??tamasyaShiftBoundariesMinutes([]);
    $minute=(int)date('G',$opened)*60+(int)date('i',$opened);
    return $minute>=$b['malam_start']||$minute<$b['day_start'];
}

/** Finance dapat menjadi pemilik shift kas malam; karena itu ia tidak boleh
 * terjebak pada kewajiban Night Audit tanpa akses untuk menjalankannya. */
function canPerformNightAuditForUser($user): bool {
    $role=strtolower(trim((string)($user['role']??'')));
    if($role==='finance')return true;
    return hasCapability($user,'perform_night_audit',['admin','manager','finance','receptionist','keamanan']);
}

function requireNightAuditCapability($user): void {
    if(!canPerformNightAuditForUser($user)){
        throw new RuntimeException('Forbidden: izin Melakukan Pemeriksaan Night Audit diperlukan.');
    }
}

/** Ambil kondisi sistem terbaru untuk satu kamar. */
function getNightAuditRoomSnapshot(PDO $pdo,string $roomNumber,bool $forUpdate=true): array {
    $suffix=$forUpdate?' FOR UPDATE':'';
    $roomStmt=$pdo->prepare("SELECT r.number,r.status,COALESCE(rac.access_mode,'physical') access_mode,COALESCE(rac.physical_key_status,'secured') physical_key_status,rac.current_booking_id FROM rooms r LEFT JOIN room_access_control rac ON rac.room_number=r.number WHERE r.number=? LIMIT 1{$suffix}");
    $roomStmt->execute([$roomNumber]);
    $room=$roomStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$room)throw new RuntimeException("Kamar {$roomNumber} tidak ditemukan pada master kamar.");
    $bookingStmt=$pdo->prepare("SELECT id,guestName,keyControlStatus,accessMode FROM bookings WHERE roomNumber=? AND status='active' ORDER BY checkIn,id{$suffix}");
    $bookingStmt->execute([$roomNumber]);
    $activeBookings=$bookingStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $booking=$activeBookings[0]??null;
    $roomBlockers=array_values(array_filter(getRoomOperationalBlockers($pdo,$roomNumber,false),static fn(array $b): bool => (string)($b['type']??'')!=='night_audit'));
    $canonicalRoomStatus=tamasyaDeriveRoomOperationalStatus($roomBlockers);
    return [
        'room_number'=>(string)$room['number'],
        'system_room_status'=>$canonicalRoomStatus,
        'active_booking_id'=>$booking['id']??null,
        'active_booking_count'=>count($activeBookings),
        'guest_name'=>$booking['guestName']??null,
        'booking_key_status'=>$booking['keyControlStatus']??null,
        'booking_access_mode'=>$booking['accessMode']??null,
        'access_mode'=>(string)($room['access_mode']??'physical'),
        'registered_key_status'=>(string)($room['physical_key_status']??'secured'),
        'current_key_booking_id'=>$room['current_booking_id']??null,
    ];
}

/**
 * Bangun ulang dan rekonsiliasi item Night Audit dari seluruh master kamar.
 * Audit tidak pernah bergantung pada penjualan atau transaksi shift.
 */
function reconcileNightAuditItems(PDO $pdo,string $auditId,bool $invalidateChangedChecks=true): array {
    $runStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");
    $runStmt->execute([$auditId]);
    $run=$runStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$run)throw new RuntimeException('Night audit tidak ditemukan atau sudah selesai.');

    $rooms=$pdo->query("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED),number FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(count($rooms)===0)throw new RuntimeException('Master kamar kosong. Tambahkan atau sinkronkan data kamar sebelum menjalankan Night Audit.');

    $itemStmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE audit_id=? FOR UPDATE");
    $itemStmt->execute([$auditId]);
    $existingRows=$itemStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $existing=[];
    foreach($existingRows as $row)$existing[(string)$row['room_number']]=$row;

    $bookingStmt=$pdo->prepare("SELECT id,guestName FROM bookings WHERE roomNumber=? AND status='active' ORDER BY checkIn,id FOR UPDATE");
    $insertStmt=$pdo->prepare("INSERT INTO night_audit_items (id,audit_id,room_number,system_room_status,active_booking_id,guest_name,physical_occupancy,observed_key_status,resolution_status) VALUES (?,?,?,?,?,?,'unchecked','unchecked','open')");
    $updateSnapshot=$pdo->prepare("UPDATE night_audit_items SET system_room_status=?,active_booking_id=?,guest_name=? WHERE id=?");
    $invalidateSnapshot=$pdo->prepare("UPDATE night_audit_items SET system_room_status=?,active_booking_id=?,guest_name=?,physical_occupancy='unchecked',observed_key_status='unchecked',discrepancy_type='source_state_changed',resolution_status='open',checked_by=NULL,checked_at=NULL,resolved_by=NULL,resolved_at=NULL,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE ' | ' END,'DATA SISTEM BERUBAH: wajib periksa ulang') WHERE id=?");

    $inserted=0;$updated=0;$invalidated=0;$masterNumbers=[];
    foreach($rooms as $room){
        $number=(string)$room['number'];$masterNumbers[$number]=true;
        $bookingStmt->execute([$number]);$booking=$bookingStmt->fetch(PDO::FETCH_ASSOC)?:null;
        $auditBlockers=array_values(array_filter(getRoomOperationalBlockers($pdo,$number,false),static fn(array $b): bool => (string)($b['type']??'')!=='night_audit'));
        $snapshot=[
            'system_room_status'=>tamasyaDeriveRoomOperationalStatus($auditBlockers),
            'active_booking_id'=>$booking['id']??null,
            'guest_name'=>$booking['guestName']??null,
        ];
        $item=$existing[$number]??null;
        if(!$item){
            $itemId=substr($auditId.'_'.$number,0,90);
            $insertStmt->execute([$itemId,$auditId,$number,$snapshot['system_room_status'],$snapshot['active_booking_id'],$snapshot['guest_name']]);
            $inserted++;
            continue;
        }
        $changed=(string)($item['system_room_status']??'')!==$snapshot['system_room_status']
            || (string)($item['active_booking_id']??'')!==(string)($snapshot['active_booking_id']??'')
            || (string)($item['guest_name']??'')!==(string)($snapshot['guest_name']??'');
        if(!$changed)continue;
        if($invalidateChangedChecks && !empty($item['checked_at'])){
            $invalidateSnapshot->execute([$snapshot['system_room_status'],$snapshot['active_booking_id'],$snapshot['guest_name'],$item['id']]);
            $invalidated++;
        }else{
            $updateSnapshot->execute([$snapshot['system_room_status'],$snapshot['active_booking_id'],$snapshot['guest_name'],$item['id']]);
            $updated++;
        }
    }

    // Item kamar yang telah dihapus dari master tidak boleh membuat total audit
    // palsu. Selama audit masih terbuka item tersebut aman dibuang.
    $removed=0;
    foreach($existingRows as $row){
        $number=(string)($row['room_number']??'');
        if($number!==''&&!isset($masterNumbers[$number])){
            $delete=$pdo->prepare("DELETE FROM night_audit_items WHERE id=? AND audit_id=?");
            $delete->execute([$row['id'],$auditId]);
            $removed+=$delete->rowCount();
        }
    }

    $countStmt=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN checked_at IS NOT NULL THEN 1 ELSE 0 END) checked,SUM(CASE WHEN discrepancy_type IS NOT NULL AND discrepancy_type<>'' THEN 1 ELSE 0 END) discrepancies,SUM(CASE WHEN discrepancy_type LIKE '%key%' OR discrepancy_type='physical_key_missing' THEN 1 ELSE 0 END) key_discrepancies,SUM(CASE WHEN discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved' THEN 1 ELSE 0 END) unresolved FROM night_audit_items WHERE audit_id=?");
    $countStmt->execute([$auditId]);
    $counts=$countStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $total=(int)($counts['total']??0);
    $pdo->prepare("UPDATE night_audit_runs SET total_rooms=?,checked_rooms=?,occupancy_discrepancies=?,key_discrepancies=? WHERE id=?")
        ->execute([$total,(int)($counts['checked']??0),(int)($counts['discrepancies']??0),(int)($counts['key_discrepancies']??0),$auditId]);
    return [
        'total'=>$total,
        'checked'=>(int)($counts['checked']??0),
        'discrepancies'=>(int)($counts['discrepancies']??0),
        'keyDiscrepancies'=>(int)($counts['key_discrepancies']??0),
        'unresolved'=>(int)($counts['unresolved']??0),
        'inserted'=>$inserted,'updated'=>$updated,'invalidated'=>$invalidated,'removed'=>$removed,
    ];
}

/** Source line 7045: insertRoomKeyEvent */
function insertRoomKeyEvent(PDO $pdo, array $user, string $room, ?string $bookingId, string $eventType, string $mode, ?string $keyRef, ?string $last4, ?string $status, string $reason=''): string {
    $id=generateServerId('keyevt');
    $pdo->prepare("INSERT INTO room_key_events (id,room_number,booking_id,event_type,access_mode,physical_key_ref,smart_code_last4,credential_status,staff_id,staff_name,reason,device_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
        ->execute([$id,$room,$bookingId,$eventType,$mode,$keyRef,$last4,$status,$user['id']??null,currentStaffLabel($user),$reason?:null,currentDeviceId()]);
    return $id;
}

/** Source line 7052: queueSmartLockBridgeJob */
function tamasyaSmartLockActorSnapshot(array $actor): array {
    return [
        'id'=>(string)($actor['id']??''),
        'name'=>currentStaffLabel($actor),
        'role'=>(string)($actor['role']??''),
        'session_id'=>(string)($actor['session_id']??''),
    ];
}

function tamasyaEncodeSmartLockPayload(array $jobPayload, string $jobId): array {
    $payload=$jobPayload;
    $payload['jobId']=$jobId;
    $payload['idempotencyKey']=$jobId;
    if(array_key_exists('code',$payload)){
        $code=(string)$payload['code'];
        unset($payload['code']);
        if($code==='')throw new InvalidArgumentException('PIN smart-lock tidak boleh kosong.');
        $payload['credentialCiphertext']=encryptStoredSecret($code);
        $payload['credentialLast4']=substr($code,-4);
        $payload['credentialEncoding']='encrypted_v1';
    }
    return $payload;
}

function tamasyaDecodeSmartLockPayload(array $job): array {
    $payload=json_decode((string)($job['payload']??''),true);
    if(!is_array($payload))throw new RuntimeException('Payload pekerjaan smart-lock tidak valid.');
    if(($payload['code']??null)==='[ONE_TIME_SECRET]'){
        throw new RuntimeException('Pekerjaan smart-lock lama tidak memiliki credential yang dapat dipulihkan dan wajib ditangani manual.');
    }
    if(!empty($payload['credentialCiphertext'])){
        try{$payload['code']=decryptStoredSecret((string)$payload['credentialCiphertext']);}
        catch(Throwable $e){throw new RuntimeException('Credential pekerjaan smart-lock tidak dapat didekripsi.');}
    }
    unset($payload['credentialCiphertext'],$payload['credentialEncoding']);
    $payload['idempotencyKey']=(string)($payload['idempotencyKey']??($job['id']??''));
    return $payload;
}

function queueSmartLockBridgeJob(PDO $pdo, array $control, array $jobPayload): string {
    $action=strtolower(trim((string)($jobPayload['action']??'')));
    if(!in_array($action,['grant','revoke'],true))throw new InvalidArgumentException('Aksi smart-lock tidak valid.');
    $roomNumber=trim((string)($jobPayload['roomNumber']??''));
    if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar smart-lock wajib diisi.');
    $jobId=trim((string)($jobPayload['jobId']??''));
    if($jobId==='')$jobId=generateServerId('lockjob');
    $encoded=tamasyaEncodeSmartLockPayload($jobPayload,$jobId);
    $json=json_encode($encoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Payload pekerjaan smart-lock tidak dapat disimpan.');
    $stmt=$pdo->prepare("INSERT INTO smart_lock_jobs (id,room_number,booking_id,action,provider,device_id,payload,status,attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'pending',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $stmt->execute([$jobId,$roomNumber,$jobPayload['bookingId']??null,$action,$control['smart_lock_provider']??null,$control['smart_lock_device_id']??($jobPayload['deviceId']??null),$json]);
    return $jobId;
}

/**
 * Membatalkan grant yang belum terminal ketika lifecycle booking sudah bergerak
 * ke checkout/pindah kamar. Job berstatus processing tetap ditandai cancelled;
 * bila respons bridge yang terlambat kemudian datang, finalizer akan membuat
 * revoke kompensasi agar akses lama tidak dapat hidup kembali.
 */
function cancelObsoleteSmartLockGrantJobs(PDO $pdo, array $staff, string $bookingId, string $roomNumber, string $reason, string $source='system'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Pembatalan grant smart-lock wajib berada di transaksi lifecycle booking.');
    $bookingId=trim($bookingId);$roomNumber=trim($roomNumber);$reason=trim($reason);
    if($bookingId===''||$roomNumber==='')return [];
    $stmt=$pdo->prepare("SELECT * FROM smart_lock_jobs WHERE booking_id=? AND room_number=? AND action='grant' AND status NOT IN ('completed','cancelled') ORDER BY created_at,id FOR UPDATE");
    $stmt->execute([$bookingId,$roomNumber]);$jobs=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $cancelled=[];
    foreach($jobs as $job){
        $jobId=(string)$job['id'];
        $message=$reason!==''?$reason:'Grant smart-lock dibatalkan karena lifecycle booking berubah.';
        $pdo->prepare("UPDATE smart_lock_jobs SET status='cancelled',last_error=?,completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND status NOT IN ('completed','cancelled')")
            ->execute([substr($message,0,500),$jobId]);
        $alertId='alert_lock_'.substr(hash('sha256',$jobId),0,40);
        $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(string)($staff['id']??''),$alertId]);
        writeRequiredEnterpriseAudit($pdo,$staff,'Membatalkan grant smart-lock usang','smart_lock_job',$jobId,$job,[
            'status'=>'cancelled','roomNumber'=>$roomNumber,'bookingId'=>$bookingId,'reason'=>$message
        ],$source);
        $cancelled[]=$jobId;
    }
    return $cancelled;
}

function tamasyaSmartLockAckComplete(mixed $ack,string $jobId): bool {
    return is_array($ack)&&($ack['success']??null)===true&&($ack['jobId']??null)===$jobId&&($ack['status']??null)==='completed';
}
function tamasyaSmartLockBridgeRequest(array $payload): array {
    $allowed=['jobId','action','roomNumber','bookingId','deviceId','code','validFrom','validUntil','idempotencyKey'];
    $request=[];
    foreach($allowed as $key)if(array_key_exists($key,$payload))$request[$key]=$payload[$key];
    return $request;
}

function tamasyaFinalizeSmartLockJob(PDO $pdo, array $job, array $payload, array $actor, string $terminalStatus, ?string $error=null, string $source='system', bool $errorSafeForClient=false): array {
    $jobId=(string)$job['id'];
    $action=strtolower((string)($job['action']??$payload['action']??''));
    $roomNumber=(string)($job['room_number']??$payload['roomNumber']??'');
    $bookingId=(string)($job['booking_id']??$payload['bookingId']??'');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare("SELECT * FROM smart_lock_jobs WHERE id=? LIMIT 1 FOR UPDATE");
        $lock->execute([$jobId]);
        $current=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$current)throw new RuntimeException('Pekerjaan smart-lock hilang saat finalisasi.');
        $currentJobStatus=strtolower(trim((string)($current['status']??'')));
        if($currentJobStatus==='completed'){
            tamasyaFinancialCommit($pdo);
            return ['jobId'=>$jobId,'status'=>'completed','message'=>'Pekerjaan smart-lock sudah selesai.'];
        }
        // Job grant yang dibatalkan karena checkout/pindah kamar tidak boleh hidup
        // kembali menjadi failed/pending saat request in-flight akhirnya gagal. Hanya
        // respons sukses grant yang tetap perlu diproses untuk revoke kompensasi.
        if($currentJobStatus==='cancelled' && !($terminalStatus==='completed' && $action==='grant')){
            tamasyaFinancialCommit($pdo);
            return ['jobId'=>$jobId,'status'=>'cancelled','message'=>'Pekerjaan smart-lock tetap dibatalkan karena lifecycle booking sudah berubah.'];
        }
        if($terminalStatus==='completed'){
            $beforeBooking=null;$afterBooking=null;$staleGrantCompensationId=null;$grantLifecycleValid=true;
            $bookingStateMode=(string)($payload['bookingStateMode']??'booking');
            $physicalKeyDisposition=strtolower(trim((string)($payload['physicalKeyDisposition']??'returned')));
            if(!in_array($physicalKeyDisposition,['returned','missing','not_required'],true))$physicalKeyDisposition='returned';
            $physicalKeyStatusAfterRevoke=$physicalKeyDisposition==='missing'?'missing':'secured';
            if($bookingId!=='' && $bookingStateMode!=='none'){
                $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
                $bookingStmt->execute([$bookingId]);
                $beforeBooking=$bookingStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if($action==='grant' && !$beforeBooking)$grantLifecycleValid=false;
                if($beforeBooking){
                    $mode=(string)($beforeBooking['accessMode']??$payload['accessMode']??'smart');
                    if($action==='grant'){
                        $payloadCode=isset($payload['code'])?(string)$payload['code']:'';
                        $payloadHash=$payloadCode!==''?hash('sha256',$payloadCode):'';
                        $storedHash=trim((string)($beforeBooking['smartLockCodeHash']??''));
                        if($bookingStateMode==='refresh_validity'){
                            $targetValidUntil=trim((string)($payload['validUntil']??''));
                            $currentDue=trim((string)($beforeBooking['checkoutDueAt']??''));
                            $expectedValidUntil=$currentDue!==''?tamasyaR3ExpectedSmartLockValidUntil($pdo,$currentDue):'';
                            $grantLifecycleValid=strtolower((string)($beforeBooking['status']??''))==='active'
                                && trim((string)($beforeBooking['roomNumber']??''))===$roomNumber
                                && strtolower((string)($beforeBooking['keyControlStatus']??''))==='issued'
                                && $targetValidUntil!=='' && strtotime($targetValidUntil)!==false
                                && $expectedValidUntil!=='' && abs(strtotime($targetValidUntil)-strtotime($expectedValidUntil))<=1
                                && $payloadHash!=='' && $storedHash!=='' && hash_equals($storedHash,$payloadHash);
                            if($grantLifecycleValid){
                                $pdo->prepare("UPDATE bookings SET smartLockValidUntil=?,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource=? WHERE id=? AND status='active' AND roomNumber=? AND keyControlStatus='issued'")
                                    ->execute([$targetValidUntil,(string)($actor['id']??''),$source,$bookingId,$roomNumber]);
                                insertRoomKeyEvent($pdo,$actor,$roomNumber,$bookingId,'smart_grant_validity_refreshed',$mode,$payload['physicalKeyRef']??null,$payload['credentialLast4']??null,'active',(string)($payload['reason']??'Masa berlaku smart-lock diperpanjang mengikuti stay window.'));
                            }
                        }else{
                            $grantLifecycleValid=strtolower((string)($beforeBooking['status']??''))==='active'
                                && trim((string)($beforeBooking['roomNumber']??''))===$roomNumber
                                && strtolower((string)($beforeBooking['keyControlStatus']??''))==='pending_smart_issue'
                                && $payloadHash!=='' && $storedHash!=='' && hash_equals($storedHash,$payloadHash);
                            if($grantLifecycleValid){
                                $grantUpdate=$pdo->prepare("UPDATE bookings SET keyControlStatus='issued',accessMode=?,keyIssuedAt=COALESCE(keyIssuedAt,CURRENT_TIMESTAMP),keyIssuedBy=COALESCE(keyIssuedBy,?),keyReturnedAt=NULL,keyReturnedBy=NULL WHERE id=? AND status='active' AND roomNumber=? AND keyControlStatus='pending_smart_issue'");
                                $grantUpdate->execute([$mode,(string)($actor['id']??''),$bookingId,$roomNumber]);
                                if($grantUpdate->rowCount()!==1)$grantLifecycleValid=false;
                                if($grantLifecycleValid){
                                    $physicalStatusAfterGrant=$mode==='hybrid'?'issued':'secured';
                                    $pdo->prepare("UPDATE room_access_control SET physical_key_status=?,current_booking_id=?,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                                        ->execute([$physicalStatusAfterGrant,$bookingId,(string)($actor['id']??''),$roomNumber]);
                                }
                                if($grantLifecycleValid)insertRoomKeyEvent($pdo,$actor,$roomNumber,$bookingId,'smart_grant_confirmed',$mode,$payload['physicalKeyRef']??null,$payload['credentialLast4']??null,'active',(string)($payload['reason']??'Smart-lock bridge mengonfirmasi pemberian akses.'));
                            }
                        }
                    }else{
                        $bookingKeyStatusAfterRevoke=$physicalKeyDisposition==='missing'?'missing':'returned';
                        $pdo->prepare("UPDATE bookings SET keyControlStatus=?,keyReturnedAt=IF(?='returned',COALESCE(keyReturnedAt,CURRENT_TIMESTAMP),NULL),keyReturnedBy=?,smartLockCodeHash=NULL,smartLockCodeLast4=NULL,smartLockValidFrom=NULL,smartLockValidUntil=NULL WHERE id=?")
                            ->execute([$bookingKeyStatusAfterRevoke,$bookingKeyStatusAfterRevoke,(string)($actor['id']??''),$bookingId]);
                        $pdo->prepare("UPDATE room_access_control SET physical_key_status=?,current_booking_id=NULL,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                            ->execute([$physicalKeyStatusAfterRevoke,(string)($actor['id']??''),$roomNumber]);
                        insertRoomKeyEvent($pdo,$actor,$roomNumber,$bookingId,'smart_revoke_confirmed',(string)($beforeBooking['accessMode']??'smart'),$payload['physicalKeyRef']??null,$beforeBooking['smartLockCodeLast4']??null,'revoked',(string)($payload['reason']??'Smart-lock bridge mengonfirmasi pencabutan akses.'));
                        $alertId='alert_lock_'.substr(hash('sha256',$jobId),0,40);
                        $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
                            ->execute([(string)($actor['id']??''),$alertId]);
                    }
                    $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
                    $afterStmt->execute([$bookingId]);
                    $afterBooking=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                }
            }
            if($action==='grant' && ($bookingId==='' || $bookingStateMode==='none'))$grantLifecycleValid=false;
            if($action==='grant' && !$grantLifecycleValid){
                // Bridge sudah mengonfirmasi GRANT, tetapi lifecycle booking berubah
                // ketika request berada di jaringan. Jangan hidupkan state booking lama;
                // antrekan REVOKE kompensasi yang idempoten dan proses setelah commit.
                $accessStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
                $accessStmt->execute([$roomNumber]);$currentAccess=$accessStmt->fetch(PDO::FETCH_ASSOC)?:[];
                $currentPhysicalStatus=strtolower(trim((string)($currentAccess['physical_key_status']??'secured')));
                $compPhysicalDisposition=$currentPhysicalStatus==='missing'?'missing':'returned';
                $staleGrantCompensationId='lockjob_stale_'.substr(hash('sha256',$jobId.'|revoke'),0,56);
                try{
                    queueSmartLockBridgeJob($pdo,array_merge($currentAccess,[
                        'smart_lock_provider'=>$current['provider']??($payload['provider']??null),
                        'smart_lock_device_id'=>$current['device_id']??($payload['deviceId']??null)
                    ]),[
                        'jobId'=>$staleGrantCompensationId,'action'=>'revoke','roomNumber'=>$roomNumber,'bookingId'=>$bookingId?:null,
                        'deviceId'=>$current['device_id']??($payload['deviceId']??null),'accessMode'=>$payload['accessMode']??'smart',
                        'physicalKeyRef'=>$payload['physicalKeyRef']??($currentAccess['physical_key_ref']??('KEY-'.$roomNumber)),
                        'credentialLast4'=>$payload['credentialLast4']??null,'bookingStateMode'=>'none','physicalKeyDisposition'=>$compPhysicalDisposition,
                        'reason'=>'Revoke kompensasi otomatis: grant smart-lock terkonfirmasi setelah lifecycle booking berubah.',
                        'source'=>$source,'actor'=>tamasyaSmartLockActorSnapshot($actor)
                    ]);
                }catch(PDOException $duplicateCompensation){
                    $dupCode=(string)$duplicateCompensation->getCode();
                    if($dupCode!=='23000')throw $duplicateCompensation;
                }
                insertRoomKeyEvent($pdo,$actor,$roomNumber,$bookingId?:null,'smart_grant_stale_revoke_queued',(string)($payload['accessMode']??'smart'),$payload['physicalKeyRef']??null,$payload['credentialLast4']??null,'pending','Grant terlambat terdeteksi; revoke kompensasi otomatis dijadwalkan.');
            }
            if($bookingStateMode==='none' && $action==='revoke'){
                $pdo->prepare("UPDATE room_access_control SET physical_key_status=CASE WHEN current_booking_id IS NULL OR current_booking_id=? THEN ? ELSE physical_key_status END,current_booking_id=CASE WHEN current_booking_id=? THEN NULL ELSE current_booking_id END,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                    ->execute([$bookingId,$physicalKeyStatusAfterRevoke,$bookingId,(string)($actor['id']??''),$roomNumber]);
                insertRoomKeyEvent($pdo,$actor,$roomNumber,$bookingId?:null,'smart_revoke_confirmed_transfer',(string)($payload['accessMode']??'smart'),$payload['physicalKeyRef']??null,$payload['credentialLast4']??null,'revoked',(string)($payload['reason']??'Smart-lock bridge mengonfirmasi pencabutan akses kamar asal.'));
            }
            $pdo->prepare("UPDATE smart_lock_jobs SET status='completed',last_error=NULL,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$jobId]);
            $roomProjection=$roomNumber!==''?tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source.'-smart-lock-terminal'):null;
            writeRequiredEnterpriseAudit($pdo,$actor,$action==='grant'?'Smart-lock grant terkonfirmasi':'Smart-lock revoke terkonfirmasi','smart_lock_job',$jobId,$current,[
                'status'=>'completed','roomNumber'=>$roomNumber,'bookingId'=>$bookingId,'bookingBefore'=>$beforeBooking,'bookingAfter'=>$afterBooking,'staleGrantCompensationJobId'=>$staleGrantCompensationId,'roomProjection'=>$roomProjection
            ],$source);
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            if($staleGrantCompensationId!==null){
                return ['jobId'=>$jobId,'status'=>'stale_grant_revoke_pending','compensatingJobId'=>$staleGrantCompensationId,'message'=>'Grant smart-lock datang terlambat setelah lifecycle booking berubah; state booking tidak dihidupkan kembali dan revoke kompensasi dijadwalkan.'];
            }
            return ['jobId'=>$jobId,'status'=>'completed','message'=>'Perintah diterima smart-lock bridge dan state database telah difinalisasi.'];
        }
        $status=in_array($terminalStatus,['failed','manual_required','attention_required','pending'],true)?$terminalStatus:'failed';
        $pdo->prepare("UPDATE smart_lock_jobs SET status=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$status,$error!==null?substr($error,0,500):null,$jobId]);
        if(in_array($status,['failed','manual_required','attention_required'],true)){
            $alertId='alert_lock_'.substr(hash('sha256',$jobId),0,40);
            $message='Pekerjaan smart-lock Kamar '.$roomNumber.' belum selesai: '.($error?:$status);
            $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'critical','smart-lock','Smart-lock perlu tindak lanjut',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                ->execute([$alertId,$message]);
        }
        $roomProjection=$roomNumber!==''?tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source.'-smart-lock-pending'):null;
        writeRequiredEnterpriseAudit($pdo,$actor,'Smart-lock belum terkonfirmasi','smart_lock_job',$jobId,$current,['status'=>$status,'roomNumber'=>$roomNumber,'bookingId'=>$bookingId,'error'=>$error,'roomProjection'=>$roomProjection],$source);
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        $clientMessage = $errorSafeForClient && trim((string)$error) !== ''
            ? trim((string)$error)
            : (in_array($status,['failed','attention_required'],true)
                ? 'Pekerjaan smart-lock belum dapat dikonfirmasi. Periksa konfigurasi/bridge atau hubungi administrator.'
                : 'Pekerjaan smart-lock belum selesai.');
        return ['jobId'=>$jobId,'status'=>$status,'message'=>$clientMessage];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function processSmartLockBridgeJobById(PDO $pdo, string $jobId, ?array $staff=null, string $source='system'): array {
    $jobId=trim($jobId);
    if($jobId==='')throw new InvalidArgumentException('ID pekerjaan smart-lock wajib diisi.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM smart_lock_jobs WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$jobId]);
        $job=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$job){tamasyaFinancialCommit($pdo);return ['jobId'=>$jobId,'status'=>'missing','message'=>'Job smart-lock tidak ditemukan.'];}
        $status=(string)($job['status']??'pending');
        if($status==='completed'){tamasyaFinancialCommit($pdo);return ['jobId'=>$jobId,'status'=>'completed','message'=>'Job smart-lock sudah selesai.'];}
        if($status==='cancelled'){tamasyaFinancialCommit($pdo);return ['jobId'=>$jobId,'status'=>'cancelled','message'=>'Job smart-lock dibatalkan karena lifecycle booking sudah berubah.'];}
        $updatedAt=strtotime((string)($job['updated_at']??''))?:0;
        if($status==='processing' && $updatedAt>time()-120){tamasyaFinancialCommit($pdo);return ['jobId'=>$jobId,'status'=>'processing','message'=>'Job smart-lock sedang diproses worker lain.'];}
        try{$payload=tamasyaDecodeSmartLockPayload($job);}catch(Throwable $e){
            $pdo->prepare("UPDATE smart_lock_jobs SET status='attention_required',last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([substr($e->getMessage(),0,500),$jobId]);
            tamasyaFinancialCommit($pdo);
            return ['jobId'=>$jobId,'status'=>'attention_required','message'=>'Payload pekerjaan smart-lock tidak dapat dibaca. Hubungi administrator.'];
        }
        $actor=is_array($staff)&&!empty($staff)?$staff:(is_array($payload['actor']??null)?$payload['actor']:['id'=>'system','name'=>'System','role'=>'system']);
        $jobAction=strtolower(trim((string)($job['action']??$payload['action']??'')));
        if($jobAction==='grant'){
            $grantBookingId=trim((string)($job['booking_id']??$payload['bookingId']??''));
            $grantBookingStateMode=strtolower(trim((string)($payload['bookingStateMode']??'booking')));
            $grantRoom=trim((string)($job['room_number']??$payload['roomNumber']??''));
            $grantStmt=$pdo->prepare("SELECT id,status,roomNumber,keyControlStatus,smartLockCodeHash,checkoutDueAt FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
            $grantStmt->execute([$grantBookingId]);$grantBooking=$grantStmt->fetch(PDO::FETCH_ASSOC)?:null;
            $payloadCode=isset($payload['code'])?(string)$payload['code']:'';
            $payloadHash=$payloadCode!==''?hash('sha256',$payloadCode):'';
            $storedHash=trim((string)($grantBooking['smartLockCodeHash']??''));
            $baseGrantValid=$grantBookingStateMode!=='none' && $grantBooking
                && strtolower((string)($grantBooking['status']??''))==='active'
                && trim((string)($grantBooking['roomNumber']??''))===$grantRoom
                && $payloadHash!=='' && $storedHash!=='' && hash_equals($storedHash,$payloadHash);
            if($grantBookingStateMode==='refresh_validity'){
                $targetValidUntil=trim((string)($payload['validUntil']??''));
                $currentDue=trim((string)($grantBooking['checkoutDueAt']??''));
                $expectedValidUntil=$currentDue!==''?tamasyaR3ExpectedSmartLockValidUntil($pdo,$currentDue):'';
                $grantValid=$baseGrantValid
                    && strtolower((string)($grantBooking['keyControlStatus']??''))==='issued'
                    && $targetValidUntil!=='' && strtotime($targetValidUntil)!==false
                    && $expectedValidUntil!=='' && abs(strtotime($targetValidUntil)-strtotime($expectedValidUntil))<=1;
            }else{
                $grantValid=$baseGrantValid
                    && strtolower((string)($grantBooking['keyControlStatus']??''))==='pending_smart_issue';
            }
            if(!$grantValid){
                $reason='Grant smart-lock tidak dikirim karena booking sudah tidak aktif/berpindah atau credential job bukan lagi credential aktif.';
                $pdo->prepare("UPDATE smart_lock_jobs SET status='cancelled',last_error=?,completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$reason,$jobId]);
                $alertId='alert_lock_'.substr(hash('sha256',$jobId),0,40);
                $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([(string)($actor['id']??''),$alertId]);
                writeRequiredEnterpriseAudit($pdo,$actor,'Membatalkan grant smart-lock usang','smart_lock_job',$jobId,$job,['status'=>'cancelled','reason'=>$reason],$source);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                return ['jobId'=>$jobId,'status'=>'cancelled','message'=>'Grant smart-lock dibatalkan aman karena lifecycle booking sudah berubah.'];
            }
        }
        $pdo->prepare("UPDATE smart_lock_jobs SET status='processing',attempts=attempts+1,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$jobId]);
        tamasyaFinancialCommit($pdo);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    if(!tamasyaExternalSideEffectsAllowed()){
        return tamasyaFinalizeSmartLockJob($pdo,$job,$payload,$actor,'pending','Menunggu replay ke primary aktif.',$source,true);
    }
    $settings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $url=trim((string)($settings['smart_lock_bridge_url']??''));
    if($url==='')return tamasyaFinalizeSmartLockJob($pdo,$job,$payload,$actor,'manual_required','Smart-lock bridge belum dikonfigurasi.',$source,true);
    if(strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return tamasyaFinalizeSmartLockJob($pdo,$job,$payload,$actor,'attention_required','Smart-lock bridge production wajib memakai HTTPS.',$source,true);
    $rawToken='';
    if(!empty($settings['smart_lock_bridge_token'])){try{$rawToken=decryptStoredSecret($settings['smart_lock_bridge_token']);}catch(Throwable $ignored){}}
    try{
        $headers=['Content-Type: application/json','Idempotency-Key: '.$jobId];
        if($rawToken!=='')$headers[]='Authorization: Bearer '.$rawToken;
        $timeout=max(3,min(30,(int)($settings['smart_lock_bridge_timeout']??8)));
        $request=tamasyaSmartLockBridgeRequest($payload);
        $raw=sendHttpPost($url,$request,$headers,['timeout'=>$timeout,'connectTimeout'=>min(5,$timeout),'expectedStatus'=>200]);
        $decoded=json_decode((string)$raw,true);
        if(!tamasyaSmartLockAckComplete($decoded,$jobId))throw new RuntimeException('Bridge belum memberikan ACK completed yang cocok dengan jobId; state akses tidak difinalisasi.');
        $finalized=tamasyaFinalizeSmartLockJob($pdo,$job,$payload,$actor,'completed',null,$source);
        $compensatingJobId=trim((string)($finalized['compensatingJobId']??''));
        if($compensatingJobId!==''){
            $compensation=processSmartLockBridgeJobById($pdo,$compensatingJobId,$actor,$source.'-stale-grant-compensation');
            $finalized['compensation']=$compensation;
            $finalized['status']=(($compensation['status']??'')==='completed')?'stale_grant_revoked':'stale_grant_revoke_pending';
            $finalized['message']=(($compensation['status']??'')==='completed')
                ? 'Grant smart-lock terlambat terdeteksi dan sudah dicabut kembali secara otomatis.'
                : 'Grant smart-lock terlambat terdeteksi. Revoke kompensasi sudah dibuat tetapi belum terkonfirmasi; kamar tetap diblokir.';
        }
        return $finalized;
    }catch(Throwable $e){
        return tamasyaFinalizeSmartLockJob($pdo,$job,$payload,$actor,'failed',$e->getMessage(),$source);
    }
}

/** Kompatibilitas pemanggil lama: payload selalu dibaca ulang dari durable outbox. */
function processSmartLockBridgeJob(PDO $pdo, string $jobId, array $settings=[], array $jobPayload=[]): array {
    return processSmartLockBridgeJobById($pdo,$jobId,is_array($jobPayload['actor']??null)?$jobPayload['actor']:null,(string)($jobPayload['source']??'system'));
}

/** Tidak melakukan network call di dalam transaksi pemanggil. */
function dispatchSmartLockBridge(PDO $pdo, array $settings, array $control, array $jobPayload): array {
    $jobId=queueSmartLockBridgeJob($pdo,$control,$jobPayload);
    return ['jobId'=>$jobId,'status'=>'pending','message'=>'Pekerjaan smart-lock masuk durable outbox dan diproses setelah commit.'];
}

function processDeferredCheckoutSmartLockJob(PDO $pdo, ?string $jobId, ?array $staff=null, string $source='system'): ?array {
    $jobId=trim((string)$jobId);
    if($jobId==='')return null;
    return processSmartLockBridgeJobById($pdo,$jobId,$staff,$source);
}

function completeSmartLockRevokeJobManually(PDO $pdo, array $staff, string $jobId, string $reason, string $source='web'): array {
    $jobId=trim($jobId);$reason=trim($reason);
    if($jobId==='')throw new InvalidArgumentException('ID pekerjaan smart-lock wajib diisi.');
    if(tamasyaStringLength($reason)<10)throw new InvalidArgumentException('Alasan dan bukti konfirmasi manual minimal 10 karakter.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM smart_lock_jobs WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$jobId]);$job=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$job)throw new RuntimeException('Pekerjaan smart-lock tidak ditemukan.');
        if(strtolower((string)($job['action']??''))!=='revoke')throw new RuntimeException('Hanya pekerjaan pencabutan akses yang dapat dikonfirmasi manual.');
        if((string)($job['status']??'')==='completed'){tamasyaFinancialCommit($pdo);return ['jobId'=>$jobId,'status'=>'completed','message'=>'Pekerjaan smart-lock sudah selesai sebelumnya.'];}
        $payload=[];try{$payload=tamasyaDecodeSmartLockPayload($job);}catch(Throwable $ignored){}
        $roomNumber=(string)($job['room_number']??$payload['roomNumber']??'');
        $bookingId=(string)($job['booking_id']??$payload['bookingId']??'');
        $bookingStateMode=(string)($payload['bookingStateMode']??'booking');
        $physicalKeyDisposition=strtolower(trim((string)($payload['physicalKeyDisposition']??'returned')));
        if(!in_array($physicalKeyDisposition,['returned','missing','not_required'],true))$physicalKeyDisposition='returned';
        $physicalKeyStatusAfterRevoke=$physicalKeyDisposition==='missing'?'missing':'secured';
        $beforeBooking=null;$afterBooking=null;
        if($bookingId!=='' && $bookingStateMode!=='none'){
            $b=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$b->execute([$bookingId]);$beforeBooking=$b->fetch(PDO::FETCH_ASSOC)?:null;
            if($beforeBooking){
                $bookingKeyStatusAfterRevoke=$physicalKeyDisposition==='missing'?'missing':'returned';
                $pdo->prepare("UPDATE bookings SET keyControlStatus=?,keyReturnedAt=IF(?='returned',COALESCE(keyReturnedAt,CURRENT_TIMESTAMP),NULL),keyReturnedBy=?,smartLockCodeHash=NULL,smartLockCodeLast4=NULL,smartLockValidFrom=NULL,smartLockValidUntil=NULL WHERE id=?")
                    ->execute([$bookingKeyStatusAfterRevoke,$bookingKeyStatusAfterRevoke,(string)($staff['id']??''),$bookingId]);
                $a=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$a->execute([$bookingId]);$afterBooking=$a->fetch(PDO::FETCH_ASSOC)?:null;
            }
        }
        $pdo->prepare("UPDATE room_access_control SET physical_key_status=CASE WHEN current_booking_id IS NULL OR current_booking_id=? THEN ? ELSE physical_key_status END,current_booking_id=CASE WHEN current_booking_id=? THEN NULL ELSE current_booking_id END,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
            ->execute([$bookingId,$physicalKeyStatusAfterRevoke,$bookingId,(string)($staff['id']??''),$roomNumber]);
        $note='MANUAL CONFIRMATION oleh '.currentStaffLabel($staff).': '.$reason;
        $pdo->prepare("UPDATE smart_lock_jobs SET status='completed',last_error=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([substr($note,0,500),$jobId]);
        $alertId='alert_lock_'.substr(hash('sha256',$jobId),0,40);
        $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(string)($staff['id']??''),$alertId]);
        insertRoomKeyEvent($pdo,$staff,$roomNumber,$bookingId?:null,'manual_revoke_confirmed','smart',null,null,'revoked',$reason);
        $roomProjection=$roomNumber!==''?tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-manual-smart-lock-complete'):null;
        writeRequiredEnterpriseAudit($pdo,$staff,'Konfirmasi manual pencabutan smart-lock','smart_lock_job',$jobId,$job,[
            'status'=>'completed','roomNumber'=>$roomNumber,'bookingId'=>$bookingId,'reason'=>$reason,'bookingBefore'=>$beforeBooking,'bookingAfter'=>$afterBooking,'roomProjection'=>$roomProjection
        ],$source);
        logActivity($pdo,'SMART_LOCK_MANUAL_COMPLETE',"Konfirmasi manual pencabutan smart-lock Kamar {$roomNumber}",(string)($staff['id']??''),currentStaffLabel($staff));
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        return ['jobId'=>$jobId,'status'=>'completed','message'=>'Pencabutan smart-lock dikonfirmasi manual dan tercatat dalam audit.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Source line 7164: refreshRoomSecurityState */
function refreshRoomSecurityState(PDO $pdo, bool $strict = false): bool {
    try {
        $settings=getOperationalSettings($pdo);
        $grace=max(0,(int)($settings['late_grace_minutes']??60));
        $reminder=max(0,(int)($settings['checkout_reminder_minutes']??30));
        $checkoutClock=substr((string)($settings['checkout_time']??'12:00:00'),0,8);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',$checkoutClock))$checkoutClock='12:00:00';
        $repairDueStmt=$pdo->prepare("UPDATE bookings b LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber SET b.actualCheckInAt=COALESCE(b.actualCheckInAt,b.createdAt),b.checkoutDueAt=COALESCE(b.checkoutDueAt,CONCAT(b.checkOut,' ',?)),b.accessMode=COALESCE(NULLIF(rac.access_mode,''),b.accessMode,'physical') WHERE b.status='active'");
        $repairDueStmt->execute([$checkoutClock]);
        $pdo->prepare("UPDATE bookings SET lateCheckoutStatus=CASE WHEN NOW()>DATE_ADD(checkoutDueAt,INTERVAL ? MINUTE) THEN 'overdue' WHEN NOW()>checkoutDueAt THEN 'grace' ELSE 'none' END WHERE status='active' AND checkoutDueAt IS NOT NULL")->execute([$grace]);
        $rows=$pdo->query("SELECT b.id,b.roomNumber,b.guestName,b.actualCheckInAt,b.checkoutDueAt,b.lateCheckoutStatus,b.keyControlStatus,rac.physical_key_status,rac.access_mode FROM bookings b LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber WHERE b.status='active'")->fetchAll()?:[];
        foreach($rows as $b){
            $due=strtotime((string)$b['checkoutDueAt']);
            if($due && time()>=$due-($reminder*60)){
                $severity=time()>$due+($grace*60)?'critical':(time()>$due?'warning':'info');
                $title=time()>$due+($grace*60)?'Check-out lewat masa toleransi':(time()>$due?'Check-out terlambat':'Check-out segera jatuh tempo');
                $message="Kamar {$b['roomNumber']} · {$b['guestName']} · jatuh tempo ".date('d-m-Y H:i',$due).".";
                $id='alert_checkout_'.hash('sha256',(string)$b['id']);
                $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,?,'checkout',?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE severity=VALUES(severity),title=VALUES(title),message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                    ->execute([$id,$severity,$title,$message]);
            }
            if((int)($settings['require_key_control']??1)===1 && ($b['keyControlStatus']??'not_issued')==='not_issued' && strtotime((string)($b['actualCheckInAt']??''))<time()-600){
                $id='alert_key_missing_'.hash('sha256',(string)$b['id']);
                $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'warning','room-security','Booking aktif tanpa catatan penyerahan kunci',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                    ->execute([$id,"Kamar {$b['roomNumber']} atas nama {$b['guestName']} aktif tetapi kunci/PIN belum tercatat diserahkan."]);
            }
        }
        $orphans=$pdo->query("SELECT rac.room_number,rac.physical_key_status FROM room_access_control rac LEFT JOIN bookings b ON b.roomNumber=rac.room_number AND b.status='active' WHERE b.id IS NULL AND rac.physical_key_status='issued'")->fetchAll()?:[];
        $orphanStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($orphans,'room_number'));
        foreach($orphans as $row){
            $roomNumber=(string)$row['room_number'];
            $canonical=$orphanStatusMap[$roomNumber]??'maintenance';
            $id='alert_room_orphan_'.hash('sha256',$roomNumber);
            $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'critical','room-security','Indikasi kamar/kunci tanpa booking aktif',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                ->execute([$id,"Kamar {$roomNumber} berstatus operasional {$canonical} / kunci {$row['physical_key_status']}, tetapi tidak memiliki booking aktif."]);
        }
        return true;
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[room-security] refresh failed',$e));
        if($strict)throw $e;
        return false;
    }
}

/** A real Telegram update must have a durable identity before any mutation. */
function tamasyaRequireTelegramUpdateId(array $update): string {
    $id=$update['update_id']??null;
    if(!is_int($id) && !is_string($id))
        throw new InvalidArgumentException('Telegram update_id tidak valid.');
    $value=(string)$id;
    // IDs must have one canonical decimal representation: accepting "00001"
    // would let a forged callback bypass the unique update-idempotency ledger
    // under another spelling. Also reject numbers beyond the DB integer range.
    if(!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)
       || (strlen($value)===19 && strcmp($value,(string)PHP_INT_MAX)>0))
        throw new InvalidArgumentException('Telegram update_id tidak valid.');
    return $value;
}

/** Source line 7197: claimTelegramUpdate */
function claimTelegramUpdate(PDO $pdo, string $updateId): string {
    if ($updateId === '') return 'skip';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT status, updated_at FROM telegram_update_log WHERE update_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$updateId]);
        $row = $stmt->fetch() ?: null;
        if (!$row) {
            $pdo->prepare("INSERT INTO telegram_update_log (update_id,status,attempts,created_at,updated_at) VALUES (?,'processing',1,NOW(),NOW())")->execute([$updateId]);
            tamasyaFinancialCommit($pdo);
            return 'claimed';
        }
        if (($row['status'] ?? '') === 'completed') {
            tamasyaFinancialCommit($pdo);
            return 'completed';
        }
        $updatedAt = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
        if (($row['status'] ?? '') === 'processing' && $updatedAt > time() - 120) {
            tamasyaFinancialCommit($pdo);
            return 'busy';
        }
        $pdo->prepare("UPDATE telegram_update_log SET status='processing', attempts=attempts+1, last_error=NULL, updated_at=NOW() WHERE update_id=?")->execute([$updateId]);
        tamasyaFinancialCommit($pdo);
        return 'claimed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log(clientExceptionMessage('[api.php] claim Telegram update gagal', $e));
        return 'error';
    }
}

/** Source line 7228: registerTelegramUpdateCompletion */
function registerTelegramUpdateCompletion(PDO $pdo, string $updateId, bool $primaryLockHeld = false): void {
    ob_start();
    register_shutdown_function(static function() use ($pdo, $updateId, $primaryLockHeld): void {
        if ($updateId === '') return;
        $statusCode = http_response_code();
        if ($statusCode === false || $statusCode === 0) $statusCode = 200;
        $responseBody = (string)(ob_get_contents() ?: '');
        $classification = tamasyaNodeClassifyResponse((int)$statusCode, $responseBody);
        if (!$classification['completed'] && $statusCode >= 200 && $statusCode < 400 && !headers_sent()) {
            http_response_code((int)$classification['httpStatus']);
        }
        $completed = !empty($classification['completed']);
        $lastError = error_get_last();
        $errorText = $completed ? null : substr((string)($classification['message'] ?: ($lastError['message'] ?? ('HTTP ' . $statusCode))), 0, 500);
        try {
            $stmt = $pdo->prepare("UPDATE telegram_update_log SET status=?, last_error=?, processed_at=?, updated_at=NOW() WHERE update_id=?");
            $stmt->execute([$completed ? 'completed' : 'failed', $errorText, $completed ? date('Y-m-d H:i:s') : null, $updateId]);
            if (function_exists('tamasyaCommunicationCompleteEvent')) tamasyaCommunicationCompleteEvent($pdo,'channel_telegram_main',$updateId,$completed,$errorText);
            // V137 fresh: webhook completion MUST NOT mutate historical tax metadata.
            // Missing/ambiguous tax snapshots are resolved only by the explicit audited
            // historical/backfill workflow; live mutations are fail-closed before commit.
            // Bump juga pada kegagalan: handler legacy mungkin sempat commit sebelum
            // gagal mengirim response. Revision konservatif mencegah snapshot memakai
            // data baru dengan nomor revision lama.
            if (!$pdo->inTransaction()) $pdo->exec("UPDATE config SET server_revision=COALESCE(server_revision,0)+1 WHERE id='system_default'");
        } catch (Throwable $ignored) {
        } finally {
            if ($primaryLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
        }
    });
}
