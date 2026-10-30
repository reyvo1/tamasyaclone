<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/**
 * Build a privacy-safe bookings projection that remains readable while an old
 * production database is being aligned. Missing additive columns are returned
 * with neutral defaults instead of causing the entire hotel-data payload (and
 * therefore categories/subcategories) to be discarded by the frontend.
 *
 * @return array{select:string,order:string,missing:array<int,string>}
 */
function tamasyaBookingReadProjection(PDO $pdo): array {
    $available=[];
    $stmt=$pdo->query("SHOW COLUMNS FROM `bookings`");
    foreach(($stmt?$stmt->fetchAll(PDO::FETCH_ASSOC):[]) as $columnRow){
        $field=(string)($columnRow['Field']??'');
        if($field!=='')$available[$field]=true;
    }
    if(!$available)throw new RuntimeException('Tabel bookings tidak memiliki kolom yang dapat dibaca.');

    // SQL fallbacks are deliberately neutral. They are read-only projections;
    // the schema repair below still attempts to add every supported column.
    $fields=[
        'id'=>"''",'guestName'=>"'Tamu'",'guestEmail'=>"''",'guestPhone'=>"''",
        'roomNumber'=>"''",'roomType'=>"'Standard'",'checkIn'=>'NULL','checkOut'=>'NULL',
        'totalAmount'=>'0','roomCharge'=>'0','extraCharge'=>'0','discountAmount'=>'0',
        'amountPaid'=>'0','balanceDue'=>'0','refundAmount'=>'0',
        'securityDepositRequired'=>'0','securityDepositRequiredAmount'=>'0','securityDepositReceived'=>'0',
        'securityDepositRefunded'=>'0','securityDepositForfeited'=>'0','securityDepositHeld'=>'0',
        'securityDepositStatus'=>"'not_required'",'financialClosureStatus'=>"'not_required'",
        'financialClosureBalance'=>'0','financialClosureReason'=>'NULL','financialClosureAt'=>'NULL',
        'financialClosureBy'=>'NULL','financialClosureSource'=>'NULL','financialClosureOperationId'=>'NULL',
        'financialProjectionMode'=>"'live_ledger'",'financialProjectionLockedAt'=>'NULL',
        'financialProjectionLockReason'=>'NULL','status'=>"'active'",'paymentStatus'=>"'unpaid'",
        'paymentMethod'=>'NULL','bankAccountId'=>'NULL','vatRate'=>'NULL','vatAmount'=>'NULL',
        'extras'=>'NULL','bookingSource'=>"'Direct'",'isSplitPayment'=>'0','splitCashAmount'=>'0',
        'splitTransferAmount'=>'0','splitTransferBankAccountId'=>'NULL','downPaymentAmount'=>'0',
        'downPaymentMethod'=>'NULL','downPaymentBankAccountId'=>'NULL','downPaymentDate'=>'NULL',
        'isOpenEnded'=>'0','stayMode'=>"'overnight'",'scheduledCheckInAt'=>'NULL',
        'scheduledCheckOutAt'=>'NULL','actualCheckInAt'=>'NULL','actualCheckOutAt'=>'NULL',
        'checkoutDueAt'=>'NULL','lateCheckoutStatus'=>"'none'",'lateCheckoutReason'=>'NULL',
        'lateCheckoutFee'=>'0','lateCheckoutApprovedBy'=>'NULL','keyControlStatus'=>"'not_issued'",
        'accessMode'=>"'physical'",'keyIssuedAt'=>'NULL','keyIssuedBy'=>'NULL','keyReturnedAt'=>'NULL',
        'keyReturnedBy'=>'NULL','version'=>'1','updatedAt'=>'NULL','updatedBy'=>'NULL',
        'updatedSource'=>'NULL','createdAt'=>'NULL'
    ];
    $parts=[];$missing=[];
    foreach($fields as $field=>$fallback){
        if(isset($available[$field]))$parts[]='`'.$field.'`';
        else{$parts[]=$fallback.' AS `'.$field.'`';$missing[]=$field;}
    }
    // Identity photos are never returned to the general hotel-data payload.
    $parts[]='NULL AS `ktpPhoto`';
    if(isset($available['ktpPhoto']))$parts[]="CASE WHEN `ktpPhoto` IS NOT NULL AND `ktpPhoto`<>'' THEN 1 ELSE 0 END AS `hasKtpPhoto`";
    else{$parts[]='0 AS `hasKtpPhoto`';$missing[]='ktpPhoto';}
    $order=isset($available['createdAt'])?' ORDER BY `createdAt` DESC':(isset($available['id'])?' ORDER BY `id` DESC':'');
    return ['select'=>implode(', ',$parts),'order'=>$order,'missing'=>array_values(array_unique($missing))];
}


/**
 * Compact mutation response for direct booking creation. It returns only the
 * entities changed by the committed booking workflow so the browser does not
 * have to wait for a full one-year hotel-data projection after every save.
 * Offline replay and normal full refresh continue using getRoleScopedHotelData.
 */
function tamasyaBookingCreateCompactDelta(PDO $pdo, array $user, array $result): array {
    $bookingId=trim((string)($result['bookingId']??''));
    $roomNumber=trim((string)($result['roomNumber']??''));
    if($bookingId==='')throw new InvalidArgumentException('Booking delta memerlukan bookingId.');

    $projection=tamasyaBookingReadProjection($pdo);
    $bookingStmt=$pdo->prepare("SELECT ".$projection['select']." FROM `bookings` WHERE id=? LIMIT 1");
    $bookingStmt->execute([$bookingId]);
    $booking=$bookingStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if($booking){
        foreach(['totalAmount','roomCharge','extraCharge','discountAmount','amountPaid','balanceDue','refundAmount','securityDepositRequiredAmount','securityDepositReceived','securityDepositRefunded','securityDepositForfeited','securityDepositHeld','financialClosureBalance','downPaymentAmount','splitCashAmount','splitTransferAmount','lateCheckoutFee'] as $moneyField){
            $booking[$moneyField]=(float)($booking[$moneyField]??0);
        }
        $booking['isOpenEnded']=!empty($booking['isOpenEnded']);
        $booking['securityDepositRequired']=!empty($booking['securityDepositRequired']);
        if(isset($booking['vatRate'])&&$booking['vatRate']!==null&&$booking['vatRate']!=='')$booking['vatRate']=(float)$booking['vatRate'];
        if(isset($booking['vatAmount'])&&$booking['vatAmount']!==null&&$booking['vatAmount']!=='')$booking['vatAmount']=(float)$booking['vatAmount'];
        $decodedExtras=!empty($booking['extras'])?(is_array($booking['extras'])?$booking['extras']:json_decode((string)$booking['extras'],true)):[];
        $booking['extras']=is_array($decodedExtras)?array_values(array_filter($decodedExtras,'is_array')):[];
    }

    $room=null;
    if($roomNumber!==''){
        $roomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1");$roomStmt->execute([$roomNumber]);$room=$roomStmt->fetch(PDO::FETCH_ASSOC)?:null;
        if($room){
            $room['price']=(int)($room['price']??0);$room['floor']=(int)($room['floor']??0);
            $blockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
            $activeBookingCount=0;$housekeepingCount=0;$maintenanceCount=0;$alertCount=0;$smartLockCount=0;$housekeepingStatuses=[];
            foreach($blockers as $blocker){
                $type=(string)($blocker['type']??'');
                if($type==='active_booking')$activeBookingCount++;
                elseif($type==='housekeeping'){$housekeepingCount++;$housekeepingStatuses[]=(string)($blocker['status']??'open');}
                elseif($type==='maintenance')$maintenanceCount++;
                elseif($type==='alert')$alertCount++;
                elseif($type==='smart_lock')$smartLockCount++;
            }
            $storedStatus=(string)($room['status']??'maintenance');
            $operationalStatus=tamasyaDeriveRoomOperationalStatus($blockers);
            $reservationBlockers=tamasyaReservationInventoryBlockers($blockers);
            $room['operationalStatus']=$operationalStatus;
            $room['isSellable']=$operationalStatus==='available';
            $room['reservationInventoryOpen']=$reservationBlockers===[];
            $room['reservationBlockers']=$reservationBlockers;
            $room['stateMismatch']=$storedStatus!==$operationalStatus;
            $room['operationalBlockers']=$blockers;
            $room['operationalBlockerMessage']=$blockers?roomOperationalBlockerMessage($roomNumber,$blockers):'';
            $room['activeBookingCount']=$activeBookingCount;
            $room['openHousekeepingTaskCount']=$housekeepingCount;
            $room['openHousekeepingStatuses']=array_values(array_unique($housekeepingStatuses));
            $room['openMaintenanceTicketCount']=$maintenanceCount;
            $room['openAlertCount']=$alertCount;
            $room['pendingSmartLockJobCount']=$smartLockCount;
        }
    }

    $transactions=[];$transactionIds=array_values(array_unique(array_filter(array_map('strval',(array)($result['transactionIds']??[])))));
    if($transactionIds){
        $ph=implode(',',array_fill(0,count($transactionIds),'?'));
        $txStmt=$pdo->prepare("SELECT t.id,t.type,t.category,t.categoryId,t.categorySystemKey,t.subcategory,t.subcategoryId,t.subcategorySystemKey,t.amount,t.`date`,t.description,t.createdBy,
            COALESCE(NULLIF(TRIM(st.name),''),NULLIF(TRIM(st.username),''),NULLIF(TRIM(t.createdBy),''),'Sistem') AS createdByName,
            (CASE WHEN t.proofUrl IS NOT NULL AND t.proofUrl != '' THEN 'placeholder_proof' ELSE NULL END) AS proofUrl,
            (CASE WHEN t.proofUrl IS NOT NULL AND t.proofUrl != '' THEN 1 ELSE 0 END) AS hasProof,
            t.roomNumber,t.bankAccountId,t.isSplitPayment,t.splitCashAmount,t.splitTransferAmount,t.splitTransferBankAccountId,t.bookingId,t.bookingSource,t.baseAmount,t.taxAmount,t.taxRate,t.taxSnapshotStatus,t.taxSource,t.taxRuleId,t.taxRuleName,t.taxNote,t.transactionKind,t.sourceEntity,t.sourceEntityId,t.isSystemGenerated,t.operationId,t.documentNumber,t.shiftSessionId,t.lockedAt,t.reconciliationStatus,t.reconciliationReference,t.recordOrigin,t.shiftExempt,t.shiftExemptionReason,t.importBatchId,t.serviceDate,t.historicalSourceType,t.historicalSourceReference,t.sourceReportedBy,t.reportingPeriod,t.periodStatusAtEntry,t.periodImpactStatus,t.requiresTaxAmendment,t.historicalReviewStatus,t.historicalReviewedBy,t.historicalReviewedAt,t.periodCorrectionReason,t.inputDelayDays,t.version,t.updatedAt,t.updatedBy,t.updatedSource,t.createdAt
            FROM transactions t LEFT JOIN staff st ON st.id=t.createdBy
            WHERE t.id IN ({$ph}) ORDER BY t.`date`,t.id");
        $txStmt->execute($transactionIds);$transactions=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($transactions as &$tx){
            $tx=tamasyaEnrichTransactionCatalogIdentity($pdo,(array)$tx);
            $tx['amount']=round((float)($tx['amount']??0),2);
            foreach(['baseAmount','taxAmount','taxRate','splitCashAmount','splitTransferAmount'] as $field)if(isset($tx[$field])&&$tx[$field]!==null)$tx[$field]=(float)$tx[$field];
            // FIX50: split snapshot must reach the browser so per-channel cash
            // reconciliation (Laporan) can attribute cash vs bank correctly.
            $tx['isSplitPayment']=!empty($tx['isSplitPayment']);
            $tx['allocations']=[];$tx['allocatedAmount']=0.0;$tx['unallocatedAmount']=(float)$tx['amount'];
            $tx['transactionKind']=inferTransactionKind($tx);
            $tx['isSystemGenerated']=!empty($tx['isSystemGenerated'])||transactionIsProtected($tx);
        }unset($tx);
    }

    return ['booking'=>$booking,'room'=>$room,'transactions'=>$transactions,'bookingId'=>$bookingId,'roomNumber'=>$roomNumber];
}

/** Source line 5401: getFullHotelData */
function getFullHotelData($pdo, $scopeUser = null) {
    global $db_host, $db_port, $db_name, $db_user, $db_pass, $loggedInStaff;
    $requestRole = strtolower((string)(($scopeUser['role'] ?? null) ?: ($loggedInStaff['role'] ?? '')));
    $scopeIdentity = is_array($scopeUser) ? $scopeUser : (is_array($loggedInStaff) ? $loggedInStaff : ['role' => $requestRole]);
    // Query berat mengikuti hak akses server, bukan hanya nama role. Dengan ini
    // Hak Akses Kustom benar-benar berfungsi tanpa mengirim data silang role.
    $needTransactions = tamasyaCanSeeFinancialData($scopeIdentity);
    $scopeStaffId = trim((string)($scopeIdentity['id'] ?? ''));
    $needChatMessages = $scopeStaffId !== '' && filter_var(getenv('INTERNAL_STAFF_HELP_CHAT_ENABLED')?:'0',FILTER_VALIDATE_BOOLEAN);
    $needTelegramMessages = in_array($requestRole, ['admin', 'manager', 'owner'], true);
    $needActivityLogs = in_array($requestRole,['admin','owner'],true);
    $needSalarySlips = in_array($requestRole, ['admin', 'manager', 'finance', 'owner'], true);
    $needInventory = hasDesktopTabAccess($scopeIdentity, 'inventory', ['admin', 'manager', 'finance', 'receptionist', 'owner']);
    $needShiftReports = in_array($requestRole, ['admin', 'manager', 'finance', 'receptionist', 'owner'], true);
    $dataHealth = ["ok" => true, "errors" => []];
    $markDataError = static function(string $module, Throwable $e) use (&$dataHealth): void {
        $dataHealth["ok"] = false;
        $dataHealth["errors"][] = $module;
        error_log(clientExceptionMessage("[api.php] gagal membaca modul {$module}", $e));
    };
    
    // Fallback default config
    $config = [
        "geminiApiKey" => "",
        "telegramBotToken" => "",
        "initialBalance" => 0,
        "targetInvestment" => 0,
        "dbType" => "mysql",
        "dbHost" => (string)($db_host ?? "localhost"),
        "dbPort" => (string)($db_port ?? 3306),
        "dbName" => (string)($db_name ?? ""),
        "dbUser" => (string)($db_user ?? ""),
        // Jangan pernah menaruh password database pada payload fallback browser.
        "dbPassword" => "",
        "telegramChatIds" => [],
        "bankName" => "",
        "bankAccount" => "",
        "bankRecipient" => "",
        "qrisMerchantName" => "",
        "qrisValue" => "",
        "telegramWebhookActive" => false,
        "smtpHost" => "",
        "smtpPort" => 587,
        "smtpUser" => "",
        "smtpPassword" => "",
        "smtpSecure" => "tls",
        "smtpFrom" => "",
        "checkinTime" => "14:00:00",
        "checkoutTime" => "12:00:00"
    ];

    if (!$pdo) {
        return [
            "rooms" => [],
            "bookings" => [],
            "transactions" => [],
            "publicReservationRequests" => [],
            "notifications" => [
                [
                    "id" => "db_error",
                    "message" => "⚠️ KONEKSI DATABASE GAGAL: Silakan periksa konfigurasi nama database, user, host dan password di menu Pengaturan API / Database.",
                    "timestamp" => date("Y-m-d H:i:s"),
                    "read" => false,
                    "type" => "system"
                ]
            ],
            "chatMessages" => [],
            "telegramMessages" => [],
            // Financial catalog is database-authoritative. When DB is unavailable
            // fail closed instead of inventing business categories/subcategories in PHP.
            "categories" => [],
            "subcategories" => [],
            "subcategoriesByCategoryId" => [],
            "subcategoryCatalog" => [],
            "bankAccounts" => [],
            "taxRules" => [],
            "config" => $config,
            "activityLogs" => [],
            "salarySlips" => [],
            "inventory" => [],
            "inventoryMaintenance" => [],
            "shiftReports" => [],
            "attendance" => [],
            "guestServiceRequests" => [],
            "guestServiceContexts" => [],
            "dataHealth" => ["ok" => false, "errors" => ["database_connection"]]
        ];
    }

    // Rooms
    $rooms = [];
    try {
        $stmt = $pdo->query("SELECT * FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC");
        $rooms = $stmt->fetchAll() ?: [];
        $roomNumbers=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['number']??'')),$rooms),static fn($number)=>$number!==''));
        $blockersByRoom=getRoomOperationalBlockersMap($pdo,$roomNumbers);
        foreach ($rooms as &$r) {
            $r['price'] = (int)$r['price'];
            $r['floor'] = (int)$r['floor'];
            $roomNumber=trim((string)($r['number']??''));
            $blockers=$roomNumber!==''?($blockersByRoom[$roomNumber]??[]):[];
            $activeBookingCount=0;$housekeepingCount=0;$maintenanceCount=0;$alertCount=0;$smartLockCount=0;$housekeepingStatuses=[];
            foreach($blockers as $blocker){
                $type=(string)($blocker['type']??'');
                if($type==='active_booking')$activeBookingCount++;
                elseif($type==='housekeeping'){$housekeepingCount++;$housekeepingStatuses[]=(string)($blocker['status']??'open');}
                elseif($type==='maintenance')$maintenanceCount++;
                elseif($type==='alert')$alertCount++;
                elseif($type==='smart_lock')$smartLockCount++;
            }
            $storedStatus=(string)($r['status']??'maintenance');
            $operationalStatus=tamasyaDeriveRoomOperationalStatus($blockers);
            $reservationBlockers=tamasyaReservationInventoryBlockers($blockers);
            $r['operationalStatus']=$operationalStatus;
            $r['isSellable']=$operationalStatus==='available';
            $r['reservationInventoryOpen']=$reservationBlockers===[];
            $r['reservationBlockers']=$reservationBlockers;
            $r['stateMismatch']=$storedStatus!==$operationalStatus;
            $r['operationalBlockers']=$blockers;
            $r['operationalBlockerMessage']=$blockers?roomOperationalBlockerMessage($roomNumber,$blockers):'';
            $r['activeBookingCount']=$activeBookingCount;
            $r['openHousekeepingTaskCount']=$housekeepingCount;
            $r['openHousekeepingStatuses']=array_values(array_unique($housekeepingStatuses));
            $r['openMaintenanceTicketCount']=$maintenanceCount;
            $r['openAlertCount']=$alertCount;
            $r['pendingSmartLockJobCount']=$smartLockCount;
        }
        unset($r);
    } catch (Throwable $e) { $markDataError("rooms", $e); }

    // Bookings
    $bookings = [];
    try {
        $bookingProjection=tamasyaBookingReadProjection($pdo);
        $stmt = $pdo->query("SELECT ".$bookingProjection['select']." FROM `bookings`".$bookingProjection['order']);
        if(!empty($bookingProjection['missing'])){
            $dataHealth["ok"]=false;
            $dataHealth["errors"][]="bookings_schema_degraded";
            error_log('[api.php] bookings compatibility projection active; missing columns: '.implode(',', $bookingProjection['missing']));
        }
        $bookings = $stmt->fetchAll() ?: [];
        foreach ($bookings as &$b) {
            foreach (['totalAmount','roomCharge','extraCharge','discountAmount','amountPaid','balanceDue','refundAmount','securityDepositRequiredAmount','securityDepositReceived','securityDepositRefunded','securityDepositForfeited','securityDepositHeld','financialClosureBalance','downPaymentAmount','splitCashAmount','splitTransferAmount','lateCheckoutFee'] as $moneyField) {
                $b[$moneyField] = (float)($b[$moneyField] ?? 0);
            }
            if(strtolower((string)($b['status']??''))!=='cancelled'&&array_filter(tamasyaDecodeBookingExtras($b['extras']??null),'tamasyaBookingExtraIsRoomCharge')){
                $b['extraCharge']=min(max(0,$b['totalAmount']-$b['discountAmount']),tamasyaBookingServiceExtrasTotal($b['extras']??null));
                $b['roomCharge']=max(0,$b['totalAmount']-$b['discountAmount']-$b['extraCharge']);
            }
            $b['isOpenEnded'] = !empty($b['isOpenEnded']);
            $b['securityDepositRequired'] = !empty($b['securityDepositRequired']);
            if (isset($b['vatRate']) && $b['vatRate'] !== null && $b['vatRate'] !== "") {
                $b['vatRate'] = (float)$b['vatRate'];
            }
            if (isset($b['vatAmount']) && $b['vatAmount'] !== null && $b['vatAmount'] !== "") {
                $b['vatAmount'] = (float)$b['vatAmount'];
            }
            if (isset($b['extras']) && !empty($b['extras'])) {
                $decodedExtras = is_array($b['extras']) ? $b['extras'] : json_decode((string)$b['extras'], true);
                $b['extras'] = is_array($decodedExtras) ? array_values(array_filter($decodedExtras, 'is_array')) : [];
            } else {
                $b['extras'] = [];
            }
        }
    } catch (Throwable $e) { $markDataError("bookings", $e); }

    // Transactions
    $transactions = [];
    if ($needTransactions) try {
        $stmt = $pdo->query("SELECT t.id,t.type,t.category,t.categoryId,t.categorySystemKey,t.subcategory,t.subcategoryId,t.subcategorySystemKey,t.amount,t.date,t.description,t.createdBy,
            COALESCE(NULLIF(TRIM(st.name),''),NULLIF(TRIM(st.username),''),NULLIF(TRIM(t.createdBy),''),'Sistem') AS createdByName,
            (CASE WHEN t.proofUrl IS NOT NULL AND t.proofUrl != '' THEN 'placeholder_proof' ELSE NULL END) AS proofUrl,
            (CASE WHEN t.proofUrl IS NOT NULL AND t.proofUrl != '' THEN 1 ELSE 0 END) AS hasProof,
            t.roomNumber,t.bankAccountId,t.isSplitPayment,t.splitCashAmount,t.splitTransferAmount,t.splitTransferBankAccountId,t.bookingId,t.bookingSource,t.baseAmount,t.taxAmount,t.taxRate,t.taxSnapshotStatus,t.taxSource,t.taxRuleId,t.taxRuleName,t.taxNote,t.transactionKind,t.sourceEntity,t.sourceEntityId,t.isSystemGenerated,t.operationId,t.documentNumber,t.shiftSessionId,t.lockedAt,t.reconciliationStatus,t.reconciliationReference,t.recordOrigin,t.shiftExempt,t.shiftExemptionReason,t.importBatchId,t.serviceDate,t.historicalSourceType,t.historicalSourceReference,t.sourceReportedBy,t.reportingPeriod,t.periodStatusAtEntry,t.periodImpactStatus,t.requiresTaxAmendment,t.historicalReviewStatus,t.historicalReviewedBy,t.historicalReviewedAt,t.periodCorrectionReason,t.inputDelayDays,t.version,t.updatedAt,t.updatedBy,t.updatedSource,t.createdAt
            FROM transactions t LEFT JOIN staff st ON st.id=t.createdBy
            ORDER BY t.date DESC,t.id DESC");
        $transactions = $stmt->fetchAll() ?: [];
        $allocationRows = [];
        try {
            $allocationRows = $pdo->query("SELECT id,operation_id AS operationId,transaction_id AS transactionId,booking_id AS bookingId,allocation_type AS allocationType,amount,base_amount AS baseAmount,tax_amount AS taxAmount,tax_rate AS taxRate,tax_snapshot_status AS taxSnapshotStatus,tax_source AS taxSource,tax_rule_id AS taxRuleId,category,category_id AS categoryId,category_system_key AS categorySystemKey,subcategory,subcategory_id AS subcategoryId,subcategory_system_key AS subcategorySystemKey,booking_extra_id AS bookingExtraId,description,booking_total_delta AS bookingTotalDelta,previous_check_out AS previousCheckOut,new_check_out AS newCheckOut,extra_was_existing AS extraWasExisting,reporting_only AS reportingOnly,transaction_booking_link_added AS transactionBookingLinkAdded,transaction_room_link_added AS transactionRoomLinkAdded,transaction_source_link_added AS transactionSourceLinkAdded,status,created_by AS createdBy,created_at AS createdAt,voided_by AS voidedBy,voided_at AS voidedAt,void_reason AS voidReason FROM transaction_allocations WHERE status='active' ORDER BY created_at,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $allocationError) {
            // Transaksi utama tetap harus dapat dibaca apabila migration allocation
            // belum selesai. Error dicatat terpisah agar payload keuangan tidak kosong.
            $markDataError("transaction_allocations", $allocationError);
        }
        $allocationsByTransaction = [];
        foreach ($allocationRows as $allocationRow) {
            foreach (['amount','baseAmount','taxAmount','taxRate'] as $allocationMoneyField) {
                $allocationRow[$allocationMoneyField] = (float)($allocationRow[$allocationMoneyField] ?? 0);
            }
            $allocationsByTransaction[(string)$allocationRow['transactionId']][] = $allocationRow;
        }
        $transactionCatalog=tamasyaTransactionCatalogSnapshot($pdo);
        foreach ($transactions as &$t) {
            $t=tamasyaEnrichTransactionCatalogIdentity($pdo,(array)$t,$transactionCatalog);
            $t['amount'] = round((float)$t['amount'],2);
            if (isset($t['baseAmount']) && $t['baseAmount'] !== null) {
                $t['baseAmount'] = (float)$t['baseAmount'];
            }
            if (isset($t['taxAmount']) && $t['taxAmount'] !== null) {
                $t['taxAmount'] = (float)$t['taxAmount'];
            }
            if (isset($t['taxRate']) && $t['taxRate'] !== null) {
                $t['taxRate'] = (float)$t['taxRate'];
            }
            // FIX50: project the split-payment snapshot (cash vs transfer/QRIS)
            // so client-side per-channel kas reports mirror the server journal.
            foreach (['splitCashAmount','splitTransferAmount'] as $splitField) {
                if (isset($t[$splitField]) && $t[$splitField] !== null) {
                    $t[$splitField] = (float)$t[$splitField];
                }
            }
            $t['isSplitPayment'] = !empty($t['isSplitPayment']);
            $t['allocations'] = $allocationsByTransaction[(string)$t['id']] ?? [];
            $workflowAllocationReceipt = count(array_filter($t['allocations'], static fn($row) => !empty($row['transactionBookingLinkAdded']))) > 0;
            $t['transactionKind'] = inferTransactionKind($t);
            // Data legacy pernah menyimpan pembayaran booking sebagai `manual`.
            // Jangan tampilkan sebagai transaksi editable/allocation-eligible kecuali
            // bookingId memang ditambahkan oleh workflow allocation receipt generik.
            if (!empty($t['bookingId']) && $t['transactionKind'] === 'manual' && !$workflowAllocationReceipt) {
                $t['transactionKind'] = 'booking_payment';
                $t['legacyKindNormalized'] = true;
            }
            $t['isSystemGenerated'] = !empty($t['isSystemGenerated']) || transactionIsProtected($t);
            // Server is the financial-classification authority. Frontend reports consume
            // this projection; their JS classifier is only an offline compatibility fallback.
            $t['financialSemantic'] = tamasyaTransactionSemantics($t);
            $t['allocatedAmount'] = array_sum(array_map(static fn($row) => (float)($row['amount'] ?? 0), $t['allocations']));
            $t['unallocatedAmount'] = max(0, (float)$t['amount'] - (float)$t['allocatedAmount']);
        }
    } catch (Throwable $e) { $markDataError("transactions", $e); }

    // Public website reservation inbox. Data ini hanya diproyeksikan kepada
    // Admin, Manager, dan Resepsionis; role lain tidak pernah menerima PII tamu
    // dari formulir publik melalui payload hotel-data.
    $publicReservationRequests = [];
    if (in_array($requestRole, ['admin','manager','receptionist','owner'], true)) {
        try {
            $publicReservationTableExists = tamasyaSchemaTableExists($pdo,'public_reservation_requests');
            if ($publicReservationTableExists) {
                $stmtPublicRequests = $pdo->query("SELECT pr.id,pr.public_request_id,pr.guest_name,pr.whatsapp,pr.email,pr.check_in,pr.check_out,pr.guest_count,pr.room_type_id,pr.extra_request,pr.notes,pr.source,pr.status,pr.reviewed_by,pr.reviewed_at,pr.linked_booking_id,pr.rejection_reason,pr.created_at,pr.updated_at,prt.name AS published_room_type_name
                    FROM public_reservation_requests pr
                    LEFT JOIN public_room_types prt ON prt.id=pr.room_type_id
                    WHERE pr.status IN ('pending_review','reviewing','converted','rejected')
                    ORDER BY FIELD(pr.status,'pending_review','reviewing','converted','rejected'),pr.created_at DESC
                    LIMIT 200");
                $rowsPublicRequests = $stmtPublicRequests ? ($stmtPublicRequests->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                foreach ($rowsPublicRequests as $rowPublicRequest) {
                    $roomTypeId = (string)($rowPublicRequest['room_type_id'] ?? '');
                    $roomTypeName = trim((string)($rowPublicRequest['published_room_type_name'] ?? ''));
                    if ($roomTypeName === '' && str_starts_with($roomTypeId, 'derived_')) {
                        $roomTypeName = strtoupper(str_replace(['derived_','-','_'], ['', ' ', ' '], $roomTypeId));
                    }
                    $publicReservationRequests[] = [
                        'id'=>(string)($rowPublicRequest['id'] ?? ''),
                        'publicRequestId'=>(string)($rowPublicRequest['public_request_id'] ?? ''),
                        'guestName'=>(string)($rowPublicRequest['guest_name'] ?? ''),
                        'whatsapp'=>(string)($rowPublicRequest['whatsapp'] ?? ''),
                        'email'=>(string)($rowPublicRequest['email'] ?? ''),
                        'checkIn'=>(string)($rowPublicRequest['check_in'] ?? ''),
                        'checkOut'=>(string)($rowPublicRequest['check_out'] ?? ''),
                        'guestCount'=>(int)($rowPublicRequest['guest_count'] ?? 1),
                        'roomTypeId'=>$roomTypeId,
                        'roomTypeName'=>$roomTypeName,
                        'extraRequest'=>(string)($rowPublicRequest['extra_request'] ?? ''),
                        'notes'=>(string)($rowPublicRequest['notes'] ?? ''),
                        'source'=>(string)($rowPublicRequest['source'] ?? 'website'),
                        'status'=>(string)($rowPublicRequest['status'] ?? 'pending_review'),
                        'reviewedBy'=>$rowPublicRequest['reviewed_by'] ?? null,
                        'reviewedAt'=>$rowPublicRequest['reviewed_at'] ?? null,
                        'linkedBookingId'=>$rowPublicRequest['linked_booking_id'] ?? null,
                        'rejectionReason'=>$rowPublicRequest['rejection_reason'] ?? null,
                        'createdAt'=>(string)($rowPublicRequest['created_at'] ?? ''),
                        'updatedAt'=>(string)($rowPublicRequest['updated_at'] ?? '')
                    ];
                }
            }
        } catch (Throwable $publicRequestError) {
            $markDataError('public_reservation_requests', $publicRequestError);
        }
    }

    // Notifications
    $notifications = [];
    try {
        $stmt = $pdo->query("SELECT * FROM notifications ORDER BY timestamp DESC LIMIT 50");
        $notifications = $stmt->fetchAll() ?: [];
        foreach ($notifications as &$n) {
            $n['read'] = (bool)$n['read'];
        }
    } catch (Throwable $e) { $markDataError("notifications", $e); }

    // Reservasi website kini membuat row notifications persisten saat request
    // berhasil disimpan. Jangan membangun notifikasi virtual dari PII request:
    // status baca harus per-user dan role Keuangan hanya menerima pesan aman.
    usort($notifications, static fn($a,$b) => strcmp((string)($b['timestamp'] ?? ''),(string)($a['timestamp'] ?? '')));
    $notifications = array_slice($notifications,0,100);

    // Chat Messages
    $chatMessages = [];
    if ($needChatMessages) try {
        $stmt = $pdo->prepare("SELECT id,staff_id,sender,text,timestamp FROM chat_messages WHERE staff_id=? ORDER BY timestamp DESC LIMIT 100");
        $stmt->execute([$scopeStaffId]);
        $chatMessages = $stmt->fetchAll() ?: [];
        $chatMessages = array_reverse($chatMessages);
        // Map 'text' column to 'message' for the React frontend chat log
        foreach ($chatMessages as &$msg) {
            $msg['message'] = $msg['text'] ?? '';
        }
    } catch (Throwable $e) { $markDataError("chat_messages", $e); }

    // Telegram Messages
    $telegramMessages = [];
    if ($needTelegramMessages) try {
        $stmt = $pdo->query("SELECT * FROM telegram_messages ORDER BY timestamp DESC LIMIT 150");
        $telegramMessages = $stmt->fetchAll() ?: [];
        $telegramMessages = array_reverse($telegramMessages);
        foreach ($telegramMessages as &$tm) {
            $tm['isAi'] = (bool)$tm['isAi'];
        }
    } catch (Throwable $e) { $markDataError("telegram_messages", $e); }

    // Categories: active catalog only; system metadata is explicit for the UI.
    $categories = [];
    try {
        $stmt = $pdo->query("SELECT id,name,type,system_key AS systemKey,is_system AS isSystem,is_active AS isActive FROM categories WHERE is_active=1 ORDER BY type,name,id");
        $categories = $stmt->fetchAll() ?: [];
        foreach($categories as &$categoryRow){$categoryRow['isSystem']=(bool)$categoryRow['isSystem'];$categoryRow['isActive']=(bool)$categoryRow['isActive'];}
        unset($categoryRow);
    } catch (Throwable $e) { $markDataError("categories", $e); }

    // Explicit semantic bindings are the workflow/accounting authority.
    $financeSemanticBindings = [];
    try {
        $financeSemanticBindings = tamasyaFinanceSemanticBindingsSnapshot($pdo);
    } catch (Throwable $e) { $markDataError("categories.system_key", $e); }

    // FIX25: configurable room-type labels come from active subcategories under
    // the semantic room_rental category. rooms.type remains the operational
    // snapshot; when no master has been configured yet, legacy room labels are
    // exposed as a compatibility fallback.
    $roomTypes = [];
    try {
        $counts=[];
        $stmt=$pdo->query("SELECT LOWER(TRIM(type)) AS typeKey,COUNT(*) AS roomCount FROM rooms WHERE TRIM(COALESCE(type,''))<>'' GROUP BY LOWER(TRIM(type))");
        foreach(($stmt->fetchAll()?:[]) as $countRow)$counts[(string)($countRow['typeKey']??'')]=(int)($countRow['roomCount']??0);
        $masterState=tamasyaRoomTypeMasterState($pdo,false);
        $master=$masterState['items'];
        if($masterState['configured']){
            foreach($master as $roomTypeRow){
                $name=trim((string)($roomTypeRow['name']??''));
                $roomTypes[]=['id'=>(string)($roomTypeRow['id']??''),'name'=>$name,'isActive'=>true,'roomCount'=>$counts[mb_strtolower($name)]??0,'source'=>'finance_room_revenue_subcategory'];
            }
        }else{
            $stmt=$pdo->query("SELECT TRIM(type) AS name,COUNT(*) AS roomCount FROM rooms WHERE TRIM(COALESCE(type,''))<>'' GROUP BY TRIM(type) ORDER BY TRIM(type)");
            foreach(($stmt->fetchAll()?:[]) as $roomTypeRow){
                $name=trim((string)($roomTypeRow['name']??''));
                $roomTypes[]=['id'=>'legacy_type_'.substr(hash('sha256',strtolower($name)),0,40),'name'=>$name,'isActive'=>true,'roomCount'=>(int)($roomTypeRow['roomCount']??0),'source'=>'legacy_rooms_type_fallback'];
            }
        }
    } catch (Throwable $e) { $markDataError("room_type_master", $e); }

    // Subcategories are keyed by immutable category ID. Legacy name map is returned only for old clients.
    $subcategories = [];
    $subcategoriesByCategoryId = [];
    $subcategoryCatalog = [];
    try {
        $stmt = $pdo->query("SELECT s.id,s.category_id AS categoryId,c.name AS categoryName,c.type AS categoryType,s.name,s.system_key AS systemKey,s.is_system AS isSystem,s.is_active AS isActive
            FROM subcategories s JOIN categories c ON c.id=s.category_id
            WHERE s.is_active=1 AND c.is_active=1 ORDER BY c.type,c.name,s.name,s.id");
        $subRows = $stmt->fetchAll() ?: [];
        foreach ($subRows as $sub) {
            $categoryId=(string)$sub['categoryId'];$catName=(string)$sub['categoryName'];
            $sub['isSystem']=(bool)$sub['isSystem'];$sub['isActive']=(bool)$sub['isActive'];
            $subcategoryCatalog[]=$sub;
            if(!isset($subcategoriesByCategoryId[$categoryId]))$subcategoriesByCategoryId[$categoryId]=[];
            $subcategoriesByCategoryId[$categoryId][]=(string)$sub['name'];
            if(!isset($subcategories[$catName]))$subcategories[$catName]=[];
            if(!in_array((string)$sub['name'],$subcategories[$catName],true))$subcategories[$catName][]=(string)$sub['name'];
        }
    } catch (Throwable $e) { $markDataError("subcategories", $e); }

    // Bank Accounts
    $bankAccounts = [];
    try {
        $stmt = $pdo->query("SELECT * FROM bank_accounts ORDER BY name ASC");
        $bankAccounts = $stmt->fetchAll() ?: [];
        foreach ($bankAccounts as &$ba) {
            $ba['isActive'] = isset($ba['isActive']) ? (bool)$ba['isActive'] : true;
        }
    } catch (Throwable $e) { $markDataError("bank_accounts", $e); }

    // Tax rules are non-secret policy data and are also cached for offline calculations.
    $taxRules = [];
    try {
        $stmt = $pdo->query("SELECT id,name,source_pattern,transaction_kind,taxable,rate,priority,effective_from,effective_until,is_active,created_by,created_at,updated_at FROM tax_rules ORDER BY priority DESC, created_at DESC");
        $taxRules = $stmt->fetchAll() ?: [];
        foreach ($taxRules as &$taxRule) {
            $taxRule['taxable'] = (bool)$taxRule['taxable'];
            $taxRule['rate'] = (float)$taxRule['rate'];
            $taxRule['priority'] = (int)$taxRule['priority'];
            $taxRule['is_active'] = (bool)$taxRule['is_active'];
        }
        unset($taxRule);
    } catch (Throwable $e) { $markDataError("tax_rules", $e); }

    // Get Config
    $config = [
        "geminiApiKey" => "",
        "telegramBotToken" => "",
        "initialBalance" => 0,
        "targetInvestment" => 0,
        "dbType" => "mysql",
        "dbHost" => (string)($db_host ?? "localhost"),
        "dbPort" => (string)($db_port ?? 3306),
        "dbName" => (string)($db_name ?? ""),
        "dbUser" => (string)($db_user ?? ""),
        "dbPassword" => "",
        "telegramChatIds" => [],
        "bankName" => "",
        "bankAccount" => "",
        "bankRecipient" => "",
        "qrisMerchantName" => "",
        "qrisValue" => "",
        "telegramWebhookActive" => false,
        "smtpHost" => "",
        "smtpPort" => 587,
        "smtpUser" => "",
        "smtpPassword" => "",
        "smtpSecure" => "tls",
        "smtpFrom" => "",
        "checkinTime" => "14:00:00",
        "checkoutTime" => "12:00:00"
    ];

    try {
        $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
        $confRow = $stmt->fetch();
        if ($confRow) {
            $config["geminiApiKey"] = decryptStoredSecret($confRow['gemini_api_key'] ?? '');
            $config["telegramBotToken"] = decryptStoredSecret($confRow['telegram_bot_token'] ?? '');
            $config["initialBalance"] = isset($confRow['initial_balance']) ? (float)$confRow['initial_balance'] : 0;
            $config["targetInvestment"] = isset($confRow['target_investment']) ? (float)$confRow['target_investment'] : 0;
            // Koneksi aktif berasal dari environment/file credential, bukan salinan lama di tabel config.
            $config["dbType"] = 'mysql';
            $config["dbHost"] = (string)($db_host ?? 'localhost');
            $config["dbPort"] = (string)($db_port ?? 3306);
            $config["dbName"] = (string)($db_name ?? '');
            $config["dbUser"] = (string)($db_user ?? '');
            $config["dbPassword"] = (string)($db_pass ?? '');
            $config["bankName"] = $confRow['bank_name'] ?? '';
            $config["bankAccount"] = $confRow['bank_account'] ?? '';
            $config["bankRecipient"] = $confRow['bank_recipient'] ?? '';
            $config["qrisMerchantName"] = $confRow['qris_merchant_name'] ?? '';
            $config["qrisValue"] = $confRow['qris_value'] ?? '';
            $config["telegramWebhookActive"] = isset($confRow['telegram_webhook_active']) ? (bool)$confRow['telegram_webhook_active'] : false;
            $config["smtpHost"] = $confRow['smtp_host'] ?? '';
            $config["smtpPort"] = isset($confRow['smtp_port']) ? (int)$confRow['smtp_port'] : 587;
            $config["smtpUser"] = $confRow['smtp_user'] ?? '';
            $config["smtpPassword"] = decryptStoredSecret($confRow['smtp_password'] ?? '');
            $config["smtpSecure"] = $confRow['smtp_secure'] ?? 'tls';
            $config["smtpFrom"] = $confRow['smtp_from'] ?? '';

            $legacyTelegramChatIds = $confRow['telegram_chat_ids'] ?? ($confRow['telegram_chat_id'] ?? ($confRow['chat_id'] ?? ''));
            if (!empty($legacyTelegramChatIds)) {
                $config["telegramChatIds"] = parseTelegramChatIds($legacyTelegramChatIds);
            }
        }
    } catch (Throwable $e) {
        $markDataError("config", $e);
    }

    // Jam operasional bukan rahasia. UI inventory dan overlap harus memakai clock
    // canonical yang sama dengan server, bukan asumsi lokal 00:00/12:00.
    try {
        $opsClockStmt=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1");
        $opsClocks=$opsClockStmt?$opsClockStmt->fetch(PDO::FETCH_ASSOC):false;
        if($opsClocks){
            $config["checkinTime"]=substr((string)($opsClocks['checkin_time']??'14:00:00'),0,8);
            $config["checkoutTime"]=substr((string)($opsClocks['checkout_time']??'12:00:00'),0,8);
        }
    } catch (Throwable $e) {
        $markDataError("hotel_operational_settings_clock", $e);
    }

    try {
        $stmtRev = $pdo->query("SELECT server_revision FROM config WHERE id = 'system_default' LIMIT 1");
        $config["serverRevision"] = $stmtRev ? (int)$stmtRev->fetchColumn() : 0;
    } catch (Throwable $e) {
        $config["serverRevision"] = 0;
        $markDataError("server_revision", $e);
    }

    // Jangan pernah mengirim nilai rahasia aktual ke browser/IndexedDB, termasuk untuk Admin.
    // Placeholder dipertahankan saat config disimpan sehingga nilai lama tidak terhapus.
    $isAdminRequest = $requestRole === 'admin';
    $config["hasGeminiApiKey"] = !empty($config["geminiApiKey"]);
    $config["hasTelegramBotToken"] = !empty($config["telegramBotToken"]);
    $config["hasDbPassword"] = !empty($config["dbPassword"]);
    $config["hasSmtpPassword"] = !empty($config["smtpPassword"]);
    $config["geminiApiKey"] = $isAdminRequest && $config["hasGeminiApiKey"] ? "********" : "";
    $config["telegramBotToken"] = $isAdminRequest && $config["hasTelegramBotToken"] ? "********" : "";
    $config["dbPassword"] = $isAdminRequest && $config["hasDbPassword"] ? "********" : "";
    $config["smtpPassword"] = $isAdminRequest && $config["hasSmtpPassword"] ? "********" : "";
    if (!$isAdminRequest) {
        $config["dbHost"] = "";
        $config["dbName"] = "";
        $config["dbUser"] = "";
        $config["smtpHost"] = "";
        $config["smtpUser"] = "";
        $config["telegramChatIds"] = [];
    }

    // Activity Logs
    $activityLogs = [];
    if ($needActivityLogs) try {
        $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY timestamp DESC LIMIT 200");
        $activityLogs = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) { $markDataError("activity_logs", $e); }

    // Salary Slips
    $salarySlips = [];
    if ($needSalarySlips) try {
        $stmt = $pdo->query("SELECT id, staff_id AS staffId, staff_name AS staffName, period, basic_salary AS basicSalary, allowances, deductions, bonus, net_salary AS netSalary, notes, detailed_allowances AS detailedAllowances, detailed_deductions AS detailedDeductions, status, payment_method AS paymentMethod, bank_account_id AS bankAccountId, paid_at AS paidAt, created_at AS createdAt, updated_at AS updatedAt FROM salary_slips ORDER BY created_at DESC");
        $salarySlips = $stmt->fetchAll() ?: [];
        foreach ($salarySlips as &$slip) {
            $slip['basicSalary'] = (float)$slip['basicSalary'];
            $slip['allowances'] = (float)$slip['allowances'];
            $slip['deductions'] = (float)$slip['deductions'];
            $slip['bonus'] = (float)$slip['bonus'];
            $slip['netSalary'] = (float)$slip['netSalary'];
            $slip['detailedAllowances'] = !empty($slip['detailedAllowances']) ? json_decode($slip['detailedAllowances'], true) : [];
            $slip['detailedDeductions'] = !empty($slip['detailedDeductions']) ? json_decode($slip['detailedDeductions'], true) : [];
        }
    } catch (Throwable $e) { $markDataError("salary_slips", $e); }

    // Inventory
    $inventory = [];
    if ($needInventory) try {
        $stmt = $pdo->query("SELECT * FROM inventory ORDER BY name ASC");
        $inventory = $stmt->fetchAll() ?: [];
        foreach ($inventory as &$inv) {
            $inv['quantity'] = (int)$inv['quantity'];
            $inv['price'] = (float)$inv['price'];
        }
    } catch (Throwable $e) { $markDataError("inventory", $e); }

    // Inventory Maintenance
    $inventoryMaintenance = [];
    if ($needInventory) try {
        $stmt = $pdo->query("SELECT id, inventory_id AS inventory_id, maintenance_date AS maintenance_date, action_taken AS action_taken, cost, staff_name AS staff_name, notes, created_at FROM inventory_maintenance ORDER BY maintenance_date DESC, id DESC");
        $inventoryMaintenance = $stmt->fetchAll() ?: [];
        foreach ($inventoryMaintenance as &$maint) {
            $maint['cost'] = (float)$maint['cost'];
        }
    } catch (Throwable $e) { $markDataError("inventory_maintenance", $e); }

    // Shift Reports
    $shiftReports = [];
    if ($needShiftReports) try {
        // V137 canonical: Resepsionis hanya menerima shift miliknya/pendampingnya pada
        // projection akhir. Terapkan scope yang sama langsung di SQL agar histori
        // seluruh hotel tidak perlu dibaca lalu dibuang di PHP. Admin/Manager/Finance
        // Admin/Manager tetap menerima dataset penuh.
        if ($requestRole === 'receptionist' && $scopeStaffId !== '') {
            $stmt = $pdo->prepare("SELECT id, staffId, companionStaffId, shiftSessionId, staffName, shiftDate, shiftTime, startingCash, expectedCash, actualPhysicalCash, variance, digitalRevenue, transactionsCount, notes, createdAt FROM shift_reports WHERE staffId=? OR companionStaffId=? ORDER BY shiftDate DESC, createdAt DESC");
            $stmt->execute([$scopeStaffId,$scopeStaffId]);
        } else {
            $stmt = $pdo->query("SELECT id, staffId, companionStaffId, shiftSessionId, staffName, shiftDate, shiftTime, startingCash, expectedCash, actualPhysicalCash, variance, digitalRevenue, transactionsCount, notes, createdAt FROM shift_reports ORDER BY shiftDate DESC, createdAt DESC");
        }
        $shiftReports = $stmt->fetchAll() ?: [];
        foreach ($shiftReports as &$sr) {
            $sr['startingCash'] = (float)$sr['startingCash'];
            $sr['expectedCash'] = (float)$sr['expectedCash'];
            $sr['actualPhysicalCash'] = (float)$sr['actualPhysicalCash'];
            $sr['variance'] = (float)$sr['variance'];
            $sr['digitalRevenue'] = (float)$sr['digitalRevenue'];
            $sr['transactionsCount'] = (int)$sr['transactionsCount'];
        }
    } catch (Throwable $e) { $markDataError("shift_reports", $e); }

    // Attendance
    $attendance = [];
    try {
        // V137 canonical: role selain Admin/Manager diproyeksikan hanya ke absensi
        // miliknya sendiri. Query langsung by staff_id menjaga hasil tetap identik
        // sambil menghindari full-table read pada hotel dengan histori
        // absensi bertahun-tahun.
        if (!in_array($requestRole, ['admin','manager','owner'], true) && $scopeStaffId !== '') {
            $stmt = $pdo->prepare("SELECT id, staff_id AS staffId, staff_name AS staffName, date, clock_in AS clockIn, clock_out AS clockOut, method, location, status, verification_id AS verificationId, device_id AS deviceId, verification_score AS verificationScore, verified_at AS verifiedAt, clock_out_verification_id AS clockOutVerificationId, clock_out_verified_at AS clockOutVerifiedAt, clock_out_source_event_id AS clockOutSourceEventId, clock_out_location AS clockOutLocation, notes, created_at AS createdAt FROM attendance WHERE staff_id=? ORDER BY date DESC, clock_in DESC");
            $stmt->execute([$scopeStaffId]);
        } else {
            $stmt = $pdo->query("SELECT id, staff_id AS staffId, staff_name AS staffName, date, clock_in AS clockIn, clock_out AS clockOut, method, location, status, verification_id AS verificationId, device_id AS deviceId, verification_score AS verificationScore, verified_at AS verifiedAt, clock_out_verification_id AS clockOutVerificationId, clock_out_verified_at AS clockOutVerifiedAt, clock_out_source_event_id AS clockOutSourceEventId, clock_out_location AS clockOutLocation, notes, created_at AS createdAt FROM attendance ORDER BY date DESC, clock_in DESC");
        }
        $attendance = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) { $markDataError("attendance", $e); }

    // Minimal canonical booking context for Guest Service. Field roles receive
    // active room ownership without booking PII; Front Office leadership may
    // also see reserved/pre-arrival context. This avoids exposing full booking
    // payload merely so a staff member can select the correct room.
    $guestServiceContexts = [];
    if (!in_array($requestRole,['finance'],true) && hasDesktopTabAccess($scopeIdentity,'operations',['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'])) try {
        if (in_array($requestRole,['admin','manager','receptionist','owner'],true)) {
            $stmt = $pdo->query("SELECT id,guestName,roomNumber,status,checkIn,checkOut FROM bookings WHERE status IN ('active','reserved') ORDER BY FIELD(status,'active','reserved'),CAST(roomNumber AS UNSIGNED),checkIn,id");
            $guestServiceContexts = $stmt->fetchAll() ?: [];
        } else {
            $stmt = $pdo->query("SELECT id,'Tamu' AS guestName,roomNumber,status,checkIn,checkOut FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),id");
            $guestServiceContexts = $stmt->fetchAll() ?: [];
        }
    } catch (Throwable $e) { $markDataError('guest_service_contexts', $e); }

    // Guest service requests are operational lifecycle records, not paid extras and not room blockers.
    $guestServiceRequests = [];
    if (!in_array($requestRole,['finance'],true) && hasDesktopTabAccess($scopeIdentity,'operations',['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'])) try {
        $stmt = $pdo->query("SELECT * FROM guest_service_requests ORDER BY FIELD(status,'open','assigned','in_progress','fulfilled','cancelled'),FIELD(priority,'urgent','high','normal'),created_at DESC LIMIT 500");
        $guestServiceRequests = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) { $markDataError('guest_service_requests', $e); }

    // Minimize sensitive modules in the shared hotel-data payload. UI tab hiding is not authorization.
    if (!in_array($requestRole, ['admin','manager','owner'], true)) $activityLogs = [];
    if (!in_array($requestRole, ['admin','manager','finance','owner'], true)) $salarySlips = [];
    if (!in_array($requestRole, ['admin','manager','finance','receptionist','owner'], true)) $shiftReports = [];

    return [
        "rooms" => $rooms,
        "bookings" => $bookings,
        "transactions" => $transactions,
        "publicReservationRequests" => $publicReservationRequests,
        "notifications" => $notifications,
        "chatMessages" => $chatMessages,
        "telegramMessages" => $telegramMessages,
        "categories" => $categories,
        "financeSemanticBindings" => $financeSemanticBindings,
        "roomTypes" => $roomTypes,
        "subcategories" => $subcategories,
        "subcategoriesByCategoryId" => $subcategoriesByCategoryId,
        "subcategoryCatalog" => $subcategoryCatalog,
        "bankAccounts" => $bankAccounts,
        "taxRules" => $taxRules,
        "config" => $config,
        "activityLogs" => $activityLogs,
        "salarySlips" => $salarySlips,
        "inventory" => $inventory,
        "inventoryMaintenance" => $inventoryMaintenance,
        "shiftReports" => $shiftReports,
        "attendance" => $attendance,
        "guestServiceRequests" => $guestServiceRequests,
        "guestServiceContexts" => $guestServiceContexts,
        "dataHealth" => $dataHealth
    ];
}


/**
 * Minimal active payment-account master for front-office room operations.
 *
 * Receptionists need an account selector for transfer/QRIS even when they do
 * not have Finance/Report access. This projection deliberately contains no
 * transaction ledger, category master, or finance-report data.
 */
function tamasyaOperationalPaymentAccounts(array $rows): array {
    $out=[];
    foreach($rows as $row){
        if(!is_array($row) || empty($row['isActive'])) continue;
        $type=strtolower(trim((string)($row['type']??'')));
        if(!in_array($type,['bank','edc_qris'],true)) continue; // operational room roles intentionally exclude card/EDC
        $id=trim((string)($row['id']??''));
        $name=trim((string)($row['name']??''));
        if($id==='' || $name==='') continue;
        $out[]=[
            'id'=>$id,
            'name'=>$name,
            'type'=>$type,
            'isActive'=>true,
        ];
    }
    return $out;
}

function tamasyaSanitizeInventoryOperationalRow(array $row): array {
    if (array_key_exists('price', $row)) $row['price'] = 0.0;
    if (array_key_exists('cost', $row)) $row['cost'] = 0.0;
    unset($row['transaction_id'], $row['approval_request_id'], $row['expenseTransactionId'], $row['approvalRequestId']);
    if (isset($row['notes']) && is_string($row['notes'])) {
        $decoded = json_decode($row['notes'], true);
        if (is_array($decoded) && isset($decoded['logs']) && is_array($decoded['logs'])) {
            foreach ($decoded['logs'] as &$log) if (is_array($log)) unset($log['price']);
            unset($log);
            $row['notes'] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $row;
}

function tamasyaCanSeeFinancialData($user): bool {
    return hasDesktopTabAccess($user,'finance',['admin','manager','finance','owner'])
        || hasDesktopTabAccess($user,'report',['admin','manager','finance','owner'])
        || hasCapability($user,'correct_booking_audit',['admin','manager','finance','owner']);
}

function tamasyaCanSeeFinancialNotifications($user): bool {
    return tamasyaCanSeeFinancialData($user);
}

function tamasyaCanSeeRoomNotifications($user): bool {
    return tamasyaHasRoomsWorkspaceAccess($user);
}

function tamasyaCanSeePublicSupportNotifications($user): bool {
    return hasDesktopTabAccess($user,'support',['admin','manager','receptionist','owner']);
}

function tamasyaNotificationTypeVisibleToUser($user, string $type): bool {
    $type=strtolower(trim($type));
    if($type==='finance')return tamasyaCanSeeFinancialNotifications($user);
    if($type==='booking')return tamasyaCanSeeRoomNotifications($user);
    if($type==='public_reservation')return tamasyaCanSeeRoomNotifications($user)||tamasyaCanSeeFinancialNotifications($user);
    if($type==='public_support')return tamasyaCanSeePublicSupportNotifications($user);
    return true;
}

/** Source line 5829: getRoleScopedHotelData */
function getRoleScopedHotelData($pdo, $user) {
    $data = getFullHotelData($pdo, $user);
    $role = strtolower((string)($user['role'] ?? ''));
    $staffId = (string)($user['id'] ?? '');
    $permissions = $user['permissions'] ?? null;
    if (is_string($permissions) && $permissions !== '') $permissions = json_decode($permissions, true);
    $canSeeFinancialData = tamasyaCanSeeFinancialData($user);
    // Notifikasi merupakan data bersama, tetapi isi finance/booking dapat memuat
    // nominal atau identitas operasional. Filter dilakukan di backend agar menu
    // tersembunyi tidak dapat dilewati melalui hotel-data langsung.
    $data['notifications'] = array_values(array_filter($data['notifications'] ?? [], static function($notification) use ($user) {
        return tamasyaNotificationTypeVisibleToUser($user,(string)($notification['type']??'system'));
    }));
    $data['businessPolicy'] = tamasyaBusinessPolicyProjection();
    // Konteks mata uang + peringatan pencampuran. Laporan yang menjumlahkan
    // nominal wajib memeriksa mixedCurrencies; aplikasi tidak pernah
    // menjumlahkan mata uang berbeda tanpa tanda.
    if (function_exists('tamasyaTransactionCurrencyGuard')) {
        $data['currencyContext'] = tamasyaTransactionCurrencyGuard($pdo);
    }
    $data['currentUser'] = [
        'id' => $staffId,
        'name' => (string)($user['name'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'role' => $role,
        'permissions' => tamasyaSessionPermissions($user,$pdo),
        'hotelScopeId' => tamasyaHotelScopeId(),
        // Mata uang dan locale property: frontend memakai ini untuk format
        // nominal, sehingga tampilan mengikuti hotel dan bukan asumsi IDR.
        'currency' => function_exists('tamasyaPropertyCurrency') ? tamasyaPropertyCurrency($pdo) : 'IDR',
        'locale' => function_exists('tamasyaPropertyLocale') ? tamasyaPropertyLocale($pdo) : 'id-ID'
    ];

    // Status baca notifikasi bersifat per-user agar satu staf tidak menandai
    // notifikasi sebagai dibaca untuk seluruh pengguna pada perangkat lain.
    if ($staffId !== '' && !empty($data['notifications'])) {
        try {
            $stmtNotificationReads = $pdo->prepare("SELECT notification_id FROM notification_reads WHERE staff_id = ?");
            $stmtNotificationReads->execute([$staffId]);
            $readNotificationIds = array_fill_keys(array_map('strval', $stmtNotificationReads->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
            foreach ($data['notifications'] as &$notificationRow) {
                $notificationRow['read'] = isset($readNotificationIds[(string)($notificationRow['id'] ?? '')]);
            }
            unset($notificationRow);
        } catch (Throwable $notificationReadError) {
            error_log('[api.php] gagal membaca status notifikasi per-user: ' . $notificationReadError->getMessage());
            foreach ($data['notifications'] as &$notificationRow) $notificationRow['read'] = false;
            unset($notificationRow);
            $data['dataHealth']['ok'] = false;
            $data['dataHealth']['errors'][] = 'notification_reads';
        }
    }

    $ownAttendance = static function(array $rows) use ($staffId): array {
        return array_values(array_filter($rows, static fn($row) =>
            (string)($row['staffId'] ?? '') === $staffId
        ));
    };
    $stripBookingIdentity = static function(array $booking, bool $keepName = false): array {
        unset($booking['guestEmail'], $booking['guestPhone'], $booking['ktpPhoto'], $booking['identityNumber']);
        if (!$keepName) $booking['guestName'] = 'Tamu';
        return $booking;
    };
    // Projection layer terakhir: explicit desktop deny harus mengurangi DATA, bukan hanya tombol/route.
    // Fallback role tetap kompatibel bila key desktopTabs belum pernah disimpan.
    $applyDesktopDataScope = static function(array $scoped) use ($user): array {
        $roomsAllowed=tamasyaHasRoomsWorkspaceAccess($user);
        $financeAllowed=hasDesktopTabAccess($user,'finance',['admin','manager','finance','owner']);
        $reportAllowed=hasDesktopTabAccess($user,'report',['admin','manager','finance','owner']);
        $inventoryAllowed=hasDesktopTabAccess($user,'inventory',['admin','manager','finance','receptionist','koki','cleaning_service']);
        $attendanceAllowed=hasDesktopTabAccess($user,'attendance',['admin','manager','receptionist','finance','koki','tukang_kebun','cleaning_service','keamanan','lain_lain']);
        $operationsAllowed=hasDesktopTabAccess($user,'operations',['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
        $staffAllowed=hasDesktopTabAccess($user,'staff',['admin']);
        $websiteAllowed=hasDesktopTabAccess($user,'website',['admin','manager','owner']);
        $telegramAllowed=hasDesktopTabAccess($user,'telegram',['admin','manager','receptionist','finance','koki','tukang_kebun','cleaning_service','keamanan','lain_lain']);
        $supportAllowed=hasDesktopTabAccess($user,'support',['admin','manager','receptionist','owner']);
        if(!$roomsAllowed){$scoped['rooms']=[];$scoped['bookings']=[];}
        if(!$roomsAllowed&&!$websiteAllowed)$scoped['publicReservationRequests']=[];
        if(!$financeAllowed&&!$reportAllowed){
            $scoped['transactions']=[];$scoped['categories']=[];$scoped['subcategories']=[];
            $scoped['subcategoriesByCategoryId']=[];$scoped['subcategoryCatalog']=[];
            // Front office yang memang berhak mengoperasikan Rooms tetap membutuhkan
            // master rekening aktif untuk memilih tujuan Transfer/QRIS. Ini bukan
            // akses Finance: ledger/transaksi/kategori tetap tidak diproyeksikan.
            $scoped['bankAccounts']=(
                $roomsAllowed && strtolower((string)($user['role']??''))==='receptionist'
            ) ? tamasyaOperationalPaymentAccounts($scoped['bankAccounts']??[]) : [];
            // Tarif pajak adalah master pricing read-only yang dibutuhkan oleh form
            // Reservasi / Check-In. Jangan mengosongkannya hanya karena staf tidak
            // memiliki tab Finance/Report; resepsionis/pendamping yang berhak menjual
            // kamar wajib melihat quote PBJT yang sama dengan Admin.
            if(!$roomsAllowed)$scoped['taxRules']=[];
        }
        if(!$inventoryAllowed){$scoped['inventory']=[];$scoped['inventoryMaintenance']=[];}
        if(!$attendanceAllowed)$scoped['attendance']=[];
        if(!$operationsAllowed){$scoped['guestServiceRequests']=[];$scoped['guestServiceContexts']=[];if(!$reportAllowed)$scoped['shiftReports']=[];}
        if(!$staffAllowed&&!$financeAllowed)$scoped['salarySlips']=[];
        if(!$telegramAllowed)$scoped['telegramMessages']=[];
        if(!$supportAllowed)$scoped['chatMessages']=[];
        return $scoped;
    };

    if ($role === 'admin' || tamasyaIsOwnerRole($user)) return $applyDesktopDataScope($data);

    // Secret sudah dimask oleh getFullHotelData; modul konfigurasi sensitif tetap Admin-only.
    $data['activityLogs'] = [];

    if ($role === 'manager') {
        // Manager boleh melihat operasional dan laporan, tetapi tidak menerima token/password.
        return $applyDesktopDataScope($data);
    }

    if ($role === 'finance') {
        $data['publicReservationRequests'] = [];
        $data['bookings'] = array_map(static fn($b) => $stripBookingIdentity($b, true), $data['bookings'] ?? []);
        $data['telegramMessages'] = [];
        $data['attendance'] = $ownAttendance($data['attendance'] ?? []);
        return $applyDesktopDataScope($data);
    }

    $sanitizeGuestServiceForFieldRole = static function(array $rows): array {
        foreach($rows as &$row){
            unset($row['requester_contact']);
            if(trim((string)($row['booking_id']??''))!==''){
                $row['guest_name_snapshot']='Tamu';
                if(trim((string)($row['requester_name']??''))!=='')$row['requester_name']='Tamu';
            }
        }
        unset($row);
        return $rows;
    };

    if ($role === 'receptionist') {
        // Hak akses finance/report kustom boleh membuka data keuangan, tetapi
        // tanpa izin tersebut resepsionis tetap tidak menerima buku kas maupun nilai aset.
        if (!$canSeeFinancialData) {
            $data['transactions'] = [];
            // Resepsionis tetap memerlukan selector rekening pembayaran aktif untuk
            // booking/panjar/checkout/deposito. Hanya master minimal bank/QRIS yang
            // dikirim; transaksi dan master Finance lain tetap disembunyikan.
            $data['bankAccounts'] = tamasyaOperationalPaymentAccounts($data['bankAccounts'] ?? []);
            $data['inventory'] = array_map('tamasyaSanitizeInventoryOperationalRow', $data['inventory'] ?? []);
            $data['inventoryMaintenance'] = array_map('tamasyaSanitizeInventoryOperationalRow', $data['inventoryMaintenance'] ?? []);
        }
        $data['salarySlips'] = [];
        $data['telegramMessages'] = [];
        $data['attendance'] = $ownAttendance($data['attendance'] ?? []);
        $data['shiftReports'] = array_values(array_filter($data['shiftReports'] ?? [], static fn($row) =>
            (string)($row['staffId'] ?? '') === (string)($user['id'] ?? '') ||
            (string)($row['companionStaffId'] ?? '') === (string)($user['id'] ?? '')
        ));
        return $applyDesktopDataScope($data);
    }

    // Role lapangan tetap minimal secara default. Bila Admin secara eksplisit
    // memberikan akses finance/report/koreksi melalui Hak Akses Kustom, backend
    // ikut mengirim data yang diperlukan; bukan hanya menampilkan tombol React.
    if (!$canSeeFinancialData) {
        $data['bookings'] = array_map(static function($b) use ($stripBookingIdentity) {
            $b = $stripBookingIdentity($b, false);
            foreach (['totalAmount','vatAmount','downPaymentAmount','amountPaid','balanceDue','refundAmount','roomCharge','extraCharge','discountAmount'] as $field) {
                unset($b[$field]);
            }
            return $b;
        }, $data['bookings'] ?? []);
        $data['transactions'] = [];
        $data['bankAccounts'] = [];
    }
    $data['publicReservationRequests'] = [];
    $data['salarySlips'] = [];
    $data['telegramMessages'] = [];
    if (hasDesktopTabAccess($user, 'inventory', ['admin','manager','finance','receptionist','owner'])) {
        if (!$canSeeFinancialData) {
            $data['inventory'] = array_map('tamasyaSanitizeInventoryOperationalRow', $data['inventory'] ?? []);
            $data['inventoryMaintenance'] = array_map('tamasyaSanitizeInventoryOperationalRow', $data['inventoryMaintenance'] ?? []);
        }
    } else {
        $data['inventory'] = [];
        $data['inventoryMaintenance'] = [];
    }
    $data['shiftReports'] = [];
    $data['attendance'] = $ownAttendance($data['attendance'] ?? []);
    $data['guestServiceRequests'] = $sanitizeGuestServiceForFieldRole($data['guestServiceRequests'] ?? []);
    // Feature flags untuk module dock UI (Growth/Enterprise). Read-only, aman
    // untuk semua role: hanya status on/off, tanpa data bisnis.
    if (function_exists('tamasyaGrowthFeatureStatus')) {
        $growth = tamasyaGrowthFeatureStatus();
        $data['features'] = [
            'growthSuiteEnabled' => (bool)($growth['enabled'] ?? false),
            'enterpriseCompletionEnabled' => function_exists('tamasyaEnterpriseEnabled') && tamasyaEnterpriseEnabled(),
        ];
    }
    return $applyDesktopDataScope($data);
}

/**
 * Google Maps public-site integration.
 * Accepts only HTTPS Google Maps URLs. The CMS may receive either a direct
 * URL or the full iframe copied from Google Maps "Share > Embed a map".
 * No API key is generated or stored by TAMASYA.
 */
function tamasyaGoogleMapsExtractCandidate($value): string {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    if (stripos($raw, '<iframe') !== false) {
        if (!preg_match('/\\bsrc\\s*=\\s*(["\\\'])(.*?)\\1/is', $raw, $matches)) {
            throw new InvalidArgumentException('Kode embed Google Maps tidak memiliki atribut src yang valid.');
        }
        $raw = (string)$matches[2];
    }
    return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function tamasyaGoogleMapsNormalizeUrl($value, bool $throwOnInvalid = false): string {
    try {
        $url = tamasyaGoogleMapsExtractCandidate($value);
        if ($url === '') return '';
        if (strlen($url) > 700) throw new InvalidArgumentException('URL Google Maps melebihi kapasitas 700 karakter. Salin URL src saja, bukan seluruh kode HTML.');
        $parts = parse_url($url);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('Google Maps wajib menggunakan HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new InvalidArgumentException('URL Google Maps mengandung komponen yang tidak diizinkan.');
        }
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $path = (string)($parts['path'] ?? '/');
        $googleWebHosts = ['google.com', 'www.google.com', 'maps.google.com'];
        $isGoogleWeb = in_array($host, $googleWebHosts, true);
        $isShortLink = $host === 'maps.app.goo.gl' && $path !== '/';
        $isEmbed = $isGoogleWeb && (str_starts_with($path, '/maps/embed') || str_starts_with($path, '/maps/embed/v1/'));
        $isMapPage = $isGoogleWeb && str_starts_with($path, '/maps');
        $isLegacyShort = $host === 'goo.gl' && str_starts_with($path, '/maps');
        if (!$isEmbed && !$isMapPage && !$isShortLink && !$isLegacyShort) {
            throw new InvalidArgumentException('URL harus berasal dari Google Maps resmi.');
        }
        if ($isEmbed && trim((string)($parts['query'] ?? '')) === '') {
            throw new InvalidArgumentException('URL embed Google Maps tidak lengkap.');
        }
        return $url;
    } catch (Throwable $error) {
        if ($throwOnInvalid) {
            if ($error instanceof InvalidArgumentException) throw $error;
            throw new InvalidArgumentException('URL Google Maps tidak valid.');
        }
        return '';
    }
}

function tamasyaGoogleMapsIsEmbedUrl($value): bool {
    $url = tamasyaGoogleMapsNormalizeUrl($value, false);
    if ($url === '') return false;
    $parts = parse_url($url);
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);
    $isClassicEmbed = str_starts_with($path, '/maps/embed') || str_starts_with($path, '/maps/embed/v1/');
    $isCoordinateEmbed = $path === '/maps'
        && strtolower(trim((string)($query['output'] ?? ''))) === 'embed'
        && trim((string)($query['q'] ?? '')) !== '';
    return in_array($host, ['google.com', 'www.google.com', 'maps.google.com'], true)
        && ($isClassicEmbed || $isCoordinateEmbed);
}

/**
 * Return a canonical "lat,lng" pair only when the stored Google Maps URL
 * contains explicit decimal coordinates. This lets Website directions use the
 * exact hotel pin captured from GPS instead of depending only on free-text address.
 */
function tamasyaGoogleMapsCoordinatesFromUrl($value): string {
    $url = tamasyaGoogleMapsNormalizeUrl($value, false);
    if ($url === '') return '';
    $parts = parse_url($url);
    parse_str((string)($parts['query'] ?? ''), $query);
    $candidate = trim((string)($query['q'] ?? $query['query'] ?? ''));
    if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $candidate, $matches)) return '';
    $lat = (float)$matches[1];
    $lng = (float)$matches[2];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return '';
    return rtrim(rtrim(number_format($lat, 7, '.', ''), '0'), '.') . ','
        . rtrim(rtrim(number_format($lng, 7, '.', ''), '0'), '.');
}

function tamasyaGoogleMapsPublicLinks($address, $storedUrl): array {
    $addressText = trim((string)$address);
    $normalized = tamasyaGoogleMapsNormalizeUrl($storedUrl, false);
    $embedUrl = tamasyaGoogleMapsIsEmbedUrl($normalized) ? $normalized : '';
    $coordinates = tamasyaGoogleMapsCoordinatesFromUrl($normalized);
    $destination = $coordinates !== '' ? $coordinates : $addressText;
    $openUrl = $normalized !== '' && $embedUrl === '' ? $normalized : '';
    $directionsUrl = '';
    if ($destination !== '') {
        $encoded = rawurlencode($destination);
        // For an embed URL, expose a normal Google Maps page instead of opening
        // the iframe endpoint directly. GPS coordinates win over textual address.
        if ($openUrl === '') $openUrl = 'https://www.google.com/maps/search/?api=1&query=' . $encoded;
        $directionsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $encoded;
    } elseif ($openUrl === '' && $embedUrl !== '') {
        $openUrl = $embedUrl;
    }
    return [
        'mapUrl' => $normalized,
        'mapEmbedUrl' => $embedUrl,
        'mapOpenUrl' => $openUrl,
        'mapDirectionsUrl' => $directionsUrl,
        'mapCoordinates' => $coordinates,
    ];
}
