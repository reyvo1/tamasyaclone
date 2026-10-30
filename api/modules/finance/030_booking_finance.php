<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

// FINAL11 root hardening: declare direct support dependencies so this module
// remains safe when loaded by maintenance/test tools outside api.php.
if (!function_exists('validIsoDate')) require_once dirname(__DIR__, 2).'/support/012_domain_primitives.php';
if (!function_exists('tamasyaResolvePaymentAccount')) require_once __DIR__.'/018_canonical_business_policy.php';
if (!function_exists('tamasyaFinancialCommit')) require_once __DIR__.'/019_canonical_financial_semantics.php';
if (!function_exists('tamasyaPromotionCodesEnabled')) require_once dirname(__DIR__, 2).'/support/120_promotion_codes.php';

/** Source line 2553: bumpServerRevision */
function bumpServerRevision($pdo) {
    try {
        $result=$pdo->exec("UPDATE config SET server_revision = server_revision + 1 WHERE id = 'system_default'");
        if($result!==false && (int)$result>0){
            $GLOBALS['tamasya_server_revision_bump_count']=(int)($GLOBALS['tamasya_server_revision_bump_count']??0)+1;
        } else {
            error_log('[server revision] config system_default tidak ditemukan atau revision tidak berubah; perangkat tidak boleh menganggap refresh signal berhasil.');
        }
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[server revision] gagal menaikkan revision', $e));
    }
}

function tamasyaServerRevisionWasBumped(): bool {
    return (int)($GLOBALS['tamasya_server_revision_bump_count']??0)>0;
}

/** Source line 2557: issueSessionTokens */
function issueSessionTokens($pdo, $userId) {
    $owns=!$pdo->inTransaction();
    try {
        if($owns)$pdo->beginTransaction();
        // Urutan lock autentikasi selalu staff -> user_sessions agar login,
        // verifikasi 2FA, dan refresh tidak saling deadlock.
        $staffLock=$pdo->prepare("SELECT id,status FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
        $staffLock->execute([$userId]);
        $staffRow=$staffLock->fetch(PDO::FETCH_ASSOC);
        if(!$staffRow || (string)($staffRow['status']??'')!=='active')throw new RuntimeException('Akun staff aktif tidak ditemukan.');

        $sessionToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(48));
        $sessionHash = hash('sha256', $sessionToken);
        $refreshHash = hash('sha256', $refreshToken);
        $sessionTtl = max(900, (int)(getenv('SESSION_TTL_SECONDS') ?: 43200));
        $refreshTtl = max(3600, (int)(getenv('OFFLINE_REFRESH_TTL_SECONDS') ?: 2592000));
        $sessionExpires = date('Y-m-d H:i:s', time() + $sessionTtl);
        $refreshExpires = date('Y-m-d H:i:s', time() + $refreshTtl);
        $deviceId = currentDeviceId();
        $deviceName = trim((string)($_SERVER['HTTP_X_DEVICE_NAME'] ?? '')) ?: substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Browser Device'), 0, 190);
        $sessionId=generateServerId('session');

        $activeLock=$pdo->prepare("SELECT id FROM user_sessions WHERE staff_id=? AND device_id=? AND revoked_at IS NULL ORDER BY created_at,id FOR UPDATE");
        $activeLock->execute([$userId,$deviceId]);
        $activeLock->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE staff_id=? AND device_id=? AND revoked_at IS NULL")
            ->execute([$userId, $deviceId]);
        $pdo->prepare("INSERT INTO user_sessions (id,staff_id,token_hash,refresh_token_hash,device_id,device_name,user_agent,ip_address,last_activity,expires_at,refresh_expires,created_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?,CURRENT_TIMESTAMP)")
            ->execute([$sessionId,$userId,$sessionHash,$refreshHash,$deviceId,$deviceName,substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500),tamasyaClientIp(),$sessionExpires,$refreshExpires]);
        if($owns)tamasyaFinancialCommit($pdo);
        return [$sessionToken, $refreshToken, $refreshExpires, $sessionId];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** Source line 2581: isPlaceholderProof */
function isPlaceholderProof($value) {
    return $value === 'placeholder_proof' || $value === '__KEEP_EXISTING_PROOF__';
}

/** Source line 2585: inferTransactionKind */
function inferTransactionKind($tx) {
    $kind = strtolower((string)($tx['transactionKind'] ?? ''));
    $allowed = tamasyaCanonicalTransactionKinds();
    // Nilai transactionKind yang sudah disimpan adalah sumber kebenaran.
    // Khususnya `manual` tidak boleh diinfer ulang hanya karena kategori/deskripsi
    // mengandung kata seperti pemeliharaan, gaji, atau refund. Inferensi teks hanya
    // dipakai untuk record legacy yang transactionKind-nya kosong/tidak dikenal.
    if ($kind !== '' && in_array($kind, $allowed, true)) return $kind;

    $id = strtolower((string)($tx['id'] ?? ''));
    $category = strtolower((string)($tx['category'] ?? ''));
    $description = strtolower((string)($tx['description'] ?? ''));
    if (str_starts_with($id, 'tx_slip_') || str_contains($category, 'gaji')) return 'salary_payment';
    if (str_contains($category, 'pemeliharaan') || str_contains($description, 'maintenance') || str_contains($description, 'perawatan aset')) return 'maintenance_cost';
    if (str_contains($description, 'uang muka') || preg_match('/\bdp\b/i', $description)) return 'down_payment';
    if (str_contains($category, 'mutasi') || str_contains($description, 'pencairan ota') || str_contains($description, 'transfer internal')) return 'internal_transfer';
    if (str_contains($description, 'refund') || str_contains($description, 'pengembalian dana')) return 'refund';
    if (!empty($tx['bookingId'])) {
        if (str_contains($description, 'layanan') || str_contains($description, 'perpanjang') || str_contains($description, 'extra') || str_contains($description, 'pindah kamar')) return 'booking_charge';
        return 'booking_payment';
    }
    return 'manual';
}

/** Source line 2605: transactionIsProtected */
function transactionIsProtected($tx) {
    if (!empty($tx['isSystemGenerated'])) return true;
    $kind = inferTransactionKind($tx);
    return in_array($kind, tamasyaProtectedTransactionKinds(), true);
}

/** Source line 2611: normalizeTransactionKind */
function normalizeTransactionKind($value) {
    $value=strtolower(trim((string)$value));
    return in_array($value, tamasyaCanonicalTransactionKinds(), true) ? $value : 'manual';
}


/** Resolve one finance master category by stable ID or display label. */
function tamasyaResolveFinanceCategory(PDO $pdo, array $input, bool $lock=false): ?array {
    return tamasyaResolveFinanceCatalogCategory($pdo,$input,$lock);
}

/**
 * Canonicalize one active category/subcategory selection. Stable IDs are the
 * preferred client contract; names are accepted only for compatibility with
 * older cached clients and are canonicalized by the server before commit.
 */
function tamasyaResolveActiveFinanceCatalogSelection(PDO $pdo, string $type, string $category, ?string $subcategory=null, bool $lock=false, ?string $categoryId=null, ?string $subcategoryId=null): array {
    $selection=tamasyaResolveFinanceCatalogSelection($pdo,[
        'type'=>$type,
        'categoryId'=>$categoryId,
        'category'=>$category,
        'subcategoryId'=>$subcategoryId,
        'subcategory'=>$subcategory,
    ],$lock,true);
    return $selection;
}

/** Validate one liquid account reference; cash is normalized to NULL. */
function tamasyaValidateFinanceBankAccount(PDO $pdo, $bankAccountId, bool $lock=false, bool $allowOtaReceivable=true): ?string {
    $id=trim((string)$bankAccountId);
    if($id===''||strtolower($id)==='cash')return null;
    if($allowOtaReceivable && strtolower($id)==='ota_receivable')return 'ota_receivable';
    $stmt=$pdo->prepare("SELECT id FROM bank_accounts WHERE id=? AND isActive=1 LIMIT 1".($lock?' FOR UPDATE':''));
    $stmt->execute([$id]);
    $canonical=$stmt->fetchColumn();
    if($canonical===false)throw new InvalidArgumentException('Akun bank/QRIS tidak aktif atau tidak ditemukan.');
    return (string)$canonical;
}

/**
 * Piutang OTA pada Log Kas/Data Historis adalah pseudo-account neraca, bukan
 * rekening likuid. Generic manual finance hanya boleh menggunakannya untuk
 * pemasukan historical_import yang benar-benar berasal dari kanal OTA.
 * Transaksi live OTA tetap wajib memakai workflow reservasi/checkout canonical.
 */
function tamasyaValidateManualOtaReceivableContext(array $payload, string $recordOrigin): void {
    $bank=strtolower(trim((string)($payload['bankAccountId']??'')));
    if($bank!=='ota_receivable')return;

    $type=strtolower(trim((string)($payload['type']??'')));
    $origin=strtolower(trim($recordOrigin));
    $bookingSource=trim((string)($payload['bookingSource']??''));

    if($type!=='income'){
        throw new InvalidArgumentException('Piutang OTA hanya boleh dipakai untuk pemasukan, bukan pengeluaran.');
    }
    if($origin!=='historical_import'){
        throw new RuntimeException('Piutang OTA pada Log Kas manual hanya tersedia untuk Data Historis / Backfill. Transaksi OTA operasional wajib melalui workflow reservasi/checkout.',409);
    }
    if($bookingSource==='' || !tamasyaIsExplicitOtaSourceLabel($bookingSource)){
        throw new InvalidArgumentException('Piutang OTA wajib memakai sumber OTA yang eksplisit seperti Traveloka, Booking.com, Agoda, Tiket.com, atau format OTA: Nama Kanal. Nama tamu/catatan bebas tidak boleh dianggap OTA.');
    }
}

/**
 * Guard generic Log Kas so module-owned financial events are not silently
 * duplicated and PBJT settlement never depends on ambiguous free text.
 */
function tamasyaManualFinanceEntryPolicy(array $categoryRow, string $recordOrigin, array $payload=[]): array {
    $origin=strtolower(trim($recordOrigin));
    $systemKey=strtolower(trim((string)($categoryRow['system_key']??'')));
    $type=strtolower(trim((string)($categoryRow['type']??$payload['type']??'')));
    $category=strtolower(trim((string)($categoryRow['name']??$payload['category']??'')));
    $subcategory=strtolower(trim((string)($payload['subcategory']??'')));
    $description=strtolower(trim((string)($payload['description']??'')));
    $bookingId=trim((string)($payload['bookingId']??''));

    // Semantic roles owned by dedicated workflows may not be forged from generic
    // Log Kas in live operation. Hotels remain free to create other dynamic
    // categories for ad-hoc income/expense; these protected roles preserve
    // booking/POS/payroll single-source accounting.
    if($origin==='live_operation' && in_array($systemKey,['room_rental','extra_service'],true)){
        throw new RuntimeException('Kategori ini terhubung ke workflow Booking/Tamu. Pencatatan live harus melalui Reservasi/Check-in/Checkout/Layanan Tamu agar folio, pajak, kas/bank, dan jurnal tetap sinkron. Gunakan kategori pemasukan umum lain untuk transaksi yang memang bukan bagian dari booking.',409);
    }
    if($origin==='live_operation' && in_array($systemKey,['pos_revenue','pos_refund','pos_cogs','pos_cogs_reversal'],true)){
        throw new RuntimeException('Kategori ini terhubung ke workflow POS/Minibar. Pencatatan live harus melalui POS agar stok, HPP, pajak, kas/piutang tamu, dan jurnal tidak terduplikasi.',409);
    }

    if($origin==='live_operation' && $systemKey==='payroll_expense'){
        throw new RuntimeException('Gaji/Upah live harus dicatat dari workflow Penggajian agar slip, status payroll, kas/bank, dan jurnal tidak terduplikasi. Log Kas manual hanya boleh dipakai untuk backfill historis yang benar-benar belum tercatat.',409);
    }
    if($origin==='live_operation' && $systemKey==='maintenance_expense'){
        throw new RuntimeException('Biaya pemeliharaan yang terikat semantic maintenance harus dicatat dari workflow Maintenance/Inventory agar tiket/aset, biaya, kas/bank, dan jurnal tidak terduplikasi. Gunakan kategori beban umum tanpa semantic maintenance hanya bila transaksi memang tidak terkait workflow pemeliharaan.',409);
    }

    if($systemKey==='pbjt_settlement'){
        if($type!=='expense')throw new RuntimeException('Penyelesaian PBJT harus berupa pengeluaran.',409);
        if($bookingId!=='')throw new RuntimeException('Penyelesaian PBJT tidak boleh ditautkan langsung ke booking tamu.',409);
    }

    $warnings=[];
    if($systemKey==='inventory_expense'){
        $warnings[]='Kategori ini langsung dibebankan sebagai biaya operasional. Jangan gunakan untuk stok minibar/persediaan yang masih menjadi aset; gunakan workflow Inventory/Procurement.';
    }
    if($origin==='historical_import' && $systemKey==='payroll_expense'){
        $warnings[]='Backfill Gaji/Upah historis hanya untuk data lama yang belum pernah tercatat di payroll/Log Kas; verifikasi agar tidak duplikat.';
    }
    return ['systemKey'=>$systemKey,'warnings'=>$warnings];
}

function tamasyaMergeFinanceWarnings(...$warnings): ?string {
    $flat=[];
    foreach($warnings as $warning){
        if(is_array($warning)){foreach($warning as $w)if(trim((string)$w)!=='')$flat[]=trim((string)$w);}
        elseif(trim((string)$warning)!=='')$flat[]=trim((string)$warning);
    }
    $flat=array_values(array_unique($flat));
    return $flat?implode(' ',$flat):null;
}

/** Source line 2616: moneyMatches */
function moneyMatches($left, $right, $tolerance = 1.0) {
    return abs((float)$left - (float)$right) <= max(0.01, (float)$tolerance);
}

/**
 * Pengurang resmi penerimaan booking.
 * Selain refund normal, pembalik booking_charge dari audit-correction juga
 * mengurangi net booking. Booking-charge expense dari sumber lain tidak ikut.
 */
function tamasyaBookingReductionSql(string $alias = ''): string {
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "{$prefix}type='expense' AND ({$prefix}transactionKind='refund' OR ({$prefix}transactionKind='booking_charge' AND {$prefix}updatedSource='audit-correction'))";
}

/** Source line 2620: bookingLedgerTotals */
function bookingLedgerTotals($pdo, $bookingId) {
    // Definisi ledger ini harus identik dengan recalculateBookingFinancials:
    // receipt tertaut menyumbang sisa setelah seluruh allocation aktif, sedangkan
    // allocation menyumbang hanya porsi booking tujuan. Tanpa ini refund booking
    // tujuan akan terlihat melebihi penerimaan walaupun allocation-nya sah.
    $linkedStmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(
            t.amount-COALESCE((SELECT SUM(a.amount) FROM transaction_allocations a WHERE a.transaction_id=t.id AND a.status='active'),0),0
        )),0)
        FROM transactions t
        WHERE t.bookingId=? AND t.type='income'
          AND NOT EXISTS (
              SELECT 1 FROM transaction_allocations workflow_link
              WHERE workflow_link.transaction_id=t.id
                AND workflow_link.transaction_booking_link_added=1
          )");
    $allocatedStmt = $pdo->prepare("SELECT COALESCE(SUM(a.amount),0)
        FROM transaction_allocations a
        JOIN transactions t ON t.id=a.transaction_id
        WHERE a.booking_id=? AND a.status='active' AND t.type='income'");
    $reductionCondition = tamasyaBookingReductionSql();
    $refundStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE bookingId=? AND {$reductionCondition}");
    $linkedStmt->execute([$bookingId]);
    $allocatedStmt->execute([$bookingId]);
    $refundStmt->execute([$bookingId]);
    $income = max(0.0, (float)$linkedStmt->fetchColumn() + (float)$allocatedStmt->fetchColumn());
    $refund = max(0.0, (float)$refundStmt->fetchColumn());
    return ['income'=>$income, 'refund'=>$refund, 'net'=>$income-$refund];
}

/** TAMASYA V137: determine whether a completed booking still has an audited receivable. */
function tamasyaBookingFinancialClosurePending(array $booking): bool {
    return strtolower((string)($booking['status']??''))==='completed'
        && strtolower((string)($booking['financialClosureStatus']??''))==='pending';
}

function tamasyaRefreshBookingFinancialClosure(PDO $pdo,string $bookingId,array $actor,string $source='web'): array {
    $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking)throw new RuntimeException('Booking penutupan keuangan tidak ditemukan.');
    $ledger=bookingLedgerTotals($pdo,$bookingId);
    $total=max(0.0,round((float)($booking['totalAmount']??0),2));
    $balance=max(0.0,round($total-(float)$ledger['net'],2));
    $closure=strtolower((string)($booking['financialClosureStatus']??'not_required'));
    if($closure==='pending'){
        if($balance<=0.01){
            $pdo->prepare("UPDATE bookings SET financialClosureStatus='settled',financialClosureBalance=0,paymentStatus='paid',version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
                ->execute([$actor['id']??null,$source,$bookingId]);
            $closure='settled';$balance=0.0;
        }else{
            $pdo->prepare("UPDATE bookings SET financialClosureBalance=?,paymentStatus=CASE WHEN ?>0.01 THEN 'partial' ELSE 'unpaid' END,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
                ->execute([$balance,(float)$ledger['net'],$actor['id']??null,$source,$bookingId]);
        }
    }
    return ['status'=>$closure,'balance'=>$balance,'ledger'=>$ledger,'total'=>$total];
}

function tamasyaMarkBookingFinancialClosurePending(PDO $pdo,array $actor,string $bookingId,string $operationId,string $reason,string $source='telegram'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Penutupan keuangan operasional wajib berada dalam transaksi database.');
    $reason=trim($reason);$operationId=trim($operationId);
    if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan checkout operasional minimal 5 karakter.');
    if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('Operation ID checkout operasional tidak valid.');
    $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking)throw new RuntimeException('Booking checkout operasional tidak ditemukan.');
    $ledger=bookingLedgerTotals($pdo,$bookingId);$total=max(0.0,round((float)($booking['totalAmount']??0),2));$balance=max(0.0,round($total-(float)$ledger['net'],2));
    if($balance<=0.01)throw new RuntimeException('Booking sudah lunas; penundaan penutupan keuangan tidak diperlukan.');
    $existingOp=trim((string)($booking['financialClosureOperationId']??''));
    if($existingOp!==''&&!hash_equals($existingOp,$operationId))throw new RuntimeException('Booking sudah memiliki operasi penutupan keuangan berbeda.');
    $paymentStatus=(float)$ledger['net']>0.01?'partial':'unpaid';
    $pdo->prepare("UPDATE bookings SET financialClosureStatus='pending',financialClosureBalance=?,financialClosureReason=?,financialClosureAt=CURRENT_TIMESTAMP,financialClosureBy=?,financialClosureSource=?,financialClosureOperationId=?,paymentStatus=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
        ->execute([$balance,$reason,$actor['id']??null,$source,$operationId,$paymentStatus,$actor['id']??null,$source,$bookingId]);
    return ['status'=>'pending','balance'=>$balance,'reason'=>$reason,'operationId'=>$operationId,'ledger'=>$ledger,'total'=>$total];
}

/** Source line 2634: recordBookingDeposit */
function recordBookingDeposit(PDO $pdo, array $actor, string $bookingId, array $payload, string $source='web'): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'penerimaan panjar booking');
    if (!$pdo->inTransaction()) throw new RuntimeException('Penerimaan panjar booking wajib berada dalam transaksi database.');
    $bookingId=trim($bookingId);
    $amount=round((float)($payload['amount']??0),2);
    $method=tamasyaNormalizePaymentMethod((string)($payload['paymentMethod']??'cash'));
    $bankAccountId=trim((string)($payload['bankAccountId']??''));
    $operationId=trim((string)($payload['operationId']??''));
    $date=trim((string)($payload['date']??date('Y-m-d')));
    $notes=trim((string)($payload['notes']??''));
    if($bookingId==='')throw new InvalidArgumentException('ID booking wajib diisi.');
    if($amount<=0)throw new InvalidArgumentException('Nominal pembayaran harus lebih dari nol.');
    if($amount>1000000000000)throw new InvalidArgumentException('Nominal pembayaran melebihi batas keamanan.');
    if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('Operation ID pembayaran wajib dan maksimal 100 karakter.');
    if(!validIsoDate($date))throw new InvalidArgumentException('Tanggal pembayaran tidak valid.');
    $bankAccountId=tamasyaResolvePaymentAccount($pdo,$method,$bankAccountId,[
        'allowedMethods'=>['cash','transfer','qris'],'lock'=>true,'context'=>'Pembayaran booking'
    ]);
    $duplicate=$pdo->prepare("SELECT id,bookingId,amount,transactionKind FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");$duplicate->execute([$operationId]);$existing=$duplicate->fetch(PDO::FETCH_ASSOC);
    if($existing){
        $existingKind=strtolower((string)($existing['transactionKind']??''));
        if((string)($existing['bookingId']??'')!==$bookingId || !in_array($existingKind,['down_payment','settlement'],true) || !moneyMatches((float)$existing['amount'],$amount,0.01))throw new RuntimeException('Operation ID sudah dipakai untuk transaksi berbeda.');
        recalculateBookingFinancials($pdo,$bookingId,true);
        $dupBookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$dupBookingStmt->execute([$bookingId]);$dupBooking=$dupBookingStmt->fetch(PDO::FETCH_ASSOC)?:[];
        if($existingKind==='settlement' && tamasyaBookingFinancialClosurePending($dupBooking))tamasyaRefreshBookingFinancialClosure($pdo,$bookingId,$actor,$source);
        assertBookingLedgerInvariant($pdo,$bookingId,$actor,$source,false);
        return ['duplicate'=>true,'transactionId'=>(string)$existing['id'],'bookingId'=>$bookingId,'transactionKind'=>$existingKind];
    }
    $stmtBooking=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$stmtBooking->execute([$bookingId]);$booking=$stmtBooking->fetch(PDO::FETCH_ASSOC);
    if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
    $bookingStatus=strtolower((string)($booking['status']??''));
    $isFinancialClosurePayment=$bookingStatus==='completed' && strtolower((string)($booking['financialClosureStatus']??''))==='pending';
    if(!in_array($bookingStatus,['reserved','active'],true) && !$isFinancialClosurePayment)throw new RuntimeException('Pembayaran hanya dapat diterima untuk reservasi/booking aktif atau piutang checkout operasional.');
    $depositRequiresShift=tamasyaManualTransactionRequiresOpenShift(['type'=>'income','amount'=>$amount,'bankAccountId'=>$bankAccountId]);
    $shiftSessionId=resolveOpenShiftSessionId($pdo,$actor,$depositRequiresShift);
    $ledgerBefore=bookingLedgerTotals($pdo,$bookingId);
    $isOpenEnded=(int)($booking['isOpenEnded']??0)===1;
    $total=max(0.0,(float)($booking['totalAmount']??0));
    $remaining=max(0.0,round($total-$ledgerBefore['net'],2));
    if(!$isOpenEnded && $amount>$remaining+0.01)throw new RuntimeException('Pembayaran melebihi sisa tagihan Rp '.number_format($remaining,0,',','.').'.');
    $bookingSource=trim((string)($booking['bookingSource']??'Direct')) ?: 'Direct';
    // Panjar/pelunasan adalah alokasi kas atas snapshot PBJT booking, bukan
    // penjualan kamar baru. Ini mencegah extra/extension bertarif berbeda
    // dihitung ulang memakai tarif kamar.
    $tax=resolveBookingPaymentTaxAllocation($pdo,$booking,$amount,(float)$ledgerBefore['net'],$date);
    if(empty($tax['snapshot']['preserved'])){
        $pdo->prepare("UPDATE bookings SET vatRate=?,vatAmount=?,extras=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
            ->execute([
                $tax['snapshot']['taxRate'],$tax['snapshot']['taxAmount'],
                json_encode($tax['snapshot']['extras']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $actor['id']??null,$source,$bookingId
            ]);
        $booking['vatRate']=$tax['snapshot']['taxRate'];$booking['vatAmount']=$tax['snapshot']['taxAmount'];$booking['extras']=$tax['snapshot']['extras']??[];
    }
    $txId=generateServerId($isFinancialClosurePayment?'tx_closure':'tx_dp');
    $label=$method==='qris'?'QRIS':($method==='transfer'?'Transfer':'Tunai');
    $transactionKind=$isFinancialClosurePayment?'settlement':'down_payment';
    $description=($isFinancialClosurePayment?'Pelunasan Piutang Checkout Kamar ':'Panjar Kamar ').(string)$booking['roomNumber'].' - '.(string)$booking['guestName'].' ('.$label.')'.($notes!==''?' - '.$notes:'');
    // A live booking receipt must carry the full tax snapshot contract that
    // tamasyaAssertLiveTaxSnapshotIntegrity() enforces: taxSource, taxRuleId and
    // taxSnapshotStatus. This path only ever sent baseAmount/taxAmount/taxRate, so
    // every deposit it wrote failed that integrity gate on the next posting. The
    // resulting poison row then blocked unrelated live postings as well, which is
    // how an audit correction on a closed shift could never be saved.
    tamasyaPostFinancialTransaction($pdo,[
        'id'=>$txId,'type'=>'income','category'=>getRoomRentalCategoryName($pdo),'categorySystemKey'=>'room_rental',
        'subcategory'=>(string)($booking['roomType']??''),'roomNumber'=>(string)$booking['roomNumber'],'amount'=>$amount,'date'=>$date,
        'description'=>$description,'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>$bankAccountId!==''?$bankAccountId:null,
        'bookingId'=>$bookingId,'bookingSource'=>$bookingSource,'baseAmount'=>(float)$tax['baseAmount'],'taxAmount'=>(float)$tax['taxAmount'],
        'taxRate'=>$tax['taxRate'],'transactionKind'=>$transactionKind,'sourceEntity'=>'booking','sourceEntityId'=>$bookingId,
        'isSystemGenerated'=>1,'operationId'=>$operationId,'shiftSessionId'=>$shiftSessionId,'updatedBy'=>$actor['id']??null,
        'taxRuleId'=>null,
        'taxRuleName'=>null,
        'taxSource'=>'booking_component_allocation','taxSnapshotStatus'=>'confirmed',
        'updatedSource'=>$source,'version'=>1
    ],$actor,'booking',['lockCatalog'=>true,'source'=>$source]);
    tamasyaAttachBookingReceiptRevenueAllocations($pdo,$booking,$txId,$amount,(float)$ledgerBefore['net'],$operationId,$actor,$source,$date);
    $depositReductionCondition=tamasyaBookingReductionSql();
    $depositStmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' AND transactionKind='down_payment' THEN amount WHEN {$depositReductionCondition} THEN -amount ELSE 0 END),0) FROM transactions WHERE bookingId=?");
    $depositStmt->execute([$bookingId]);$depositTotal=max(0.0,(float)$depositStmt->fetchColumn());
    $ledgerAfter=bookingLedgerTotals($pdo,$bookingId);
    $paymentStatus=$isOpenEnded?'partial':($ledgerAfter['net']>=$total-0.01?'paid':'partial');
    if($isFinancialClosurePayment){
        $pdo->prepare("UPDATE bookings SET paymentStatus=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
            ->execute([$paymentStatus,$actor['id']??null,$source,$bookingId]);
    }else{
        $pdo->prepare("UPDATE bookings SET downPaymentAmount=?,downPaymentMethod=?,downPaymentBankAccountId=?,downPaymentDate=?,paymentStatus=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
            ->execute([$depositTotal,$method,$bankAccountId!==''?$bankAccountId:null,$date,$paymentStatus,$actor['id']??null,$source,$bookingId]);
    }
    recalculateBookingFinancials($pdo,$bookingId,true);
    if($isFinancialClosurePayment)tamasyaRefreshBookingFinancialClosure($pdo,$bookingId,$actor,$source);
    assertBookingLedgerInvariant($pdo,$bookingId,$actor,$source,false);
    $afterBookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$afterBookingStmt->execute([$bookingId]);$afterBooking=$afterBookingStmt->fetch(PDO::FETCH_ASSOC)?:null;
    $afterTransactionStmt=$pdo->prepare("SELECT id,bookingId,roomNumber,amount,baseAmount,taxAmount,taxRate,transactionKind,operationId,shiftSessionId,bankAccountId,`date` FROM transactions WHERE id=? LIMIT 1");$afterTransactionStmt->execute([$txId]);$afterTransaction=$afterTransactionStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$txId,'amount'=>$amount,'operationId'=>$operationId];
    writeRequiredEnterpriseAudit($pdo,$actor,$isFinancialClosurePayment?'Menerima pelunasan piutang checkout':'Menerima panjar booking','booking',$bookingId,tamasyaBookingAuditSnapshot($booking),['booking'=>tamasyaBookingAuditSnapshot($afterBooking),'transaction'=>$afterTransaction],$source);
    $notifId=generateServerId('n_dp');
    $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance')")
        ->execute([$notifId,($isFinancialClosurePayment?'Pelunasan piutang checkout ':'Panjar ').'Rp '.number_format($amount,0,',','.').' untuk Kamar '.$booking['roomNumber'].' diterima via '.$label.' oleh '.currentStaffLabel($actor).'.']);
    bumpServerRevision($pdo);
    $telegramTitle=$isFinancialClosurePayment?'PELUNASAN PIUTANG CHECKOUT DITERIMA':'PANJAR DITERIMA';
    $summaryLabel=$isFinancialClosurePayment?'Sisa piutang':'Total panjar';
    $summaryAmount=$isFinancialClosurePayment?max(0.0,round($total-(float)$ledgerAfter['net'],2)):$depositTotal;
    return ['duplicate'=>false,'transactionId'=>$txId,'bookingId'=>$bookingId,'amount'=>$amount,'paymentMethod'=>$method,'depositTotal'=>$depositTotal,'ledgerNet'=>$ledgerAfter['net'],'shiftSessionId'=>$shiftSessionId,'transactionKind'=>$transactionKind,'telegramMessage'=>"💵 *{$telegramTitle}*\n\n🚪 Kamar: *{$booking['roomNumber']}*\n👤 Tamu: *{$booking['guestName']}*\n💰 Nominal: *Rp ".number_format($amount,0,',','.')."*\n💳 Metode: *{$label}*\n🧾 {$summaryLabel}: *Rp ".number_format($summaryAmount,0,',','.')."*\n👤 Petugas: *".currentStaffLabel($actor)."*"];
}

/**
 * Hitung porsi ekonomi satu penerimaan yang benar-benar dimiliki booking.
 *
 * Receipt manual dapat tertaut ke booking pertama sambil sebagian nominalnya
 * dialokasikan ke booking lain. Karena itu refund tidak boleh memakai seluruh
 * amount transaksi hanya berdasarkan transactions.bookingId.
 */
function tamasyaRefundShareForBooking(array $income, array $allocations, string $bookingId): array {
    $incomeAmount = max(0.0, round((float)($income['amount'] ?? 0), 2));
    $incomeTax = max(0.0, round((float)($income['taxAmount'] ?? 0), 2));
    $incomeBase = array_key_exists('baseAmount', $income) && $income['baseAmount'] !== null && $income['baseAmount'] !== ''
        ? max(0.0, round((float)$income['baseAmount'], 2))
        : max(0.0, round($incomeAmount - $incomeTax, 2));
    $allocatedAmount = 0.0;
    $allocatedBase = 0.0;
    $allocatedTax = 0.0;
    $targetAmount = 0.0;
    $targetBase = 0.0;
    $targetTax = 0.0;
    $targetRateWeight = 0.0;
    $targetCategory = null;
    $targetSubcategory = null;

    foreach ($allocations as $allocation) {
        if (strtolower((string)($allocation['status'] ?? 'active')) !== 'active') continue;
        $amount = max(0.0, round((float)($allocation['amount'] ?? 0), 2));
        $base = max(0.0, round((float)($allocation['base_amount'] ?? $allocation['baseAmount'] ?? 0), 2));
        $tax = max(0.0, round((float)($allocation['tax_amount'] ?? $allocation['taxAmount'] ?? 0), 2));
        $allocatedAmount += $amount;
        $allocatedBase += $base;
        $allocatedTax += $tax;
        if ((string)($allocation['booking_id'] ?? $allocation['bookingId'] ?? '') === $bookingId) {
            $targetAmount += $amount;
            $targetBase += $base;
            $targetTax += $tax;
            $targetRateWeight += $amount * max(0.0, (float)($allocation['tax_rate'] ?? $allocation['taxRate'] ?? $income['taxRate'] ?? 0));
            if ($targetCategory === null && trim((string)($allocation['category'] ?? '')) !== '') $targetCategory = (string)$allocation['category'];
            if ($targetSubcategory === null && trim((string)($allocation['subcategory'] ?? '')) !== '') $targetSubcategory = (string)$allocation['subcategory'];
        }
    }

    $directRemainder = 0.0;
    $directBase = 0.0;
    $directTax = 0.0;
    $workflowBookingLinkAdded = false;
    foreach ($allocations as $allocation) {
        if (!empty($allocation['transaction_booking_link_added'])) {
            $workflowBookingLinkAdded = true;
            break;
        }
    }
    if (!$workflowBookingLinkAdded && (string)($income['bookingId'] ?? '') === $bookingId) {
        $directRemainder = max(0.0, round($incomeAmount - $allocatedAmount, 2));
        $directBase = max(0.0, round($incomeBase - $allocatedBase, 2));
        $directTax = max(0.0, round($incomeTax - $allocatedTax, 2));
    }

    $amount = min($incomeAmount, round($targetAmount + $directRemainder, 2));
    $base = round($targetBase + $directBase, 2);
    $tax = round($targetTax + $directTax, 2);
    // Data allocation legacy dapat tidak memiliki rincian base/tax. Normalisasi
    // proporsional agar base + tax selalu sama dengan porsi refund booking.
    if ($amount > 0 && !moneyMatches($base + $tax, $amount)) {
        $effectiveTaxRatio = $incomeAmount > 0 ? min(1.0, $incomeTax / $incomeAmount) : 0.0;
        $tax = round($amount * $effectiveTaxRatio, 2);
        $base = round(max(0.0, $amount - $tax), 2);
    }
    $rateWeight = $targetRateWeight + ($directRemainder * max(0.0,(float)($income['taxRate'] ?? 0)));
    return [
        'amount'=>$amount,
        'baseAmount'=>$base,
        'taxAmount'=>$tax,
        'taxRate'=>$amount > 0 ? round($rateWeight / $amount, 3) : 0.0,
        'category'=>$targetCategory,
        'subcategory'=>$targetSubcategory,
    ];
}

/** Source line 2705: createBookingRefundTransactions */
function createBookingRefundTransactions($pdo, array $booking, array $actor, $source = 'web') {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'refund booking');
    if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new RuntimeException('Refund booking wajib berada dalam transaksi database.');
    $bookingId = (string)($booking['id'] ?? '');
    if ($bookingId === '') return 0.0;

    // Ambil receipt yang tertaut langsung ATAU mempunyai allocation aktif untuk
    // booking ini. ID dikumpulkan dahulu; setiap transaksi kemudian dikunci.
    $idStmt = $pdo->prepare("SELECT id FROM transactions t
        WHERE t.type='income' AND (
            t.bookingId=? OR EXISTS (
                SELECT 1 FROM transaction_allocations a
                WHERE a.transaction_id=t.id AND a.booking_id=? AND a.status='active'
            )
        ) ORDER BY t.`date`,t.id");
    $idStmt->execute([$bookingId,$bookingId]);
    $incomeIds = $idStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $incomeLock = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND type='income' LIMIT 1 FOR UPDATE");
    $allocationLock = $pdo->prepare("SELECT * FROM transaction_allocations WHERE transaction_id=? AND status='active' ORDER BY created_at,id FOR UPDATE");
    $inserted = 0.0;
    $refundExists=$pdo->prepare("SELECT id FROM transactions WHERE id=? OR operationId=? LIMIT 1 FOR UPDATE");
    foreach ($incomeIds as $incomeId) {
        $incomeLock->execute([(string)$incomeId]);
        $income = $incomeLock->fetch(PDO::FETCH_ASSOC);
        if (!$income) continue;
        $allocationLock->execute([(string)$incomeId]);
        $allocations = $allocationLock->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $share = tamasyaRefundShareForBooking($income,$allocations,$bookingId);
        $sourceTxId = (string)($income['id'] ?? '');
        $amount = max(0.0, (float)($share['amount'] ?? 0));
        if ($sourceTxId === '' || $amount <= 0) continue;
        $hash = substr(hash('sha256', $bookingId . '|' . $sourceTxId), 0, 40);
        $refundId = 'tx_refund_' . $hash;
        $operationId = 'booking_refund:' . $hash;
        $description = 'Refund pembatalan booking ' . ($booking['roomNumber'] ?? '-') . ' - ' . ($booking['guestName'] ?? '-') . ' [pembalik ' . $sourceTxId . ']';
        // A one-row split receipt must be reversed through the same cash/bank legs.
        // When only a booking-owned share of a receipt is refunded, apportion those
        // legs by the source settlement ratio so the drawer and bank cannot diverge.
        $incomeAmount=max(0.0,round((float)($income['amount']??0),2));
        $incomeSplitCash=max(0.0,round((float)($income['splitCashAmount']??0),2));
        $incomeSplitTransfer=max(0.0,round((float)($income['splitTransferAmount']??0),2));
        $incomeSplitBank=trim((string)($income['splitTransferBankAccountId']??''));
        $incomeSplitValid=!empty($income['isSplitPayment']) && $incomeAmount>0 && $incomeSplitCash>0 && $incomeSplitTransfer>0
            && $incomeSplitBank!=='' && moneyMatches($incomeSplitCash+$incomeSplitTransfer,$incomeAmount,0.01);
        $refundSplitCash=0.0;$refundSplitTransfer=0.0;$refundSplitBank=null;
        if($incomeSplitValid){
            $refundSplitCash=round($amount*($incomeSplitCash/$incomeAmount),2);
            $refundSplitTransfer=round($amount-$refundSplitCash,2);
            $refundSplitBank=$incomeSplitBank;
            if($refundSplitCash<=0 || $refundSplitTransfer<=0){
                // Tiny partial allocations cannot faithfully represent two positive
                // legs. Fail closed instead of silently converting them to cash.
                throw new RuntimeException('Porsi refund split terlalu kecil untuk mempertahankan kaki Tunai dan Bank secara akurat. Gunakan Koreksi Audit.');
            }
        }
        $refundExists->execute([$refundId,$operationId]);
        if(!$refundExists->fetchColumn()){
            $refundRequiresShift=tamasyaManualTransactionRequiresOpenShift([
                'type'=>'expense','amount'=>$amount,
                'bankAccountId'=>$incomeSplitValid?null:($income['bankAccountId']??null),
                'isSplitPayment'=>$incomeSplitValid?1:0,
                'splitCashAmount'=>$refundSplitCash,'splitTransferAmount'=>$refundSplitTransfer,
                'splitTransferBankAccountId'=>$refundSplitBank
            ]);
            $refundShiftSessionId=resolveOpenShiftSessionId($pdo,$actor,$refundRequiresShift);
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$refundId,'type'=>'expense','category'=>$share['category']??null?:($income['category']??getRoomRentalCategoryName($pdo)),
                'categoryId'=>$income['categoryId']??null,'categorySystemKey'=>$income['categorySystemKey']??null,
                'subcategory'=>$share['subcategory']??null?:($income['subcategory']??($booking['roomType']??null)),
                'subcategoryId'=>$income['subcategoryId']??null,'subcategorySystemKey'=>$income['subcategorySystemKey']??null,
                'roomNumber'=>$income['roomNumber']??($booking['roomNumber']??null),'amount'=>$amount,'date'=>date('Y-m-d'),'description'=>$description,
                'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>$incomeSplitValid?null:($income['bankAccountId']??null),'bookingId'=>$bookingId,
                'bookingSource'=>$income['bookingSource']??($booking['bookingSource']??'Direct'),'baseAmount'=>(array_key_exists('baseAmount',$share) && $share['baseAmount'] !== null && $share['baseAmount'] !== '' ? tamasyaPosNumber($share['baseAmount']) : null),
                'taxAmount'=>max(0.0,(float)($share['taxAmount']??0)),'taxRate'=>max(0.0,(float)($share['taxRate']??0)),
                'taxSnapshotStatus'=> (array_key_exists('baseAmount',$share) && $share['baseAmount'] !== null && $share['baseAmount'] !== '') ? 'confirmed' : 'unresolved','taxSource'=>'booking_refund_snapshot',
                'transactionKind'=>'refund','sourceEntity'=>'booking_refund','sourceEntityId'=>$sourceTxId,'isSystemGenerated'=>1,
                'operationId'=>$operationId,'updatedBy'=>$actor['id']??null,'updatedSource'=>$source,'version'=>1,
                'isSplitPayment'=>$incomeSplitValid?1:0,'splitCashAmount'=>$refundSplitCash,'splitTransferAmount'=>$refundSplitTransfer,
                'splitTransferBankAccountId'=>$refundSplitBank,'shiftSessionId'=>$refundShiftSessionId,
            // Refund reverses the original revenue category; retaining that identity
            // is required for contra-revenue/tax journal projection and split reversal.
            ],$actor,'booking',['source'=>$source,'allowSplitExpenseReversal'=>true,'allowSemanticTypeMismatchForReversal'=>true]);
            if($refundShiftSessionId!==null) attachTransactionToActorShift($pdo,$refundId,$actor,$refundRequiresShift);
            $inserted += $amount;
        }
    }
    recalculateBookingFinancials($pdo, $bookingId, true);
    return round($inserted,2);
}

/** Source line 2751: assertBookingLedgerInvariant */
function assertBookingLedgerInvariant($pdo, $bookingId, array $actor, $source = 'web', $refundCancelled = true) {
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) throw new RuntimeException('Booking tidak ditemukan saat rekonsiliasi keuangan.');
    $rawTotal=(float)($booking['totalAmount'] ?? 0);
    if(!is_finite($rawTotal) || $rawTotal<0)throw new RuntimeException('Total tagihan booking tidak valid.');
    $total = $rawTotal;
    $extrasTotal=max(0.0,tamasyaBookingExtrasTotal($booking['extras']??null));
    if($extrasTotal>$total+1.0){
        throw new RuntimeException('Total layanan booking melebihi total tagihan. Mutasi dibatalkan agar biaya kamar tidak menjadi negatif.');
    }
    $status = strtolower((string)($booking['status'] ?? 'active'));
    $activeOpenEnded = in_array($status,['reserved','active'],true) && (int)($booking['isOpenEnded'] ?? 0) === 1;
    if ($status === 'cancelled' && $refundCancelled) {
        createBookingRefundTransactions($pdo, $booking, $actor, $source);
    }
    $ledger = bookingLedgerTotals($pdo, $bookingId);
    if ($ledger['net'] < -1.0) throw new RuntimeException('Refund booking melebihi penerimaan yang tercatat.');
    if (!$activeOpenEnded && $ledger['net'] > $total + 1.0) throw new RuntimeException('Penerimaan booking melebihi total tagihan. Sinkronisasi dibatalkan untuk mencegah kas ganda.');
    if ($status === 'cancelled' && !moneyMatches($ledger['net'], 0)) {
        throw new RuntimeException('Pembatalan belum memiliki transaksi refund yang seimbang.');
    }
    $paymentStatus = strtolower((string)($booking['paymentStatus'] ?? 'unpaid'));
    $pendingFinancialClosure = $status === 'completed' && strtolower((string)($booking['financialClosureStatus'] ?? '')) === 'pending';
    if (($status === 'completed' || $paymentStatus === 'paid') && $status !== 'cancelled' && !moneyMatches($ledger['net'], $total) && !$pendingFinancialClosure) {
        throw new RuntimeException('Status lunas tidak sesuai dengan total penerimaan kas booking.');
    }
    if($pendingFinancialClosure){
        $storedBalance=max(0.0,round((float)($booking['financialClosureBalance']??0),2));
        $actualBalance=max(0.0,round($total-(float)$ledger['net'],2));
        if(!moneyMatches($storedBalance,$actualBalance,0.01))throw new RuntimeException('Saldo piutang checkout operasional tidak sesuai ledger booking.');
    }
    recalculateBookingFinancials($pdo, $bookingId, true);
    return $ledger;
}

/**
 * Ringkasan kas kanonik lintas web, Telegram, laporan, dan server.
 * Pendapatan/beban operasional tidak memasukkan mutasi internal.
 * Saldo likuid tidak memasukkan Piutang OTA sampai benar-benar dicairkan.
 */

/**
 * Return the single bank/QRIS leg that is eligible for bank reconciliation.
 * Cash and technical/non-liquid pseudo accounts (OTA receivable, guest folio,
 * inventory/AP) are deliberately excluded. A canonical split transaction has
 * one bank leg and one cash leg; only the bank leg belongs in bank statement
 * reconciliation.
 */
function tamasyaTransactionBankReconciliationLeg(array $tx): ?array {
    $amount=max(0.0,(float)($tx['amount']??0));
    $splitCash=max(0.0,(float)($tx['splitCashAmount']??$tx['split_cash_amount']??0));
    $splitTransfer=max(0.0,(float)($tx['splitTransferAmount']??$tx['split_transfer_amount']??0));
    $splitBank=trim((string)($tx['splitTransferBankAccountId']??$tx['split_transfer_bank_account_id']??''));
    $isSplit=!empty($tx['isSplitPayment']??$tx['is_split_payment']??0)
        && $splitCash>0.0 && $splitTransfer>0.0
        && abs(($splitCash+$splitTransfer)-$amount)<=0.01;
    $technical=['ota_receivable','inventory_asset','guest_receivable','accounts_payable'];
    if($isSplit){
        if($splitBank===''||in_array(strtolower($splitBank),$technical,true))return null;
        return ['amount'=>$splitTransfer,'bankAccountId'=>$splitBank,'isSplit'=>true];
    }
    $bank=trim((string)($tx['bankAccountId']??$tx['bank_account_id']??''));
    if($bank===''||strtolower($bank)==='cash'||in_array(strtolower($bank),$technical,true))return null;
    if($amount<=0.0)return null;
    return ['amount'=>$amount,'bankAccountId'=>$bank,'isSplit'=>false];
}

/** Return true only when a statement amount can reconcile the actual bank leg. */
function tamasyaTransactionMatchesBankReconciliation(array $tx, float $amount, ?string $date=null): bool {
    $leg=tamasyaTransactionBankReconciliationLeg($tx);
    if(!$leg)return false;
    if($date!==null && $date!=='' && trim((string)($tx['date']??''))!==trim($date))return false;
    return abs((float)$leg['amount']-abs($amount))<=0.01;
}

/**
 * Recalculate transaction-level reconciliation state from all linked bank
 * statement items. This prevents the last edited item from overwriting a
 * previous valid match and supports split/partial statement matching safely.
 */
function tamasyaRecalculateTransactionReconciliation(PDO $pdo,string $transactionId,array $actor=[]): array {
    $transactionId=trim($transactionId);
    if($transactionId==='')throw new InvalidArgumentException('ID transaksi rekonsiliasi wajib diisi.');
    $txStmt=$pdo->prepare("SELECT id,amount,`date`,bankAccountId,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId,reconciliationStatus,reconciliationReference FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([$transactionId]);
    $tx=$txStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$tx)throw new RuntimeException('Transaksi rekonsiliasi tidak ditemukan.');
    $leg=tamasyaTransactionBankReconciliationLeg($tx);
    $sum=$pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total, GROUP_CONCAT(NULLIF(reference,'') ORDER BY created_at SEPARATOR ' | ') AS refs FROM reconciliation_items WHERE transaction_id=? AND status IN ('matched','partially_matched')");
    $sum->execute([$transactionId]);
    $agg=$sum->fetch(PDO::FETCH_ASSOC)?:[];
    $matched=max(0.0,(float)($agg['total']??0));
    $reference=trim((string)($agg['refs']??''))?:null;
    if(!$leg){
        if($matched>0.01)throw new RuntimeException('Transaksi ini tidak memiliki kaki Bank/QRIS yang dapat direkonsiliasi. Kas dan akun non-likuid tidak boleh dicocokkan dengan mutasi bank.',409);
        tamasyaSetTransactionReconciliation($pdo,$transactionId,'unmatched',null,$actor);
        return ['status'=>'unmatched','matchedAmount'=>0.0,'bankLegAmount'=>0.0,'bankAccountId'=>null,'reference'=>null];
    }
    $legAmount=(float)$leg['amount'];
    if($matched>$legAmount+0.01)throw new RuntimeException('Total rekonsiliasi melebihi nominal kaki Bank/QRIS transaksi. Periksa item rekonsiliasi yang tertaut.',409);
    $status=$matched<=0.01?'unmatched':(abs($matched-$legAmount)<=0.01?'matched':'partially_matched');
    tamasyaSetTransactionReconciliation($pdo,$transactionId,$status,$reference,$actor);
    return ['status'=>$status,'matchedAmount'=>$matched,'bankLegAmount'=>$legAmount,'bankAccountId'=>$leg['bankAccountId'],'reference'=>$reference];
}

function tamasyaCanonicalCashSummary(PDO $pdo, float $initialBalance = 0.0): array {
    // Split-aware canonical payment legs. Keep legacy cashIncome/cashExpense as
    // total liquid movement for existing callers, while exposing explicit
    // cashOnly*/digital* fields so Telegram/UI cannot confuse a split receipt
    // with 100% physical cash.
    $cashLeg = "CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitCashAmount,0) WHEN (bankAccountId IS NULL OR bankAccountId='' OR LOWER(bankAccountId)='cash') THEN amount ELSE 0 END";
    $bankLeg = "CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitTransferAmount,0) WHEN bankAccountId IS NOT NULL AND bankAccountId<>'' AND LOWER(bankAccountId)<>'cash' AND LOWER(bankAccountId) NOT IN ('ota_receivable','inventory_asset','guest_receivable','accounts_payable') THEN amount ELSE 0 END";
    $sql = "SELECT
        COALESCE(SUM(CASE
            WHEN type='income'
             AND COALESCE(transactionKind,'manual') NOT IN ('internal_transfer','opening_balance_cash','opening_balance_bank')
             AND COALESCE(transactionKind,'manual') <> 'security_deposit_forfeit'
            THEN {$cashLeg} ELSE 0 END),0) AS cash_only_income,
        COALESCE(SUM(CASE
            WHEN type='expense'
             AND COALESCE(transactionKind,'manual') <> 'internal_transfer'
            THEN {$cashLeg} ELSE 0 END),0) AS cash_only_expense,
        COALESCE(SUM(CASE
            WHEN type='income'
             AND COALESCE(transactionKind,'manual') NOT IN ('internal_transfer','opening_balance_cash','opening_balance_bank')
             AND COALESCE(transactionKind,'manual') <> 'security_deposit_forfeit'
            THEN {$bankLeg} ELSE 0 END),0) AS digital_income,
        COALESCE(SUM(CASE
            WHEN type='expense'
             AND COALESCE(transactionKind,'manual') <> 'internal_transfer'
            THEN {$bankLeg} ELSE 0 END),0) AS digital_expense,
        COALESCE(SUM(CASE
            WHEN COALESCE(transactionKind,'manual') <> 'security_deposit_forfeit'
            THEN CASE WHEN type='income' THEN ({$cashLeg})+({$bankLeg}) ELSE -1*({$cashLeg})-({$bankLeg}) END
            ELSE 0 END),0) AS liquid_delta,
        COALESCE(SUM(CASE
            WHEN type='income'
             AND COALESCE(transactionKind,'manual') NOT IN ('internal_transfer','ota_transfer','security_deposit_received','salary_reversal','pos_cogs_reversal','opening_balance_cash','opening_balance_bank')
             AND LOWER(COALESCE(taxSnapshotStatus,'unresolved')) <> 'unresolved'
            THEN CASE
                WHEN baseAmount IS NOT NULL AND taxAmount IS NOT NULL
                 AND ABS(amount - baseAmount - taxAmount) <= 1.0
                THEN GREATEST(0, amount - LEAST(amount, taxAmount))
                ELSE amount
            END ELSE 0 END),0) AS recognized_income
        FROM transactions";
    $row=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC)?:[];
    $cashOnlyIncome=round((float)($row['cash_only_income']??0),2);
    $cashOnlyExpense=round((float)($row['cash_only_expense']??0),2);
    $digitalIncome=round((float)($row['digital_income']??0),2);
    $digitalExpense=round((float)($row['digital_expense']??0),2);
    $liquidIncome=round($cashOnlyIncome+$digitalIncome,2);
    $liquidExpense=round($cashOnlyExpense+$digitalExpense,2);
    $liquidDelta=round((float)($row['liquid_delta']??0),2);
    return [
        // Backward compatible names: historically these represented liquid
        // inflow/outflow, not physical cash only.
        'cashIncome'=>$liquidIncome,
        'cashExpense'=>$liquidExpense,
        'cashFlow'=>round($liquidIncome-$liquidExpense,2),
        'cashOnlyIncome'=>$cashOnlyIncome,
        'cashOnlyExpense'=>$cashOnlyExpense,
        'digitalIncome'=>$digitalIncome,
        'digitalExpense'=>$digitalExpense,
        'liquidIncome'=>$liquidIncome,
        'liquidExpense'=>$liquidExpense,
        'liquidDelta'=>$liquidDelta,
        'liquidBalance'=>round($initialBalance+$liquidDelta,2),
        'recognizedIncome'=>round((float)($row['recognized_income']??0),2),
    ];
}

/** Source line 2776: isOtaBookingSource */
function isOtaBookingSource($source, $paymentMethod = null, $bankAccountId = null) {
    // Payment/account are explicit financial evidence and therefore authoritative.
    if (strtolower(trim((string)$paymentMethod)) === 'ota') return true;
    if (strtolower(trim((string)$bankAccountId)) === 'ota_receivable') return true;

    // Source text alone is intentionally fail-closed. Unknown/free-text labels
    // are Direct/custom, never OTA by default. This prevents a guest name or typo
    // in historical backfill from creating Piutang OTA and selecting OTA tax rules.
    return function_exists('tamasyaIsExplicitOtaSourceLabel')
        ? tamasyaIsExplicitOtaSourceLabel((string)$source)
        : in_array(strtolower(trim((string)$source)), ['ota','traveloka','booking.com','agoda','tiket.com'], true);
}

/** Source line 2789: inferBookingChargeAction */
function inferBookingChargeAction($tx) {
    return tamasyaInferBookingChargeAction((array)$tx);
}

/** Published room tariffs use whole-rupiah nightly gross quotes.  This fixes
 * the Rp227273 + 10% = Rp250000.30 drift without rounding negotiated gross,
 * arbitrary transaction amounts, split allocations, or already posted history.
 * Round each night before multiplying nights, otherwise a two-night stay would
 * become Rp500001 instead of the published Rp500000.
 * rooms.price remains the pre-tax master tariff for compatibility.
 */
function tamasyaPublishedRoomGrossTotal(float $nightlyBase,int $nights,float $taxRate): float {
    if(!is_finite($nightlyBase)||$nightlyBase<0||$nightlyBase>1000000000000||$nights<1||$nights>3650||!is_finite($taxRate)||$taxRate<0||$taxRate>100)throw new InvalidArgumentException('Tarif master kamar atau PBJT tidak valid.');
    $nightlyGross=round($nightlyBase*(1+$taxRate/100),0,PHP_ROUND_HALF_UP);
    $total=$nightlyGross*$nights;
    if(!is_finite($total)||$total>1000000000000)throw new InvalidArgumentException('Total tarif master kamar melebihi batas aman.');
    return round($total,2);
}

/** Source line 2805: calculateInclusiveTaxBreakdown */
function calculateInclusiveTaxBreakdown($amount, $rate) {
    $amount = max(0, (float)$amount);
    $rate = max(0, (float)$rate);
    if ($rate <= 0) {
        return ['baseAmount' => $amount, 'taxAmount' => 0.0, 'taxRate' => 0.0];
    }
    $base = round($amount / (1 + ($rate / 100)), 2);
    $tax = round($amount - $base, 2);
    return ['baseAmount' => $base, 'taxAmount' => $tax, 'taxRate' => $rate];
}

/** Validasi snapshot PBJT komponen agar histori tidak dihitung ulang dari aturan baru. */
function tamasyaValidComponentTaxSnapshot(array $component, float $grossAmount): ?array {
    foreach (['baseAmount','taxAmount','taxRate'] as $key) {
        if (!array_key_exists($key,$component) || $component[$key] === '' || $component[$key] === null || !is_numeric($component[$key])) return null;
    }
    $base=max(0.0,(float)$component['baseAmount']);
    $tax=max(0.0,(float)$component['taxAmount']);
    $rate=max(0.0,(float)$component['taxRate']);
    if ($rate > 100 || !moneyMatches($base+$tax,$grossAmount,1.0)) return null;
    return ['baseAmount'=>round($base,2),'taxAmount'=>round($tax,2),'taxRate'=>round($rate,4)];
}

/**
 * Susun PBJT agregat booking dari komponen kamar dan extra. Snapshot extra yang
 * sudah tersimpan dipertahankan; hanya komponen tanpa snapshot yang memakai
 * tax_rules efektif. Ini mencegah checkout menimpa seluruh booking dengan tarif kamar.
 */
function resolveBookingAggregateTaxSnapshot(PDO $pdo, array $booking, float $grossTotal, ?string $effectiveDate = null): array {
    $grossTotal=max(0.0,round($grossTotal,2));
    $source=trim((string)($booking['bookingSource']??'Direct'))?:'Direct';
    $roomDate=$effectiveDate && validIsoDate($effectiveDate)
        ? $effectiveDate
        : (validIsoDate((string)($booking['checkIn']??''))?(string)$booking['checkIn']:date('Y-m-d'));
    $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
    $extrasGross=0.0;$extrasTax=0.0;$components=[];$normalizedExtras=[];
    foreach($extras as $extra){
        $qty=max(1.0,(float)($extra['qty']??1));
        $unit=max(0.0,(float)($extra['price']??$extra['unitPrice']??0));
        $lineGross=isset($extra['total'])&&is_numeric($extra['total'])
            ? max(0.0,round((float)$extra['total'],2))
            : round($unit*$qty,2);
        $snapshot=tamasyaValidComponentTaxSnapshot($extra,$lineGross);
        if($snapshot===null){
            $extraDate=substr((string)($extra['createdAt']??''),0,10);
            if(!validIsoDate($extraDate))$extraDate=$roomDate;
            $componentTaxKind=strtolower(trim((string)($extra['taxKind']??'extra')));
            if(!in_array($componentTaxKind,['extra','extension'],true))$componentTaxKind='extra';
            $rate=resolveConfiguredTaxRate($pdo,$source,$componentTaxKind,0,$extraDate);
            $snapshot=calculateInclusiveTaxBreakdown($lineGross,$rate);
        }
        $extra['total']=$lineGross;
        $extra['baseAmount']=$snapshot['baseAmount'];
        $extra['taxAmount']=$snapshot['taxAmount'];
        $extra['taxRate']=$snapshot['taxRate'];
        $normalizedExtras[]=$extra;
        $extrasGross+=$lineGross;$extrasTax+=(float)$snapshot['taxAmount'];
        if($lineGross>0.0001)$components[]=(float)$snapshot['taxRate'];
    }
    $extrasGross=round($extrasGross,2);
    if($extrasGross>$grossTotal+1.0)throw new RuntimeException('Total layanan extra melebihi total booking. Koreksi data wajib dilakukan sebelum menghitung PBJT.');
    $roomGross=max(0.0,round($grossTotal-$extrasGross,2));
    $roomRate=resolveConfiguredTaxRate($pdo,$source,'room',0,$roomDate);
    $roomTax=calculateInclusiveTaxBreakdown($roomGross,$roomRate);
    if($roomGross>0.0001)$components[]=(float)$roomTax['taxRate'];
    $aggregateTax=round((float)$roomTax['taxAmount']+$extrasTax,2);
    $aggregateBase=round($grossTotal-$aggregateTax,2);
    $aggregateRate=null;
    if($components){
        $first=(float)$components[0];
        $aggregateRate=count(array_filter($components,static fn($rate)=>abs((float)$rate-$first)>0.0001))===0?$first:null;
    }
    return [
        'baseAmount'=>$aggregateBase,'taxAmount'=>$aggregateTax,'taxRate'=>$aggregateRate,
        'roomGross'=>$roomGross,'roomTax'=>$roomTax,'extrasGross'=>$extrasGross,'extrasTax'=>round($extrasTax,2),
        'extras'=>$normalizedExtras
    ];
}

/**
 * Historical imports preserve the tax result selected at entry time. The
 * default backfill policy is rule-by-date; document snapshots are explicit
 * overrides, and genuinely unclassifiable rows remain unresolved.
 */

/** Validate a booking-level PBJT snapshot without forcing a single rate.
 * A NULL rate is valid for a booking whose room/extension/extra components
 * legitimately use different rates; vatAmount remains the accounting truth.
 */
function tamasyaValidBookingTaxSnapshot(array $booking, float $grossTotal): ?array {
    $grossTotal=max(0.0,round($grossTotal,2));
    $rawTax=$booking['vatAmount']??null;
    $rawRate=$booking['vatRate']??null;
    if(!is_numeric($rawTax))return null;
    $tax=round((float)$rawTax,2);
    if(!is_finite($tax)||$tax<0||$tax>$grossTotal+1.0)return null;
    $rate=null;
    if($rawRate!==null&&$rawRate!==''){
        if(!is_numeric($rawRate))return null;
        $rate=(float)$rawRate;
        if(!is_finite($rate)||$rate<0||$rate>100)return null;
    }
    return [
        'baseAmount'=>round(max(0.0,$grossTotal-$tax),2),
        'taxAmount'=>$tax,
        'taxRate'=>$rate,
        'extras'=>tamasyaDecodeBookingExtras($booking['extras']??null),
        'preserved'=>true,
    ];
}

/** Preserve a valid historical/current booking snapshot. Recalculate only when
 * the snapshot is missing or structurally impossible.
 */
function resolveBookingTaxSnapshotForSettlement(PDO $pdo, array $booking, float $grossTotal, ?string $effectiveDate=null): array {
    $snapshot=tamasyaValidBookingTaxSnapshot($booking,$grossTotal);
    if($snapshot!==null)return $snapshot;
    $resolved=resolveBookingAggregateTaxSnapshot($pdo,$booking,$grossTotal,$effectiveDate);
    $resolved['preserved']=false;
    return $resolved;
}


/**
 * Server-authoritative room-transfer financial plan.
 *
 * `rooms.price` is the configured BASE nightly room rate. `bookings.totalAmount`
 * is the GROSS folio total. A rate change therefore cannot simply add the raw
 * difference between room master prices to totalAmount: the PBJT component must
 * be adjusted too, and nights already elapsed must keep the prior booked rate.
 *
 * The plan is intentionally NON-CASH. It changes the folio snapshot only; any
 * money received later still goes through booking-payments/checkout so receipts,
 * shift ownership, allocations, journal projection, and idempotency stay canonical.
 */
function tamasyaBuildRoomTransferFinancialPlan(
    PDO $pdo,
    array $booking,
    array $sourceRoom,
    array $targetRoom,
    string $rateMode = 'keep',
    float $surchargeGross = 0.0,
    ?string $effectiveDate = null
): array {
    $rateMode=strtolower(trim($rateMode));
    if(!in_array($rateMode,['keep','update'],true))throw new InvalidArgumentException('Mode penyesuaian tarif pindah kamar tidak valid.');
    if(!is_finite($surchargeGross)||$surchargeGross<0||$surchargeGross>1000000000000)throw new InvalidArgumentException('Biaya tambahan pindah kamar tidak valid.');
    $surchargeGross=round($surchargeGross,2);

    $effectiveDate=($effectiveDate&&validIsoDate($effectiveDate))?$effectiveDate:date('Y-m-d');
    $bookingId=trim((string)($booking['id']??''));
    $source=trim((string)($booking['bookingSource']??'Direct'))?:'Direct';
    $oldTotal=max(0.0,round((float)($booking['totalAmount']??0),2));
    $oldSnapshot=resolveBookingTaxSnapshotForSettlement(
        $pdo,$booking,$oldTotal,
        validIsoDate((string)($booking['checkIn']??''))?(string)$booking['checkIn']:$effectiveDate
    );
    $oldVat=max(0.0,min($oldTotal,round((float)($oldSnapshot['taxAmount']??0),2)));

    $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
    $extrasGross=0.0;$extrasTax=0.0;
    foreach($extras as $extra){
        $qty=max(1.0,(float)($extra['qty']??1));
        $unit=max(0.0,(float)($extra['price']??$extra['unitPrice']??0));
        $gross=isset($extra['total'])&&is_numeric($extra['total'])
            ? max(0.0,round((float)$extra['total'],2))
            : round($unit*$qty,2);
        $snap=tamasyaValidComponentTaxSnapshot($extra,$gross);
        if($snap===null){
            $extraDate=substr((string)($extra['createdAt']??''),0,10);
            if(!validIsoDate($extraDate))$extraDate=validIsoDate((string)($booking['checkIn']??''))?(string)$booking['checkIn']:$effectiveDate;
            $kind=strtolower(trim((string)($extra['taxKind']??'extra')));
            if(!in_array($kind,['extra','extension'],true))$kind='extra';
            $rule=resolveConfiguredTaxRule($pdo,$source,$kind,$extraDate);
            if(empty($rule['matched'])||!is_numeric($rule['rate']??null))throw new RuntimeException('Snapshot PBJT layanan lama tidak lengkap dan rule historis tidak ditemukan. Pindah kamar dengan perubahan tarif ditolak.');
            $snap=calculateInclusiveTaxBreakdown($gross,(float)$rule['rate']);
        }
        $extrasGross=round($extrasGross+$gross,2);
        $extrasTax=round($extrasTax+(float)$snap['taxAmount'],2);
    }
    if($extrasGross>$oldTotal+1.0)throw new RuntimeException('Total layanan extra melebihi total booking. Pindah kamar ditolak sampai folio dikoreksi.');

    $oldRoomGross=max(0.0,round($oldTotal-$extrasGross,2));
    $oldRoomTax=round($oldVat-$extrasTax,2);
    if($oldRoomTax<-0.01||$oldRoomTax>$oldRoomGross+0.01)throw new RuntimeException('Snapshot PBJT kamar lama tidak konsisten dengan total folio. Pindah kamar dengan perubahan tarif ditolak.');
    $oldRoomTax=max(0.0,min($oldRoomGross,$oldRoomTax));
    $oldRoomBase=max(0.0,round($oldRoomGross-$oldRoomTax,2));

    $stayMode=strtolower(trim((string)($booking['stayMode']??'overnight')));
    $isOpenEnded=(int)($booking['isOpenEnded']??0)===1;
    $checkIn=(string)($booking['checkIn']??'');
    $checkOut=(string)($booking['checkOut']??'');
    if(!validIsoDate($checkIn)||!validIsoDate($checkOut))throw new RuntimeException('Tanggal booking tidak valid untuk perhitungan pindah kamar.');
    $ci=new DateTimeImmutable($checkIn.' 00:00:00');
    $co=new DateTimeImmutable($checkOut.' 00:00:00');
    $totalNights=max(1,(int)$ci->diff($co)->days);
    $remainingNights=0;
    if($rateMode==='update'){
        if($isOpenEnded||$stayMode!=='overnight')throw new RuntimeException('Sesuaikan Tarif Baru hanya tersedia untuk booking overnight bertanggal pasti. Gunakan Tetap Tarif Lama untuk short-time/open-ended.');
        $startDate=$effectiveDate>$checkIn?$effectiveDate:$checkIn;
        if($startDate<$checkOut){
            $rs=new DateTimeImmutable($startDate.' 00:00:00');
            $remainingNights=max(0,(int)$rs->diff($co)->days);
        }
        if($remainingNights<=0)throw new RuntimeException('Tidak ada malam tersisa yang dapat dikenai tarif kamar baru. Gunakan Tetap Tarif Lama.');
    }

    $currentNightlyBase=$totalNights>0?round($oldRoomBase/$totalNights,2):0.0;
    $sourceMasterNightlyBase=max(0.0,round((float)($sourceRoom['price']??0),2));
    $targetNightlyBase=max(0.0,round((float)($targetRoom['price']??0),2));
    if($rateMode==='update'&&$targetNightlyBase<=0)throw new RuntimeException('Tarif dasar kamar tujuan tidak valid.');
    if($rateMode==='update'){
        $homogeneousExpectedBase=round($sourceMasterNightlyBase*$totalNights,2);
        if($sourceMasterNightlyBase<=0 || !moneyMatches($oldRoomBase,$homogeneousExpectedBase,1.0)){
            throw new RuntimeException('Tarif booking lama tidak homogen dengan tarif master kamar asal (misalnya harga negosiasi, perpanjangan khusus, atau penyesuaian sebelumnya). Sesuaikan Tarif Baru ditolak agar malam yang sudah lewat tidak dihitung ulang; gunakan Tetap Tarif Lama atau koreksi terkontrol.');
        }
    }

    $roomRule=resolveConfiguredTaxRule($pdo,$source,'room',$effectiveDate);
    if(($rateMode==='update'||$surchargeGross>0.0001) && (empty($roomRule['matched'])||!is_numeric($roomRule['rate']??null))){
        throw new RuntimeException('Aturan Pajak kamar aktif tidak ditemukan untuk tanggal pindah kamar. Rule 0% juga harus dibuat eksplisit.');
    }
    $targetRate=($rateMode==='update'||$surchargeGross>0.0001)?max(0.0,(float)($roomRule['rate']??0)):0.0;
    $oldEffectiveRate=$oldRoomBase>0.0001?round(($oldRoomTax/$oldRoomBase)*100,6):$targetRate;
    // Booking saat ini menyimpan satu snapshot agregat untuk komponen kamar. Jika
    // tarif PBJT kamar berubah di tengah stay, satu bucket room tidak cukup untuk
    // mempertahankan tax allocation receipt lama dan baru secara presisi. Fail
    // closed sampai folio per-night/rate-segment tersedia.
    if(($rateMode==='update'||$surchargeGross>0.0001) && $oldRoomBase>0.0001 && abs($oldEffectiveRate-$targetRate)>0.02){
        throw new RuntimeException('Tarif PBJT kamar pada tanggal pindah berbeda dari snapshot booking lama. Gunakan Tetap Tarif Lama tanpa surcharge atau lakukan split/koreksi terkontrol; sistem menolak pencampuran rate pajak kamar dalam satu folio.');
    }

    $rateBaseDelta=0.0;$rateTaxDelta=0.0;$rateGrossDelta=0.0;
    if($rateMode==='update'){
        $oldRemainingBase=round($currentNightlyBase*$remainingNights,2);
        $oldRemainingTax=round($oldRemainingBase*($oldEffectiveRate/100),2);
        $newRemainingBase=round($targetNightlyBase*$remainingNights,2);
        $newRemainingTax=round($newRemainingBase*($targetRate/100),2);
        $rateBaseDelta=round($newRemainingBase-$oldRemainingBase,2);
        $rateTaxDelta=round($newRemainingTax-$oldRemainingTax,2);
        $rateGrossDelta=round($rateBaseDelta+$rateTaxDelta,2);
    }

    $surchargeTax=0.0;$surchargeBase=$surchargeGross;
    if($surchargeGross>0.0001){
        $surchargeBreakdown=calculateInclusiveTaxBreakdown($surchargeGross,$targetRate);
        $surchargeBase=(float)$surchargeBreakdown['baseAmount'];
        $surchargeTax=(float)$surchargeBreakdown['taxAmount'];
    }

    $totalDelta=round($rateGrossDelta+$surchargeGross,2);
    $taxDelta=round($rateTaxDelta+$surchargeTax,2);
    $newTotal=round($oldTotal+$totalDelta,2);
    $newVat=round($oldVat+$taxDelta,2);
    if($newTotal<0||$newVat<0||$newVat>$newTotal+0.01)throw new RuntimeException('Hasil penyesuaian tarif/pajak pindah kamar tidak valid.');

    $ledger=$bookingId!==''?bookingLedgerTotals($pdo,$bookingId):['net'=>0.0];
    $ledgerNet=max(0.0,round((float)($ledger['net']??0),2));
    if($newTotal+0.01<$ledgerNet){
        $excess=round($ledgerNet-$newTotal,2);
        throw new RuntimeException('Tarif kamar baru membuat penerimaan booking melebihi tagihan baru sebesar Rp '.number_format($excess,0,',','.').'. Pilih Tetap Tarif Lama atau lakukan koreksi/refund terkontrol sebelum downgrade tarif.');
    }

    $newVatRate=$booking['vatRate']??null;
    if($newVatRate!==null&&$newVatRate!==''&&is_numeric($newVatRate)){
        $newVatRate=(float)$newVatRate;
        if(($rateMode==='update'||$surchargeGross>0.0001)&&abs($newVatRate-$targetRate)>0.0001)$newVatRate=null;
    }else{$newVatRate=null;}

    return [
        'rateMode'=>$rateMode,'effectiveDate'=>$effectiveDate,
        'sourceRoomNumber'=>(string)($sourceRoom['number']??$booking['roomNumber']??''),
        'targetRoomNumber'=>(string)($targetRoom['number']??''),
        'totalNights'=>$totalNights,'remainingNights'=>$remainingNights,
        'currentNightlyBase'=>$currentNightlyBase,'targetNightlyBase'=>$targetNightlyBase,
        'oldEffectiveTaxRate'=>$oldEffectiveRate,'targetTaxRate'=>$targetRate,
        'rateBaseDelta'=>$rateBaseDelta,'rateTaxDelta'=>$rateTaxDelta,'rateGrossDelta'=>$rateGrossDelta,
        'surchargeGross'=>$surchargeGross,'surchargeBase'=>round($surchargeBase,2),'surchargeTax'=>round($surchargeTax,2),
        'totalDelta'=>$totalDelta,'taxDelta'=>$taxDelta,
        'oldTotalAmount'=>$oldTotal,'newTotalAmount'=>$newTotal,
        'oldVatAmount'=>$oldVat,'newVatAmount'=>$newVat,'newVatRate'=>$newVatRate,
        'ledgerNet'=>$ledgerNet,'balanceAfter'=>max(0.0,round($newTotal-$ledgerNet,2)),
        'taxRuleId'=>$roomRule['ruleId']??null,'taxRuleName'=>$roomRule['ruleName']??null,
        'cashMutation'=>false,
    ];
}

/** Allocate the booking's aggregate PBJT to the next cash receipt without
 * assuming all booking components have the room rate. The final receipt owns
 * any rounding residual so the sum of transaction tax equals bookings.vatAmount.
 */
function resolveBookingPaymentTaxAllocation(PDO $pdo, array $booking, float $amount, float $ledgerNetBefore, ?string $effectiveDate=null): array {
    $amount=max(0.0,round($amount,2));
    $gross=max(0.0,round((float)($booking['totalAmount']??0),2));
    if($amount<=0)return ['baseAmount'=>0.0,'taxAmount'=>0.0,'taxRate'=>$booking['vatRate']??null,'bookingTax'=>0.0];
    $snapshot=resolveBookingTaxSnapshotForSettlement($pdo,$booking,$gross,$effectiveDate);
    $bookingTax=max(0.0,round((float)$snapshot['taxAmount'],2));
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN COALESCE(taxAmount,0) ELSE -COALESCE(taxAmount,0) END),0) FROM transactions WHERE bookingId=?");
    $stmt->execute([(string)($booking['id']??'')]);
    $recorded=max(0.0,round((float)$stmt->fetchColumn(),2));
    $targetNet=$gross>0?min($gross,max(0.0,round($ledgerNetBefore+$amount,2))):$amount;
    $targetTax=$gross>0
        ? ($targetNet>=$gross-0.01?$bookingTax:round($bookingTax*($targetNet/$gross),2))
        : 0.0;
    $tax=max(0.0,min($amount,round($targetTax-$recorded,2)));
    $rate=$snapshot['taxRate']??null;
    return [
        'baseAmount'=>round($amount-$tax,2),'taxAmount'=>$tax,'taxRate'=>$rate,
        'bookingTax'=>$bookingTax,'recordedTax'=>$recorded,'targetTax'=>$targetTax,
        'snapshot'=>$snapshot,
    ];
}


/**
 * Build canonical cash-basis revenue components for a booking receipt.
 * Room/extension revenue remains room revenue; extra/POS keeps its own category.
 * A booking-level NULL tax rate is valid when components legitimately have
 * different rates. Amount/base/tax remain the accounting source of truth.
 */
function tamasyaBookingReceiptComponents(PDO $pdo, array $booking, ?string $effectiveDate=null): array {
    $bookingId=trim((string)($booking['id']??''));
    $gross=max(0.0,round((float)($booking['totalAmount']??0),2));
    $source=trim((string)($booking['bookingSource']??'Direct'))?:'Direct';
    $serviceDate=validIsoDate((string)($booking['checkIn']??''))?(string)$booking['checkIn']:
        (($effectiveDate&&validIsoDate($effectiveDate))?$effectiveDate:date('Y-m-d'));
    $snapshot=resolveBookingTaxSnapshotForSettlement($pdo,$booking,$gross,$serviceDate);
    $extras=tamasyaDecodeBookingExtras($snapshot['extras']??($booking['extras']??null));
    $roomRevenueCategory=tamasyaRequireSystemFinanceCategory($pdo,'room_rental','income');
    $defaultExtraCategory=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income');
    $components=[];$extrasGross=0.0;$extrasTax=0.0;
    foreach($extras as $idx=>$extra){
        $qty=max(1.0,(float)($extra['qty']??1));
        $unit=max(0.0,(float)($extra['price']??$extra['unitPrice']??0));
        $lineGross=isset($extra['total'])&&is_numeric($extra['total'])
            ? max(0.0,round((float)$extra['total'],2)) : round($unit*$qty,2);
        if($lineGross<=0.0001)continue;
        $tax=tamasyaValidComponentTaxSnapshot($extra,$lineGross);
        $taxRuleId=trim((string)($extra['taxRuleId']??''))?:null;
        $taxSource=trim((string)($extra['taxSource']??''))?:'booking_extra_snapshot';
        if($tax===null){
            $extraDate=substr((string)($extra['createdAt']??''),0,10);
            if(!validIsoDate($extraDate))$extraDate=$serviceDate;
            $componentTaxKind=strtolower(trim((string)($extra['taxKind']??'extra')));
            if(!in_array($componentTaxKind,['extra','extension'],true))$componentTaxKind='extra';
            $rule=resolveConfiguredTaxRule($pdo,$source,$componentTaxKind,$extraDate);
            if(empty($rule['matched'])||!is_numeric($rule['rate']??null))throw new RuntimeException('Rule PBJT komponen booking tidak ditemukan. Receipt dibatalkan.');
            $tax=calculateInclusiveTaxBreakdown($lineGross,(float)$rule['rate']);
            $taxRuleId=trim((string)($rule['ruleId']??''))?:null;
            $taxSource='live_rule';
        }
        $extraCategoryId=trim((string)($extra['categoryId']??$extra['category_id']??''));
        $extraCategoryName=trim((string)($extra['category']??''));
        $extraSubcategoryId=trim((string)($extra['subcategoryId']??$extra['subcategory_id']??''));
        $extraSubcategoryName=trim((string)($extra['subcategory']??''));
        if($extraCategoryId!==''||$extraCategoryName!==''){
            $catalog=tamasyaResolveFinanceCatalogSelection($pdo,[
                'type'=>'income','categoryId'=>$extraCategoryId?:null,'category'=>$extraCategoryName,
                'subcategoryId'=>$extraSubcategoryId?:null,'subcategory'=>$extraSubcategoryName?:null,
            ],false,true);
            $category=$catalog['categoryName'];$categoryId=$catalog['categoryId'];$categorySystemKey=$catalog['categorySystemKey']?:null;
            $subcategory=$catalog['subcategoryName'];$subcategoryId=$catalog['subcategoryId'];$subcategorySystemKey=$catalog['subcategorySystemKey']?:null;
        }else{
            $category=(string)$defaultExtraCategory['name'];$categoryId=(string)$defaultExtraCategory['id'];$categorySystemKey='extra_service';
            $subcategory=null;$subcategoryId=null;$subcategorySystemKey=null;
        }
        $allocationType=tamasyaBookingExtraIsRoomCharge($extra)?'room':'extra';
        if(!in_array($allocationType,['room','extra'],true))$allocationType='extra';
        $components[]=[
            'key'=>'extra:'.(trim((string)($extra['id']??''))?:('idx'.$idx)),
            'allocationType'=>$allocationType,'bookingExtraId'=>trim((string)($extra['id']??''))?:null,
            'gross'=>$lineGross,'tax'=>round((float)$tax['taxAmount'],2),'rate'=>(float)$tax['taxRate'],
            'category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
            'subcategory'=>$subcategory?:null,'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,'taxRuleId'=>$taxRuleId,
            'taxSource'=>$taxSource,'description'=>'Alokasi receipt layanan '.($subcategory?:trim((string)($extra['name']??''))?:$category),
        ];
        $extrasGross=round($extrasGross+$lineGross,2);$extrasTax=round($extrasTax+(float)$tax['taxAmount'],2);
    }
    if($extrasGross>$gross+1.0)throw new RuntimeException('Total extra melebihi total booking saat alokasi receipt.');
    $roomGross=max(0.0,round($gross-$extrasGross,2));
    $bookingTax=max(0.0,round((float)($snapshot['taxAmount']??0),2));
    $roomTax=round($bookingTax-$extrasTax,2);
    $roomRule=resolveConfiguredTaxRule($pdo,$source,'room',$serviceDate);
    if($roomGross>0.0001 && ($roomTax<-0.01 || $roomTax>$roomGross+0.01)){
        if(empty($roomRule['matched'])||!is_numeric($roomRule['rate']??null))throw new RuntimeException('Rule PBJT kamar tidak ditemukan. Receipt dibatalkan.');
        $roomBreakdown=calculateInclusiveTaxBreakdown($roomGross,(float)$roomRule['rate']);
        $roomTax=(float)$roomBreakdown['taxAmount'];
    }
    $roomTax=max(0.0,min($roomGross,round($roomTax,2)));
    if($roomGross>0.0001){
        $roomBase=max(0.0,round($roomGross-$roomTax,2));
        $roomRate=$roomTax<=0.0001?0.0:($roomBase>0.0001?round(($roomTax/$roomBase)*100,4):(float)($roomRule['rate']??0));
        $roomRuleId=null;
        if(!empty($roomRule['matched'])&&is_numeric($roomRule['rate']??null)&&abs((float)$roomRule['rate']-$roomRate)<0.02){
            $roomRuleId=trim((string)($roomRule['ruleId']??''))?:null;
        }
        array_unshift($components,[
            'key'=>'room','allocationType'=>'room','bookingExtraId'=>null,'gross'=>$roomGross,'tax'=>$roomTax,'rate'=>$roomRate,
            'category'=>(string)$roomRevenueCategory['name'],'categoryId'=>(string)$roomRevenueCategory['id'],'categorySystemKey'=>'room_rental',
            'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,
            'taxRuleId'=>$roomRuleId,'taxSource'=>'booking_room_snapshot',
            'description'=>'Alokasi receipt sewa/perpanjangan kamar',
        ]);
    }
    return ['components'=>$components,'gross'=>$gross,'tax'=>$bookingTax,'serviceDate'=>$serviceDate,'bookingId'=>$bookingId];
}

/** Add an amount to component buckets in deterministic order, respecting caps. */
function tamasyaDistributeComponentAmount(array &$paid,array $components,float $amount,?callable $filter=null): float {
    $remaining=max(0.0,round($amount,2));
    foreach($components as $component){
        if($remaining<=0.0001)break;
        if($filter!==null&&!$filter($component))continue;
        $key=(string)$component['key'];$cap=max(0.0,(float)$component['gross']);$current=max(0.0,(float)($paid[$key]??0));
        $room=max(0.0,round($cap-$current,2));if($room<=0.0001)continue;
        $take=min($room,$remaining);$paid[$key]=round($current+$take,2);$remaining=round($remaining-$take,2);
    }
    return $remaining;
}


/** Pure receipt allocator used by runtime and deterministic financial fuzz tests. */
function tamasyaBuildReceiptAllocationSegments(array $components,array $paid,float $amount): array {
    $remaining=max(0.0,round($amount,2));$segments=[];
    $taxAt=static function(float $paidAmount,float $gross,float $tax):float{
        if($gross<=0.0001||$tax<=0.0001)return 0.0;
        if($paidAmount>=$gross-0.005)return round($tax,2);
        return round($tax*($paidAmount/$gross),2);
    };
    foreach($components as $c){
        if($remaining<=0.0001)break;
        $key=(string)$c['key'];$gross=max(0.0,(float)$c['gross']);$before=min($gross,max(0.0,(float)($paid[$key]??0)));
        $available=max(0.0,round($gross-$before,2));if($available<=0.0001)continue;
        $take=min($available,$remaining);$after=min($gross,round($before+$take,2));$componentTax=max(0.0,min($gross,(float)$c['tax']));
        $segTax=max(0.0,round($taxAt($after,$gross,$componentTax)-$taxAt($before,$gross,$componentTax),2));$segTax=min($take,$segTax);
        $segments[]=$c+['amount'=>round($take,2),'baseAmount'=>round($take-$segTax,2),'taxAmount'=>$segTax];
        $paid[$key]=$after;$remaining=round($remaining-$take,2);
    }
    if($remaining>0.01)throw new RuntimeException('Nominal receipt melebihi komponen folio yang tersedia.');
    return ['segments'=>$segments,'paid'=>$paid,'unallocated'=>max(0.0,$remaining)];
}

/**
 * Attach reporting/accounting allocations to a standard booking receipt without
 * changing cash or booking totals. Prior explicit allocations/booking charges are
 * respected first, then unclassified receipts settle room first and remaining
 * extras in folio order. This keeps cash-basis revenue classification stable.
 */
/** Reuse the exact receipt allocation rules for quotes and negotiated prices. */
function tamasyaBookingComponentPaid(PDO $pdo,array $booking,array $bundle,float $ledgerNetBefore,string $transactionId=''): array {
    $bookingId=(string)$booking['id'];$components=$bundle['components'];
    $byKey=[];foreach($components as $c)$byKey[$c['key']]=$c;
    $paid=[];foreach($components as $c)$paid[$c['key']]=0.0;

    // Existing active allocations are explicit component evidence.
    $q=$pdo->prepare("SELECT a.*,t.type FROM transaction_allocations a JOIN transactions t ON t.id=a.transaction_id WHERE a.booking_id=? AND a.status='active' AND t.type='income' AND a.transaction_id<>? ORDER BY a.created_at,a.id");
    $q->execute([$bookingId,$transactionId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $a){
        $amt=max(0.0,round((float)($a['amount']??0),2));if($amt<=0)continue;
        $extraId=trim((string)($a['booking_extra_id']??''));$type=strtolower((string)($a['allocation_type']??'room'));
        $target=null;
        // A component ID is stronger evidence than the broad allocation type.
        // This matters for extensions: they post to room revenue but remain a
        // distinct folio component so a later receipt cannot pay them twice.
        if($extraId!=='')foreach($components as $c)if(($c['bookingExtraId']??null)===$extraId){$target=$c['key'];break;}
        if($target===null&&in_array($type,['room','extension'],true)&&isset($byKey['room']))$target='room';
        if($target===null&&$type==='extra'){
            foreach($components as $c)if($c['allocationType']==='extra'&&($paid[$c['key']]??0)<$c['gross']-0.01){$target=$c['key'];break;}
        }
        if($target!==null)$paid[$target]=min((float)$byKey[$target]['gross'],round((float)$paid[$target]+$amt,2));
    }

    // Paid booking_charge rows predating allocation support remain component evidence.
    $q=$pdo->prepare("SELECT t.* FROM transactions t WHERE t.bookingId=? AND t.id<>? AND t.type='income' AND t.transactionKind='booking_charge' AND NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id=t.id AND a.status='active') ORDER BY t.`date`,t.createdAt,t.id");
    $q->execute([$bookingId,$transactionId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $tx){
        $amt=max(0.0,round((float)($tx['amount']??0),2));if($amt<=0)continue;
        $action=tamasyaInferBookingChargeAction($tx);
        if($action==='extra'){
            $cat=strtolower(trim((string)($tx['category']??'')));$sub=strtolower(trim((string)($tx['subcategory']??'')));
            $left=tamasyaDistributeComponentAmount($paid,$components,$amt,static function($c)use($cat,$sub){
                if($c['allocationType']!=='extra')return false;
                $cc=strtolower(trim((string)($c['category']??'')));$ss=strtolower(trim((string)($c['subcategory']??'')));
                return ($cat!==''&&$cc===$cat)||($sub!==''&&$ss===$sub);
            });
            if($left>0.0001)tamasyaDistributeComponentAmount($paid,$components,$left,static fn($c)=>$c['allocationType']==='extra');
        }elseif(isset($byKey['room'])){
            tamasyaDistributeComponentAmount($paid,$components,$amt,static fn($c)=>$c['key']==='room');
        }
    }

    // Refunds/reversals can make explicit historical evidence exceed current net.
    // Trim the latest component buckets first so effective paid-before equals ledger net.
    $netBefore=max(0.0,min((float)$bundle['gross'],round($ledgerNetBefore,2)));
    $specific=round(array_sum($paid),2);
    if($specific>$netBefore+0.01){
        $trim=round($specific-$netBefore,2);
        foreach(array_reverse($components) as $c){
            if($trim<=0.0001)break;$key=$c['key'];$cur=(float)($paid[$key]??0);$cut=min($cur,$trim);$paid[$key]=round($cur-$cut,2);$trim=round($trim-$cut,2);
        }
    }
    $specific=round(array_sum($paid),2);
    $general=max(0.0,round($netBefore-$specific,2));
    if($general>0.0001)tamasyaDistributeComponentAmount($paid,$components,$general);

    return $paid;
}

function tamasyaAttachBookingReceiptRevenueAllocations(PDO $pdo,array $booking,string $transactionId,float $amount,float $ledgerNetBefore,string $operationId,array $actor,string $source='web',?string $effectiveDate=null): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Alokasi receipt booking wajib berada dalam transaksi database.');
    $transactionId=trim($transactionId);$operationId=trim($operationId);$amount=max(0.0,round($amount,2));
    if($transactionId===''||$operationId===''||$amount<=0.0)return ['amount'=>0.0,'baseAmount'=>0.0,'taxAmount'=>0.0,'taxRate'=>null,'allocations'=>[]];
    $bookingId=trim((string)($booking['id']??''));if($bookingId==='')throw new RuntimeException('Booking receipt tidak memiliki ID.');
    $bundle=tamasyaBookingReceiptComponents($pdo,$booking,$effectiveDate);$components=$bundle['components'];
    if(!$components)throw new RuntimeException('Komponen folio booking kosong. Receipt dibatalkan.');
    $paid=tamasyaBookingComponentPaid($pdo,$booking,$bundle,$ledgerNetBefore,$transactionId);
    $outstanding=0.0;foreach($components as $c)$outstanding=round($outstanding+max(0.0,(float)$c['gross']-(float)($paid[$c['key']]??0)),2);
    if($amount>$outstanding+0.01){
        $openGross=round($amount-$outstanding,2);$serviceDate=(string)$bundle['serviceDate'];
        $rule=resolveConfiguredTaxRule($pdo,trim((string)($booking['bookingSource']??'Direct'))?:'Direct','room',$serviceDate);
        if(empty($rule['matched'])||!is_numeric($rule['rate']??null))throw new RuntimeException('Receipt melebihi komponen folio dan rule kamar tidak tersedia.');
        $tax=calculateInclusiveTaxBreakdown($openGross,(float)$rule['rate']);$openKey='room:open';
        $roomRevenueCategory=tamasyaRequireSystemFinanceCategory($pdo,'room_rental','income');
        $components[]=['key'=>$openKey,'allocationType'=>'room','bookingExtraId'=>null,'gross'=>$openGross,'tax'=>$tax['taxAmount'],'rate'=>$tax['taxRate'],
            'category'=>(string)$roomRevenueCategory['name'],'categoryId'=>(string)$roomRevenueCategory['id'],'categorySystemKey'=>'room_rental',
            'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,
            'taxRuleId'=>$rule['ruleId']??null,'taxSource'=>'live_rule','description'=>'Alokasi receipt kamar open-ended'];
        $paid[$openKey]=0.0;
    }
    $built=tamasyaBuildReceiptAllocationSegments($components,$paid,$amount);$segments=$built['segments'];

    $insert=$pdo->prepare("INSERT INTO transaction_allocations
        (id,operation_id,transaction_id,booking_id,allocation_type,amount,base_amount,tax_amount,tax_rate,tax_snapshot_status,tax_source,tax_rule_id,
         category,category_id,category_system_key,subcategory,subcategory_id,subcategory_system_key,booking_extra_id,description,
         booking_total_delta,extra_was_existing,reporting_only,transaction_booking_link_added,transaction_room_link_added,transaction_source_link_added,status,created_by,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,'confirmed',?,?,?,?,?,?,?,?,?,?,0,0,0,0,0,0,'active',?,CURRENT_TIMESTAMP)");
    $baseTotal=0.0;$taxTotal=0.0;$rates=[];$rules=[];$rows=[];
    foreach($segments as $i=>$seg){
        $allocOp='auto_receipt:'.substr(hash('sha256',$operationId.'|'.$transactionId.'|'.$i),0,64);
        $allocId='al_auto_'.substr(hash('sha256',$transactionId.'|'.$allocOp),0,48);
        $rate=(float)($seg['rate']??0);$ruleId=trim((string)($seg['taxRuleId']??''))?:null;
        $insert->execute([$allocId,$allocOp,$transactionId,$bookingId,$seg['allocationType'],$seg['amount'],$seg['baseAmount'],$seg['taxAmount'],$rate,
            $seg['taxSource']??'booking_component_snapshot',$ruleId,
            $seg['category']??null,$seg['categoryId']??null,$seg['categorySystemKey']??null,
            $seg['subcategory']??null,$seg['subcategoryId']??null,$seg['subcategorySystemKey']??null,
            $seg['bookingExtraId']??null,$seg['description']??null,$actor['id']??null]);
        $baseTotal=round($baseTotal+(float)$seg['baseAmount'],2);$taxTotal=round($taxTotal+(float)$seg['taxAmount'],2);$rates[]=round($rate,4);if($ruleId!==null)$rules[]=$ruleId;$rows[]=$seg;
    }
    if(!moneyMatches($baseTotal+$taxTotal,$amount,0.01))throw new RuntimeException('Alokasi komponen receipt tidak sama dengan nominal transaksi.');
    $uniqueRates=array_values(array_unique(array_map(static fn($v)=>number_format((float)$v,4,'.',''),$rates)));
    $aggregateRate=count($uniqueRates)===1?(float)$rates[0]:null;
    $uniqueRules=array_values(array_unique($rules));$aggregateRule=count($uniqueRules)===1?$uniqueRules[0]:null;
    $aggregateRuleName=$aggregateRule!==null?tamasyaTaxRuleNameById($pdo,$aggregateRule):null;
    tamasyaMutateFinancialTransaction($pdo,$transactionId,[
        'baseAmount'=>$baseTotal,'taxAmount'=>$taxTotal,'taxRate'=>$aggregateRate,
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'booking_component_allocation','taxRuleId'=>$aggregateRule,
        'taxRuleName'=>$aggregateRuleName,
    ],$actor,'tax_projection','tax_snapshot',['source'=>$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]);
    return ['amount'=>$amount,'baseAmount'=>$baseTotal,'taxAmount'=>$taxTotal,'taxRate'=>$aggregateRate,'allocations'=>$rows];
}

function tamasyaNormalizeHistoricalTaxSnapshot(array $payload, float $amount, bool $requireComplete = false): array {
    $values=[];
    foreach (['baseAmount','taxAmount','taxRate'] as $key) {
        $raw=$payload[$key] ?? null;
        $values[$key]=($raw === '' || $raw === null) ? null : (float)$raw;
    }
    $present=array_filter($values, static fn($value) => $value !== null);
    if(count($present)===0){
        if($requireComplete)throw new InvalidArgumentException('Snapshot PBJT historis wajib lengkap untuk proses ini.');
        return ['complete'=>false,'baseAmount'=>null,'taxAmount'=>null,'taxRate'=>null];
    }
    if(count($present)!==3)throw new InvalidArgumentException('Snapshot PBJT historis harus diisi lengkap atau seluruhnya dikosongkan.');
    $base=(float)$values['baseAmount'];
    $tax=(float)$values['taxAmount'];
    $rate=(float)$values['taxRate'];
    if(!is_finite($base)||!is_finite($tax)||!is_finite($rate)||$base<0||$tax<0||$rate<0||$rate>100){
        throw new InvalidArgumentException('Snapshot PBJT historis tidak valid.');
    }
    if(!moneyMatches($base+$tax,$amount,1.0)){
        throw new InvalidArgumentException('Jumlah dasar dan PBJT historis harus sama dengan nominal transaksi (toleransi input historis maksimal Rp 1).');
    }
    // Arsip kertas lama boleh mempunyai selisih pembulatan <= Rp 1, tetapi
    // snapshot yang disimpan tetap dinormalisasi tepat ke gross agar jurnal,
    // laporan pajak, dan audit database tidak membawa selisih tersebut.
    $tax=round(min($amount,$tax),2);
    $base=round(max(0.0,$amount-$tax),2);
    return ['complete'=>true,'baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>round($rate,4)];
}


/** Resolve one immutable transaction tax snapshot for both live and historical data. */
function tamasyaInferTransactionTaxKind(array $tx): string {
    $categorySystemKey=tamasyaTransactionCategorySystemKey($tx);
    if($categorySystemKey==='room_rental')return 'room';
    if($categorySystemKey==='extra_service')return 'extra';
    $action=inferBookingChargeAction($tx);
    if(in_array($action,['room','extension','extra'],true))return $action;
    $kind=normalizeTransactionKind((string)($tx['transactionKind']??'manual'));
    // FIX44: canonical booking ledger kinds are not tax-rule kinds. Mirror
    // resolveBookingChargeTaxPolicy(): a booking receipt/charge that did not
    // resolve to extra/extension is room revenue. Without this, the kind fell
    // through to tamasyaNormalizeTaxKind('*') and the generic wildcard PBJT
    // rule silently won over source/kind-specific rules (e.g. an explicit
    // 0%/bebas pajak rule for jenis 'extra').
    if($kind==='booking_payment'||$kind==='booking_charge')return 'room';
    if(!tamasyaLegacyCatalogTextFallbackAllowed($tx))return $kind;
    $category=strtolower(trim((string)($tx['category']??'')));
    if($kind==='manual' && (str_contains($category,'kamar') || str_contains($category,'hotel')))return 'room';
    return $kind;
}

function tamasyaResolveTransactionTaxSnapshot(PDO $pdo, array $payload, float $amount, bool $historical): array {
    $amount=max(0.0,round($amount,2));
    $type=strtolower(trim((string)($payload['type']??'income')));
    if($type!=='income'){
        // Generic manual expenses are not PBJT sales.  Keep the gross amount as
        // the expense base and mark tax metadata explicitly not_applicable so
        // live and historical/backfill paths cannot inherit stale income tax state.
        return [
            'baseAmount'=>$amount,'taxAmount'=>0.0,'taxRate'=>0.0,
            'taxSnapshotStatus'=>'not_applicable','taxSource'=>'not_applicable','taxRuleId'=>null,
        ];
    }
    $source=trim((string)($payload['bookingSource']??'Direct'))?:'Direct';
    $date=validIsoDate((string)($payload['date']??''))?(string)$payload['date']:date('Y-m-d');
    $kind=tamasyaInferTransactionTaxKind($payload);

    if($historical){
        $requestedMode=strtolower(trim((string)($payload['historicalTaxMode']??$payload['taxSource']??'')));
        $explicitDocument=in_array($requestedMode,['document','historical_document','snapshot'],true);
        $explicitUnresolved=in_array($requestedMode,['unresolved','unknown'],true);
        $explicitRule=in_array($requestedMode,['rule_by_date','historical_rule','rule'],true);

        if($explicitUnresolved){
            return [
                'baseAmount'=>null,'taxAmount'=>null,'taxRate'=>null,
                'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null,
            ];
        }

        if($explicitDocument){
            $document=tamasyaNormalizeHistoricalTaxSnapshot($payload,$amount,true);
            return [
                'baseAmount'=>$document['baseAmount'],'taxAmount'=>$document['taxAmount'],'taxRate'=>$document['taxRate'],
                'taxSnapshotStatus'=>'confirmed','taxSource'=>'historical_document','taxRuleId'=>null,
            ];
        }

        // Backfill hotel bersifat rule-first. Jika klien lama/offline tidak
        // mengirim historicalTaxMode, tanggal+sumber+jenis tetap dihitung dari
        // menu Aturan Pajak. Snapshot dokumen hanya menjadi fallback kompatibel
        // ketika tidak ada rule dan angka dokumen memang lengkap.
        $rule=resolveConfiguredTaxRule($pdo,$source,$kind,$date);
        if(!empty($rule['matched'])){
            $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
            return $breakdown+[
                'taxSnapshotStatus'=>'confirmed','taxSource'=>'historical_rule','taxRuleId'=>$rule['ruleId'],
                'taxRuleName'=>$rule['ruleName'],'taxEffectiveDate'=>$date,
            ];
        }

        if(!$explicitRule){
            $document=tamasyaNormalizeHistoricalTaxSnapshot($payload,$amount,false);
            if($document['complete']){
                return [
                    'baseAmount'=>$document['baseAmount'],'taxAmount'=>$document['taxAmount'],'taxRate'=>$document['taxRate'],
                    'taxSnapshotStatus'=>'confirmed','taxSource'=>'historical_document','taxRuleId'=>null,
                ];
            }
            return [
                'baseAmount'=>null,'taxAmount'=>null,'taxRate'=>null,
                'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null,
            ];
        }

        // FIX29: a rejected historical rule lookup must explain exactly which
        // combination found no rule, so operators can complete the tax-rule
        // catalog instead of guessing why the save failed.
        $activeRulesStmt=$pdo->query("SELECT name, source_pattern, transaction_kind, rate, effective_from, effective_until FROM tax_rules WHERE is_active=1 ORDER BY priority DESC, id ASC");
        $activeRuleSummaries=[];
        foreach($activeRulesStmt ? $activeRulesStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $activeRule){
            $activeRuleSummaries[]=sprintf(
                '"%s" (sumber %s, jenis %s, %s%%, %s s/d %s)',
                (string)$activeRule['name'],
                ((string)($activeRule['source_pattern']??'')!==''?(string)$activeRule['source_pattern']:'*'),
                ((string)($activeRule['transaction_kind']??'')!==''?(string)$activeRule['transaction_kind']:'*'),
                rtrim(rtrim(number_format((float)$activeRule['rate'],2,'.',''),'0'),'.'),
                ((string)($activeRule['effective_from']??'')!==''?(string)$activeRule['effective_from']:'-'),
                ((string)($activeRule['effective_until']??'')!==''?(string)$activeRule['effective_until']:'-')
            );
        }
        throw new InvalidArgumentException(
            'Tidak ada Aturan Pajak aktif yang cocok untuk kombinasi: sumber "'.$source.'", jenis "'.$kind.'", tanggal '.$date.'.'
            .' Tambahkan aturan untuk kombinasi ini atau pilih PBJT belum diketahui.'
            .' Aturan aktif saat ini: '.(count($activeRuleSummaries)>0?implode('; ',$activeRuleSummaries):'(belum ada aturan pajak aktif).')
        );
    }

    $rule=resolveConfiguredTaxRule($pdo,$source,$kind,$date);
    if(empty($rule['matched'])){
        throw new RuntimeException(
            'Transaksi live ditolak karena tidak ada Aturan Pajak aktif untuk sumber '.$source.
            ', jenis '.$kind.', tanggal '.$date.'. Aturan 0%/tidak kena pajak juga harus dibuat eksplisit.'
        );
    }
    $breakdown=calculateInclusiveTaxBreakdown($amount,(float)($rule['rate']??0));
    return $breakdown+[
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,
        'taxRuleName'=>$rule['ruleName']??null,'taxEffectiveDate'=>$date,
    ];
}

function tamasyaTransactionIsHistorical(array $transaction): bool {
    return strtolower(trim((string)($transaction['recordOrigin'] ?? 'live_operation'))) === 'historical_import'
        || (int)($transaction['shiftExempt'] ?? 0) === 1;
}

/** Source line 2816: resolveBookingChargeTaxPolicy */
function resolveBookingChargeTaxPolicy($booking, $tx, $amount, $pdoOverride = null) {
    $taxDb = $pdoOverride ?: ($GLOBALS['pdo'] ?? null);
    $action = inferBookingChargeAction($tx);
    $source = trim((string)($tx['bookingSource'] ?? ''));
    if ($source === '') $source = trim((string)($booking['bookingSource'] ?? ''));
    if ($source === '') $source = 'Direct';
    $date = validIsoDate((string)($tx['date'] ?? '')) ? (string)$tx['date'] : date('Y-m-d');

    // Jenis dan sumber transaksi hanya menjadi kunci pencarian tax_rules.
    // Tidak ada cabang Direct/OTA atau tarif bawaan yang dapat mengunci kebijakan.
    if ($action === 'extra' || $action === 'extension') {
        $ruleKind = $action;
    } else {
        $txKind = normalizeTransactionKind((string)($tx['transactionKind'] ?? 'manual'));
        $categorySystemKey=tamasyaTransactionCategorySystemKey((array)$tx);
        $isRoomFinancial = $categorySystemKey==='room_rental'
            || in_array($txKind, ['booking_payment','down_payment','settlement'], true)
            || (($tx['sourceEntity'] ?? '') === 'booking');
        $ruleKind = $isRoomFinancial ? 'room' : $txKind;
    }
    if(!$taxDb instanceof PDO) throw new RuntimeException('Database aturan pajak booking tidak tersedia.');
    $rule = resolveConfiguredTaxRule($taxDb,$source,$ruleKind,$date);
    if(empty($rule['matched'])){
        throw new RuntimeException('Tidak ada Aturan Pajak aktif untuk sumber '.$source.', jenis '.$ruleKind.', tanggal '.$date.'. Rule 0%/tidak kena pajak juga wajib eksplisit.');
    }
    $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
    return $breakdown+[
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,
        'taxRuleName'=>$rule['ruleName']??null,'taxEffectiveDate'=>$date,
    ];
}

/** Source line 2845: resolveStandaloneTransactionTaxPolicy */
function resolveStandaloneTransactionTaxPolicy(PDO $pdo, array $tx, float $amount): array {
    $type = strtolower(trim((string)($tx['type'] ?? 'income')));
    if ($type !== 'income') return calculateInclusiveTaxBreakdown($amount,0)+[
        'taxSnapshotStatus'=>'not_applicable','taxSource'=>'not_applicable','taxRuleId'=>null,
    ];
    $source = trim((string)($tx['bookingSource'] ?? 'Direct')) ?: 'Direct';
    $date = validIsoDate((string)($tx['date'] ?? '')) ? (string)$tx['date'] : date('Y-m-d');
    $action = inferBookingChargeAction($tx);
    if ($action === 'extension' || $action === 'extra') {
        $kind = $action;
    } else {
        $kind = normalizeTransactionKind((string)($tx['transactionKind'] ?? 'manual'));
        $categorySystemKey=tamasyaTransactionCategorySystemKey((array)$tx);
        if($categorySystemKey==='room_rental')$kind='room';
        elseif($categorySystemKey==='extra_service')$kind='extra';
        elseif(tamasyaLegacyCatalogTextFallbackAllowed((array)$tx)){
            $category = strtolower(trim((string)($tx['category'] ?? '')));
            if ($kind === 'manual' && str_contains($category,'kamar')) $kind = 'room';
        }
    }
    $rule=resolveConfiguredTaxRule($pdo,$source,$kind,$date);
    if(empty($rule['matched'])) throw new RuntimeException('Tidak ada Aturan Pajak aktif untuk transaksi '.$source.' / '.$kind.' pada '.$date.'.');
    $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
    return $breakdown+[
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,
        'taxRuleName'=>$rule['ruleName']??null,'taxEffectiveDate'=>$date,
    ];
}

/** Source line 2868: resolveRoomSaleTaxBreakdown */
function resolveRoomSaleTaxBreakdown($bookingSource, $paymentMethod, $bankAccountId, $grossAmount, $effectiveDate = null) {
    global $pdo;
    $source = trim((string)$bookingSource) ?: 'Direct';
    $date = $effectiveDate && validIsoDate((string)$effectiveDate) ? (string)$effectiveDate : date('Y-m-d');
    if(!$pdo instanceof PDO) throw new RuntimeException('Database aturan pajak kamar tidak tersedia.');
    $rule=resolveConfiguredTaxRule($pdo,$source,'room',$date);
    if(empty($rule['matched'])) throw new RuntimeException('Tidak ada Aturan Pajak kamar aktif untuk sumber '.$source.' pada '.$date.'.');
    $breakdown=calculateInclusiveTaxBreakdown($grossAmount,(float)$rule['rate']);
    return $breakdown+[
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,
        'taxRuleName'=>$rule['ruleName']??null,'taxEffectiveDate'=>$date,
    ];
}

/** Source line 2876: signedTransactionTaxAmount */
function signedTransactionTaxAmount($tx) {
    $tax = max(0, (float)($tx['taxAmount'] ?? 0));
    return (($tx['type'] ?? 'income') === 'expense' ? -1 : 1) * $tax;
}

/**
 * V137 fresh baseline deliberately contains no automatic tax-repair/reconciliation
 * mutator. Historical evidence is entered through the audited backfill flow and
 * live tax snapshots are validated fail-closed when the transaction is created.
 */

/**
 * Receipt idempoten lintas-channel untuk workflow booking pusat.
 *
 * Jurnal diklaim dalam transaksi pendek sebelum mutasi bisnis. Dengan demikian
 * retry setelah respons jaringan hilang tidak mengeksekusi booking kedua, dan
 * operation ID yang sama tidak dapat digunakan dengan payload/actor berbeda.
 */
function tamasyaClaimCanonicalBookingReceipt(PDO $pdo, array $actor, string $operationId, string $channel, string $bookingId, array $payload, bool $joinTransaction=false, string $entityType='booking'): array {
    if ($pdo->inTransaction()!==$joinTransaction) throw new RuntimeException('Batas transaksi receipt booking tidak sesuai.');
    if(!in_array($entityType,['booking','growth_group'],true))throw new InvalidArgumentException('Jenis receipt booking tidak valid.');
    $operationId=trim($operationId);
    $staffId=trim((string)($actor['id']??''));
    $channel=strtolower(trim($channel));
    if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('Operation ID booking wajib dan maksimal 100 karakter.');
    if($staffId==='')throw new RuntimeException('Actor booking tidak memiliki staff ID server.');
    if(!in_array($channel,['web','telegram','provider','offline-replay'],true))$channel='provider';
    $deviceId=substr($channel.':'.currentDeviceId(),0,190);
    $normalized=tamasyaOperationCanonicalize($payload,false);
    $json=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($json===false)throw new RuntimeException('Payload booking tidak dapat dinormalisasi.');
    $payloadHash=hash('sha256',$json);
    try{
        if(!$joinTransaction)$pdo->beginTransaction();
        $insert=$pdo->prepare("INSERT IGNORE INTO sync_operations
            (operation_id,staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at,processed_at)
            VALUES (?,?,?,?,?,'create_booking',?,'processing',NULL,CURRENT_TIMESTAMP,NULL)");
        $insert->execute([$operationId,$staffId,$deviceId,$entityType,$bookingId,$payloadHash]);
        $created=$insert->rowCount()===1;
        $select=$pdo->prepare("SELECT staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at FROM sync_operations WHERE operation_id=? LIMIT 1 FOR UPDATE");
        $select->execute([$operationId]);
        $row=$select->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Receipt booking tidak dapat dibaca setelah klaim.');
        $matches=hash_equals((string)$row['staff_id'],$staffId)
            && hash_equals((string)($row['device_id']??''),$deviceId)
            && hash_equals((string)$row['entity_type'],$entityType)
            && hash_equals((string)$row['entity_id'],$bookingId)
            && hash_equals((string)$row['action'],'create_booking')
            && hash_equals((string)($row['payload_hash']??''),$payloadHash);
        if(!$matches){
            if(!$joinTransaction)tamasyaFinancialCommit($pdo);
            throw new RuntimeException('Operation ID booking sudah digunakan oleh actor, perangkat, atau payload berbeda.');
        }
        if($created){if(!$joinTransaction)tamasyaFinancialCommit($pdo);return ['state'=>'claimed','payloadHash'=>$payloadHash];}
        $status=strtolower((string)($row['status']??'processing'));
        if($status==='processed'){
            $result=json_decode((string)($row['result_json']??''),true);
            if(!$joinTransaction)tamasyaFinancialCommit($pdo);
            return ['state'=>'duplicate','payloadHash'=>$payloadHash,'result'=>is_array($result)?$result:[]];
        }
        if($status==='failed'){
            $retry=$pdo->prepare("UPDATE sync_operations SET status='processing',result_json=NULL,processed_at=NULL,created_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='failed'");
            $retry->execute([$operationId]);
            if($retry->rowCount()===1){if(!$joinTransaction)tamasyaFinancialCommit($pdo);return ['state'=>'claimed','payloadHash'=>$payloadHash,'retry'=>true];}
        }
        $createdAt=strtotime((string)($row['created_at']??''))?:time();
        if($status==='processing' && $createdAt<time()-600){
            $pdo->prepare("UPDATE sync_operations SET status='attention_required',result_json=?,processed_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'")
                ->execute([json_encode(['error'=>'Receipt booking lama belum mempunyai hasil terminal; rekonsiliasi manual wajib dilakukan.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$operationId]);
            if(!$joinTransaction)tamasyaFinancialCommit($pdo);
            throw new RuntimeException('Operation ID booking berada pada status tidak pasti dan wajib direkonsiliasi, bukan dieksekusi ulang.');
        }
        if(!$joinTransaction)tamasyaFinancialCommit($pdo);
        throw new RuntimeException('Operation ID booking yang sama masih sedang diproses.');
    }catch(Throwable $e){
        if(!$joinTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function tamasyaCompleteCanonicalBookingReceipt(PDO $pdo, string $operationId, array $result): void {
    if(!$pdo->inTransaction())throw new RuntimeException('Receipt booking hanya boleh diselesaikan bersama transaksi bisnis.');
    $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Hasil receipt booking tidak dapat diserialisasi.');
    $stmt=$pdo->prepare("UPDATE sync_operations SET status='processed',result_json=?,processed_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'");
    $stmt->execute([$json,$operationId]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Receipt booking gagal diselesaikan secara atomik.');
}

function tamasyaFailCanonicalBookingReceipt(PDO $pdo, string $operationId, Throwable $error): void {
    if($operationId==='')return;
    try{
        $owns=!$pdo->inTransaction();
        if($owns)$pdo->beginTransaction();
        $payload=json_encode(['error'=>clientExceptionMessage('Workflow booking gagal',$error)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE sync_operations SET status='failed',result_json=?,processed_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'")
            ->execute([$payload?:null,$operationId]);
        if($owns)tamasyaFinancialCommit($pdo);
    }catch(Throwable $receiptError){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[Canonical Booking Receipt] '.clientExceptionMessage('Gagal menandai receipt',$receiptError));
    }
}

/**
 * Workflow pusat pembuatan booking untuk Web, Telegram, provider chat, dan replay.
 *
 * Semua channel melewati lock kamar + overlap, lifecycle reserved/active,
 * ledger server, audit before/after, receipt operation ID, dan commit yang sama.
 * Panggilan Telegram eksternal baru dilakukan setelah commit.
 */
function createCanonicalBookingWorkflow(PDO $pdo, array $actor, array $payload, string $channel, string $operationId, bool $joinGroupTransaction=false): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'booking/check-in live');
    if($pdo->inTransaction()!==$joinGroupTransaction)throw new RuntimeException('Batas transaksi workflow booking pusat tidak sesuai.');
    $channel=strtolower(trim($channel));
    if(!in_array($channel,['web','telegram','provider','offline-replay'],true))$channel='provider';
    $source=$channel==='offline-replay'?'offline-replay':$channel;
    $operationId=trim($operationId);
    $bookingId='b_op_'.substr(hash('sha256',$operationId),0,36);
    $receipt=tamasyaClaimCanonicalBookingReceipt($pdo,$actor,$operationId,$channel,$bookingId,$payload,$joinGroupTransaction);
    if(($receipt['state']??'')==='duplicate')return ['duplicate'=>true]+(array)($receipt['result']??[]);

    $guestName=trim((string)($payload['guestName']??''));
    $guestEmail=trim((string)($payload['guestEmail']??''));
    $guestPhone=trim((string)($payload['guestPhone']??''));
    $roomNumber=trim((string)($payload['roomNumber']??''));
    $roomType=trim((string)($payload['roomType']??''));
    $checkIn=trim((string)($payload['checkIn']??''));
    $checkOut=trim((string)($payload['checkOut']??''));
    $isOpenEnded=!empty($payload['isOpenEnded'])?1:0;
    $stayMode=strtolower(trim((string)($payload['stayMode']??'')));
    if($isOpenEnded){
        if($stayMode==='short_time')$stayMode='short_time';
        else $stayMode='open_ended';
    }elseif($stayMode===''&&$checkOut===$checkIn)$stayMode='short_time';
    elseif($stayMode==='')$stayMode='overnight';
    $scheduledCheckInAt=normalizeHotelDateTime($payload['scheduledCheckInAt']??null);
    $scheduledCheckOutAt=normalizeHotelDateTime($payload['scheduledCheckOutAt']??null);
    $totalAmount=round((float)($payload['totalAmount']??0),2);
$discountAmount=0.0;
$promoCodeId=null;
    $requestedPaymentStatus=strtolower(trim((string)($payload['paymentStatus']??'unpaid')));
    $paymentMethod=strtolower(trim((string)($payload['paymentMethod']??'')));
    $bankAccountId=trim((string)($payload['bankAccountId']??''));
    $bookingSource=trim((string)($payload['bookingSource']??'Direct'))?:'Direct';
    $paymentDate=trim((string)($payload['paymentDate']??$checkIn));
    $downPaymentAmount=round((float)($payload['downPaymentAmount']??0),2);
    $downPaymentMethod=strtolower(trim((string)($payload['downPaymentMethod']??'')));
    $downPaymentBankAccountId=trim((string)($payload['downPaymentBankAccountId']??''));
    $downPaymentDate=trim((string)($payload['downPaymentDate']??$paymentDate));
    $isSplitPayment=!empty($payload['isSplitPayment'])?1:0;
    $splitCashAmount=round((float)($payload['splitCashAmount']??0),2);
    $splitTransferAmount=round((float)($payload['splitTransferAmount']??0),2);
    $splitTransferBankAccountId=trim((string)($payload['splitTransferBankAccountId']??''));
    $securityDepositRequired=!empty($payload['securityDepositRequired'])?1:0;
    $securityDepositRequiredAmount=max(0.0,round((float)($payload['securityDepositRequiredAmount']??0),2));
    $securityDepositReceivedAmount=max(0.0,round((float)($payload['securityDepositReceivedAmount']??0),2));
    $securityDepositPaymentMethod=strtolower(trim((string)($payload['securityDepositPaymentMethod']??'cash')));
    $securityDepositBankAccountId=trim((string)($payload['securityDepositBankAccountId']??''));
    $securityDepositDate=trim((string)($payload['securityDepositDate']??$paymentDate));
    if($securityDepositReceivedAmount>0&&$securityDepositRequiredAmount<=0)$securityDepositRequiredAmount=$securityDepositReceivedAmount;
    if($securityDepositRequiredAmount>0)$securityDepositRequired=1;
    $publicRequestId=trim((string)($payload['publicRequestId']??''));
    $ktpPhoto=normalizeBookingIdentityReferenceForStorage($pdo,$payload['ktpPhoto']??null);
$promoCodeInput=trim((string)($payload['promoCode'] ?? $payload['promotionCode'] ?? $payload['promotion_code'] ?? ''));
    $broadcast=!array_key_exists('broadcast',$payload)||!empty($payload['broadcast']);
    // R6 reservation/check-in separation: booking creation must never infer physical
    // occupancy from the calendar date. A booking is reserved by default. Only an
    // explicit operator intent may create-and-check-in atomically; all other check-in
    // transitions use the canonical reserved -> active status workflow.
    $lifecycleIntent=strtolower(trim((string)($payload['lifecycleIntent']??'reserve')));
    if($lifecycleIntent==='')$lifecycleIntent='reserve';
    if(!in_array($lifecycleIntent,['reserve','check_in_now'],true)){
        $e=new InvalidArgumentException('Intent lifecycle booking tidak valid. Gunakan reserve atau check_in_now.');
        tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }
    if($channel==='offline-replay'&&$lifecycleIntent==='check_in_now'){
        $e=new RuntimeException('Check-in aktual tidak boleh direplay sebagai pembuatan booking offline. Buat reservasi lalu lakukan check-in online.');
        tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }
    if($publicRequestId!==''&&$lifecycleIntent!=='reserve'){
        $e=new RuntimeException('Permintaan reservasi website wajib dikonversi menjadi reserved terlebih dahulu. Lakukan check-in melalui workflow reserved → active setelah tamu benar-benar datang.');
        tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }

    if($guestName===''||$roomNumber===''||!validIsoDate($checkIn)||!validIsoDate($checkOut)){
        $e=new InvalidArgumentException('Nama tamu, kamar, dan tanggal booking wajib valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }
    if(!in_array($stayMode,['overnight','short_time','open_ended'],true)){$e=new InvalidArgumentException('Mode menginap tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if($stayMode==='short_time'){
        if($checkOut!==$checkIn||($scheduledCheckInAt&&substr($scheduledCheckInAt,0,10)!==$checkIn)){
            $e=new InvalidArgumentException('Short time wajib memakai tanggal yang sama; jam masuk bila diisi harus berada pada tanggal tersebut.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
        }
        if(!$isOpenEnded && (!$scheduledCheckInAt||!$scheduledCheckOutAt||substr($scheduledCheckOutAt,0,10)!==$checkOut||strtotime($scheduledCheckOutAt)<=strtotime($scheduledCheckInAt))){
            $e=new InvalidArgumentException('Short time dengan jam keluar pasti wajib memiliki jam masuk dan jam selesai yang valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
        }
    }elseif(!$isOpenEnded && $checkOut<=$checkIn){$e=new InvalidArgumentException('Booking menginap wajib memiliki checkout setelah check-in.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if($checkIn<date('Y-m-d')){$e=new InvalidArgumentException('Tanggal check-in tidak boleh berada di masa lalu.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if(!$isOpenEnded && $totalAmount<=0){$e=new InvalidArgumentException('Total booking harus lebih dari nol.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if($securityDepositRequiredAmount>1000000000000||$securityDepositReceivedAmount>1000000000000||$securityDepositReceivedAmount>$securityDepositRequiredAmount+0.01){
        $e=new InvalidArgumentException('Nominal deposito jaminan tidak valid atau penerimaan melebihi nominal wajib.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }
    if($securityDepositReceivedAmount>0&&!validIsoDate($securityDepositDate)){$e=new InvalidArgumentException('Tanggal deposito jaminan tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if($totalAmount<0||$totalAmount>1000000000000||$downPaymentAmount<0||(!$isOpenEnded&&$downPaymentAmount>$totalAmount+0.01)){
        $e=new InvalidArgumentException('Total atau panjar booking tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
    }
    if(!in_array($requestedPaymentStatus,['paid','unpaid','partial'],true)){$e=new InvalidArgumentException('Status pembayaran yang diminta tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if(!validIsoDate($paymentDate)||($downPaymentAmount>0&&!validIsoDate($downPaymentDate))){$e=new InvalidArgumentException('Tanggal pembayaran booking tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}
    if($publicRequestId!==''&&!preg_match('/^PWR-[A-Z0-9-]{8,80}$/',$publicRequestId)){$e=new InvalidArgumentException('Nomor permintaan reservasi website tidak valid.');tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;}

    $lifecycleStatus=$lifecycleIntent==='check_in_now'?'active':'reserved';
    $financialMovement=$downPaymentAmount>0||$requestedPaymentStatus==='paid'||$securityDepositReceivedAmount>0;
    $telegramMessage='';
    try{
        if(!$joinGroupTransaction)$pdo->beginTransaction();
        $settings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
        $blacklistMatch=null;
        if($guestPhone!==''||$guestEmail!==''){
            $blacklistStmt=$pdo->prepare("SELECT id,name,phone,email FROM guest_profiles WHERE blacklisted=1 AND ((?<>'' AND phone=?) OR (?<>'' AND LOWER(email)=LOWER(?))) ORDER BY updated_at DESC LIMIT 1 FOR UPDATE");
            $blacklistStmt->execute([$guestPhone,$guestPhone,$guestEmail,$guestEmail]);$blacklistMatch=$blacklistStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($blacklistMatch&&!hasCapability($actor,'approve_sensitive_actions',['admin','manager']))throw new RuntimeException('Tamu terdaftar pada blacklist. Booking harus ditinjau dan dilakukan oleh Admin/Manager.');
            if($blacklistMatch){
                $alertId='alert_blacklist_'.substr(hash('sha256',$bookingId.'|'.(string)$blacklistMatch['id']),0,48);
                $pdo->prepare("INSERT INTO system_alerts(id,severity,source,title,message,created_at) VALUES (?,'warning','guest-blacklist','Override booking tamu blacklist',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                    ->execute([$alertId,'Booking '.$bookingId.' untuk tamu blacklist '.($blacklistMatch['name']??$guestName).' dilakukan oleh '.currentStaffLabel($actor).'.']);
            }
        }
        if($financialMovement && ($actor['role']??'')==='receptionist' && (int)($settings['require_open_shift_for_sale']??1)===1){
            if(!getOpenShiftForStaff($pdo,(string)$actor['id'],false))throw new RuntimeException('Buka shift kas terlebih dahulu atau pastikan akun menjadi pendamping shift.');
        }
        $roomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
        $roomStmt->execute([$roomNumber]);
        $room=$roomStmt->fetch(PDO::FETCH_ASSOC);
        if(!$room)throw new RuntimeException('Kamar tidak ditemukan di database server.');
        $checkinTime=substr((string)($settings['checkin_time']??'14:00:00'),0,8);
        $checkoutTime=substr((string)($settings['checkout_time']??'12:00:00'),0,8);
        $stayWindow=resolveHotelBookingStayWindow([
            'checkIn'=>$checkIn,'checkOut'=>$checkOut,'isOpenEnded'=>$isOpenEnded,'stayMode'=>$stayMode,
            'scheduledCheckInAt'=>$scheduledCheckInAt,'scheduledCheckOutAt'=>$scheduledCheckOutAt
        ],$checkoutTime,$checkinTime);
        $requestedStartAt=(string)$stayWindow['startAt'];
        $requestedEndAt=(string)$stayWindow['endAt'];
        $scheduledCheckInAt=$stayWindow['scheduledCheckInAt'];
        $scheduledCheckOutAt=$stayWindow['scheduledCheckOutAt'];
        tamasyaR3AssertStayWindowNoOverlap($pdo,'',$roomNumber,$requestedStartAt,$requestedEndAt,true);
        // R6-R2: rooms.status is only a physical projection/cache. Reservation
        // inventory must be decided from domain blockers plus the requested time
        // window, otherwise a stale `maintenance` cache can hide a healthy room
        // and an occupied room can never be reserved for a non-overlapping future slot.
        $checkinEligibility=null;
        if($lifecycleStatus==='active'){
            $checkInCandidate=array_merge($payload,[
                'id'=>$bookingId,'status'=>'reserved','roomNumber'=>$roomNumber,'checkIn'=>$checkIn,'checkOut'=>$checkOut,
                'stayMode'=>$stayMode,'isOpenEnded'=>$isOpenEnded,'scheduledCheckInAt'=>$scheduledCheckInAt,'scheduledCheckOutAt'=>$scheduledCheckOutAt,
                'securityDepositRequired'=>$securityDepositRequired,'securityDepositRequiredAmount'=>$securityDepositRequiredAmount,
                'securityDepositReceived'=>$securityDepositReceivedAmount
            ]);
            $checkinEligibility=tamasyaR3EvaluateCheckInEligibility($pdo,$actor,$checkInCandidate,['allowNew'=>true,'settings'=>$settings,'room'=>$room]);
        }else{
            $reservationBlockers=tamasyaReservationInventoryBlockers(getRoomOperationalBlockers($pdo,$roomNumber,true));
            if($reservationBlockers){
                throw new RuntimeException('Kamar belum dapat menerima reservasi baru. '.roomOperationalBlockerMessage($roomNumber,$reservationBlockers));
            }
        }
        $roomType=(string)($room['type']??$roomType);
        if($roomType==='')throw new RuntimeException('Tipe kamar server tidak tersedia.');

        // Tarif kontrak B2B. Bila staf memilih paket kontrak, server yang
        // berwenang atas nominal: total dihitung dari harga paket per tanggal,
        // bukan dari angka yang dikirim browser. Paket yang tidak berlaku
        // membuat reservasi ditolak, bukan kembali diam-diam ke tarif rak.
        $wholesaleQuote=null;
        $wholesalePlanId=trim((string)($payload['wholesalePlanId']??''));
        if($wholesalePlanId!==''){
            $wholesaleQuote=tamasyaWholesaleContractRateQuote($pdo,(string)($GLOBALS['tamasya_property_id']??''),[
                'planId'=>$wholesalePlanId,
                'roomType'=>$roomType,
                'companyName'=>trim((string)($payload['wholesaleCompanyName']??($payload['companyName']??''))),
                'checkIn'=>$checkIn,
                'checkOut'=>$checkOut,
            ]);
            if(!$wholesaleQuote['applicable']){
                $e=new InvalidArgumentException('Tarif kontrak tidak dapat dipakai: '.$wholesaleQuote['reason']);
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
            $totalAmount=round((float)$wholesaleQuote['total'],2);
            if($totalAmount<=0||$totalAmount>1000000000000){
                $e=new InvalidArgumentException('Total tarif kontrak tidak valid.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
            if($downPaymentAmount>$totalAmount+0.01){
                $e=new InvalidArgumentException('Panjar booking melebihi total tarif kontrak.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
        }

        $publicRow=null;
        if($publicRequestId!==''){
            $publicStmt=$pdo->prepare("SELECT public_request_id,room_type_id,status,linked_booking_id,promo_code FROM public_reservation_requests WHERE public_request_id=? LIMIT 1 FOR UPDATE");
            $publicStmt->execute([$publicRequestId]);$publicRow=$publicStmt->fetch(PDO::FETCH_ASSOC);
            if(!$publicRow)throw new RuntimeException('Permintaan reservasi website tidak ditemukan.');
            if((string)($publicRow['status']??'')==='rejected')throw new RuntimeException('Permintaan reservasi website sudah ditolak.');
            if((string)($publicRow['status']??'')==='converted'||!empty($publicRow['linked_booking_id']))throw new RuntimeException('Permintaan reservasi website sudah dikonversi.');
            $requestedTypeId=(string)($publicRow['room_type_id']??'');
            $selectedSlug=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$roomType),'-'));
            $matches=false;
            if(str_starts_with($requestedTypeId,'derived_'))$matches=hash_equals(substr($requestedTypeId,8),$selectedSlug);
            else{
                $typeStmt=$pdo->prepare("SELECT name FROM public_room_types WHERE id=? LIMIT 1");$typeStmt->execute([$requestedTypeId]);
                $name=trim((string)$typeStmt->fetchColumn());$matches=$name!==''&&strcasecmp($name,$roomType)===0;
            }
            if(!$matches)throw new RuntimeException('Kamar tidak sesuai tipe pada permintaan website.');
            $bookingSource='Website';
            if($promoCodeInput==='' && !empty($publicRow['promo_code'])){
                $promoCodeInput=trim((string)$publicRow['promo_code']);
            }
        }
         $accessStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
        $accessStmt->execute([$roomNumber]);$access=$accessStmt->fetch(PDO::FETCH_ASSOC)?:['access_mode'=>'physical','physical_key_ref'=>'KEY-'.$roomNumber];
        $accessMode=in_array((string)($access['access_mode']??''),['physical','smart','hybrid'],true)?(string)$access['access_mode']:'physical';
        $checkoutDueAt=$isOpenEnded?null:(string)$stayWindow['checkoutDueAt'];

$promoAppliedAmount = $totalAmount;
    $manualDiscountProvided = !empty($payload['discountAmount']) || !empty($payload['discount']) || !empty($payload['manualDiscount'])
        || (isset($payload['discountValue']) && (float)$payload['discountValue'] > 0) || !empty($payload['discountType']);
    if ($promoCodeInput === '' && !$manualDiscountProvided) {
        $autoPromo = tamasyaPromotionCodeAutoApply($pdo, 'booking', $totalAmount, validIsoDate($checkIn) ? $checkIn : date('Y-m-d'));
        if (is_array($autoPromo) && !empty($autoPromo)) {
            $promoCodeInput = trim((string)($autoPromo['code'] ?? $autoPromo['promotionCode']['code'] ?? ''));
        }
    }
    if($promoCodeInput!==''){
            if(!tamasyaPromotionCodesEnabled() || !tamasyaPromotionCodesSchemaReady($pdo)){
                $e=new RuntimeException('Fitur kode promo dinonaktifkan atau schema belum siap.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
            $promoResult=tamasyaPromotionCodeValidate($pdo,$promoCodeInput,'booking',$totalAmount);
            $promoDiscountAmount=(float)$promoResult['discountAmount'];
            if($promoDiscountAmount<=0 || $promoDiscountAmount>=$totalAmount){
                $e=new RuntimeException('Diskon kode promo tidak valid untuk total booking.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
            $promoCodeId=(string)$promoResult['promotionCode']['id'];
            $discountAmount=$promoDiscountAmount;
            $totalAmount=round($totalAmount-$promoDiscountAmount,2);
            if($totalAmount<=0){
                $e=new InvalidArgumentException('Total booking setelah diskon promo harus lebih dari nol.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
            if($downPaymentAmount>$totalAmount+0.01){
                $e=new InvalidArgumentException('Panjar booking melebihi total tarif setelah diskon promo.');
                tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);throw $e;
            }
        }

        if($downPaymentAmount>0){
            if(!in_array($downPaymentMethod,['cash','transfer','qris'],true))throw new InvalidArgumentException('Metode panjar tidak valid.');
            if(in_array($downPaymentMethod,['transfer','qris'],true)&&$downPaymentBankAccountId==='')throw new InvalidArgumentException('Akun bank/QRIS panjar wajib dipilih.');
        }
        $remaining=max(0.0,round($totalAmount-$downPaymentAmount,2));
        if($requestedPaymentStatus==='paid'&&$remaining>0){
            if($isSplitPayment){
                if($splitCashAmount<=0||$splitTransferAmount<=0||!moneyMatches($splitCashAmount+$splitTransferAmount,$remaining)||$splitTransferBankAccountId==='')throw new InvalidArgumentException('Pembayaran split harus sama dengan sisa tagihan.');
                $paymentMethod='split';$bankAccountId='';
            }else{
                if(!in_array($paymentMethod,['cash','transfer','qris','ota'],true))throw new InvalidArgumentException('Metode pelunasan tidak valid.');
                if(in_array($paymentMethod,['transfer','qris'],true)&&$bankAccountId==='')throw new InvalidArgumentException('Akun bank/QRIS pelunasan wajib dipilih.');
                if($paymentMethod==='ota'){
                    if(!tamasyaIsExplicitOtaSourceLabel($bookingSource))throw new InvalidArgumentException('Piutang OTA hanya boleh dipakai untuk sumber booking OTA yang eksplisit.');
                    $bankAccountId='ota_receivable';
                }
            }
        }
        $validateBank=function(string $accountId,string $method)use($pdo):void{
            if($method==='split'){
                tamasyaInferPaymentMethodFromAccount($pdo,$accountId,true,['transfer','qris']);
                return;
            }
            tamasyaResolvePaymentAccount($pdo,$method,$accountId,[
                'allowedMethods'=>['cash','transfer','qris','ota'],'lock'=>true,'context'=>'Pembayaran reservasi'
            ]);
        };
        if($downPaymentAmount>0)$validateBank($downPaymentBankAccountId,$downPaymentMethod);
        if($requestedPaymentStatus==='paid'&&$remaining>0){
            if($isSplitPayment)$validateBank($splitTransferBankAccountId,'split');
            else $validateBank($bankAccountId,$paymentMethod);
        }
        if($securityDepositReceivedAmount>0)tamasyaSecurityDepositPaymentAccount($pdo,$securityDepositPaymentMethod,$securityDepositBankAccountId);

        $roomTax=resolveRoomSaleTaxBreakdown($bookingSource,$paymentMethod?:null,$bankAccountId?:null,$totalAmount,$checkIn);
        $vatRate=(float)$roomTax['taxRate'];$vatAmount=(float)$roomTax['taxAmount'];
        // Browser FINAL6 mengirim preview vatRate/vatAmount. Server tetap authority,
        // namun preview yang berbeda berarti rules browser stale/terpotong oleh role.
        // Fail-closed agar akun pendamping tidak membuat gross booking tanpa PBJT.
        if($channel==='web' && array_key_exists('vatRate',$payload) && is_numeric($payload['vatRate'])){
            $clientVatRate=(float)$payload['vatRate'];
            if(abs($clientVatRate-$vatRate)>0.0001){
                throw new RuntimeException('Aturan PBJT pada browser tidak sinkron dengan server. Muat ulang data sebelum membuat reservasi/check-in; transaksi tidak disimpan.');
            }
            if(array_key_exists('vatAmount',$payload) && is_numeric($payload['vatAmount'])){
                $clientVatAmount=round((float)$payload['vatAmount'],2);
                if(abs($clientVatAmount-$vatAmount)>1.0){
                    throw new RuntimeException('Nominal PBJT pada browser tidak sinkron dengan server. Muat ulang data sebelum membuat reservasi/check-in; transaksi tidak disimpan.');
                }
            }
        }
        $createdAt=date('Y-m-d H:i:s');$actualCheckInAt=$lifecycleStatus==='active'?$createdAt:null;
        $initialPaymentStatus='unpaid';
        $stmt=$pdo->prepare("INSERT INTO bookings
            (id,guestName,guestEmail,guestPhone,roomNumber,roomType,checkIn,checkOut,totalAmount,discountAmount,status,paymentStatus,paymentMethod,bankAccountId,
             vatRate,vatAmount,ktpPhoto,bookingSource,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId,
             downPaymentAmount,downPaymentMethod,downPaymentBankAccountId,downPaymentDate,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt,actualCheckInAt,checkoutDueAt,
             lateCheckoutStatus,keyControlStatus,accessMode,createdAt,version,updatedBy,updatedSource)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'none','not_issued',?,?,1,?,?)");
        $stmt->execute([$bookingId,$guestName,$guestEmail,$guestPhone,$roomNumber,$roomType,$checkIn,$checkOut,$totalAmount,$discountAmount,$lifecycleStatus,$initialPaymentStatus,$paymentMethod?:null,$bankAccountId?:null,
            $vatRate,$vatAmount,$ktpPhoto,$bookingSource,$isSplitPayment,$isSplitPayment?$splitCashAmount:null,$isSplitPayment?$splitTransferAmount:null,$isSplitPayment?$splitTransferBankAccountId:null,
            $downPaymentAmount,$downPaymentAmount>0?$downPaymentMethod:null,$downPaymentAmount>0?($downPaymentBankAccountId?:null):null,$downPaymentAmount>0?$downPaymentDate:null,
            $isOpenEnded,$stayMode,$scheduledCheckInAt,$scheduledCheckOutAt,$actualCheckInAt,$checkoutDueAt,$accessMode,$createdAt,$actor['id']??null,$source]);
        if($lifecycleStatus==='active')finalizeActiveBookingOperationalState($pdo,$bookingId,$roomNumber,$actor,$source);
        if($promoCodeId!==null && $promoCodeId!==''){
            $promoColumnExists=false;
            try{
                $colStmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='promotion_code_id'");
                $colStmt->execute();
                $promoColumnExists=((int)$colStmt->fetchColumn()>0);
                } catch (Throwable $e) { $promoColumnExists=false; }
                if ($promoColumnExists) {
                    $pdo->prepare('UPDATE bookings SET promotion_code_id=? WHERE id=?')->execute([$promoCodeId,$bookingId]);
                }
            tamasyaPromotionCodeMarkUsed($pdo,$promoCodeId,$operationId.':booking',$actor,$promoAppliedAmount,'booking',['discountAmount'=>$promoDiscountAmount]);
        }
        if($wholesaleQuote!==null){
            $wholesaleQuote['propertyId']=(string)($GLOBALS['tamasya_property_id']??'');
            tamasyaWholesaleContractRateLock($pdo,$wholesaleQuote,$bookingId,(string)($actor['id']??''));
        }

        // FIX28R1: for create+check-in, reuse the shift row already locked by the
        // physical check-in gate. Do not perform a second non-locking lookup that could
        // conceptually detach receipt posting from the eligibility decision.
        $lockedCheckInShiftSessionId=trim((string)($checkinEligibility['shiftSessionId']??''));
        $txIds=[];$category=getRoomRentalCategoryName($pdo);
        $insertTx=function(array $tx)use($pdo,$actor,$bookingId,$bookingSource,$lockedCheckInShiftSessionId,$source,&$txIds):void{
            $txRequiresShift=tamasyaManualTransactionRequiresOpenShift($tx);
            $txShiftSessionId=$lockedCheckInShiftSessionId!==''?$lockedCheckInShiftSessionId:resolveOpenShiftSessionId($pdo,$actor,$txRequiresShift);
            $ledgerBeforeReceipt=bookingLedgerTotals($pdo,$bookingId);
            // Guarded live booking income needs the tax snapshot contract; see
            // tamasyaAssertLiveTaxSnapshotIntegrity(). Omitting it writes a poison row
            // that blocks every later live posting in the hotel.
            $receiptTaxRule=resolveConfiguredTaxRule($pdo,$bookingSource,(string)($tx['transactionKind']??'booking_charge'),(string)($tx['date']??date('Y-m-d')));
            $receiptTaxRuleId=array_key_exists('taxRuleId',$tx)
                ? (trim((string)$tx['taxRuleId'])?:null)
                : (($receiptTaxRule['matched']??false)?(string)$receiptTaxRule['ruleId']:null);
            $receiptTaxRuleName=array_key_exists('taxRuleName',$tx)
                ? (trim((string)$tx['taxRuleName'])?:null)
                : (($receiptTaxRule['matched']??false)?(string)$receiptTaxRule['ruleName']:null);
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$tx['id'],'type'=>'income','category'=>$tx['category'],'categorySystemKey'=>'room_rental','subcategory'=>$tx['subcategory'],
                'roomNumber'=>$tx['roomNumber'],'amount'=>$tx['amount'],'date'=>$tx['date'],'description'=>$tx['description'],
                'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>$tx['bankAccountId']?:null,'bookingId'=>$bookingId,'bookingSource'=>$bookingSource,
                'baseAmount'=>$tx['baseAmount'],'taxAmount'=>$tx['taxAmount'],'taxRate'=>$tx['taxRate'],'transactionKind'=>$tx['transactionKind'],
                'taxRuleId'=>$receiptTaxRuleId,
                'taxRuleName'=>$receiptTaxRuleName,
                'taxSource'=>'live_rule','taxSnapshotStatus'=>'confirmed',
                'sourceEntity'=>'booking','sourceEntityId'=>$bookingId,'isSystemGenerated'=>1,'operationId'=>$tx['operationId'],
                'shiftSessionId'=>$txShiftSessionId,'updatedBy'=>$actor['id']??null,'updatedSource'=>$source,'version'=>1,
                'isSplitPayment'=>!empty($tx['isSplitPayment'])?1:0,
                'splitCashAmount'=>$tx['splitCashAmount']??0,
                'splitTransferAmount'=>$tx['splitTransferAmount']??0,
                'splitTransferBankAccountId'=>$tx['splitTransferBankAccountId']??null,
            ],$actor,'booking',['lockCatalog'=>true,'source'=>$source]);
            $bookingReceiptStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$bookingReceiptStmt->execute([$bookingId]);$bookingReceipt=$bookingReceiptStmt->fetch(PDO::FETCH_ASSOC);
            if(!$bookingReceipt)throw new RuntimeException('Booking tidak ditemukan saat alokasi receipt.');
            tamasyaAttachBookingReceiptRevenueAllocations($pdo,$bookingReceipt,(string)$tx['id'],(float)$tx['amount'],(float)$ledgerBeforeReceipt['net'],(string)$tx['operationId'],$actor,$source,(string)$tx['date']);
            $txIds[]=$tx['id'];
        };
        $dpTax=['baseAmount'=>0.0,'taxAmount'=>0.0,'taxRate'=>$vatRate];
        if($downPaymentAmount>0){
            $dpTax=resolveRoomSaleTaxBreakdown($bookingSource,$downPaymentMethod,$downPaymentBankAccountId?:null,$downPaymentAmount,$downPaymentDate);
            $label=$downPaymentMethod==='qris'?'QRIS':($downPaymentMethod==='transfer'?'Transfer':'Tunai');
            $insertTx(['id'=>'tx_dp_'.substr(hash('sha256',$operationId),0,30),'operationId'=>$operationId.':down_payment','category'=>$category,'subcategory'=>$roomType,'roomNumber'=>$roomNumber,'amount'=>$downPaymentAmount,'date'=>$downPaymentDate,'description'=>"Penerimaan Panjar {$category} {$roomNumber} - {$guestName} ({$label}) [{$bookingSource}]",'bankAccountId'=>$downPaymentBankAccountId,'baseAmount'=>$dpTax['baseAmount'],'taxAmount'=>$dpTax['taxAmount'],'taxRate'=>$dpTax['taxRate'],'taxRuleId'=>$dpTax['taxRuleId']??null,'taxRuleName'=>$dpTax['taxRuleName']??null,'transactionKind'=>'down_payment']);
        }
        $remaining=max(0.0,round($totalAmount-$downPaymentAmount,2));
        $remainingTax=max(0.0,round($vatAmount-(float)$dpTax['taxAmount'],2));
        $remainingBase=max(0.0,round($remaining-$remainingTax,2));
        if($requestedPaymentStatus==='paid'&&$remaining>0){
            $kind=$downPaymentAmount>0?'settlement':'booking_payment';$word=$downPaymentAmount>0?'Pelunasan':'Pemasukan';
            if($isSplitPayment){
                // Satu settlement split = satu transaksi canonical.  Kaki Tunai dan
                // Transfer/QRIS disimpan sebagai snapshot pada row yang sama supaya
                // create web, backfill/offline, jurnal, shift, refund dan sync tidak
                // berubah bentuk hanya karena jalur inputnya berbeda.
                $insertTx([
                    'id'=>'tx_pay_'.substr(hash('sha256',$operationId),0,29),
                    'operationId'=>$operationId.':payment',
                    'category'=>$category,'subcategory'=>$roomType,'roomNumber'=>$roomNumber,
                    'amount'=>$remaining,'date'=>$paymentDate,
                    'description'=>"{$word} {$category} {$roomNumber} - {$guestName} (Split Tunai + Transfer/QRIS) [{$bookingSource}]",
                    'bankAccountId'=>null,'baseAmount'=>$remainingBase,'taxAmount'=>$remainingTax,'taxRate'=>$vatRate,'transactionKind'=>$kind,
                    'taxRuleId'=>$roomTax['taxRuleId']??null,'taxRuleName'=>$roomTax['taxRuleName']??null,
                    'isSplitPayment'=>1,'splitCashAmount'=>$splitCashAmount,'splitTransferAmount'=>$splitTransferAmount,
                    'splitTransferBankAccountId'=>$splitTransferBankAccountId,
                ]);
            }else{
                $label=$paymentMethod==='qris'?'QRIS':($paymentMethod==='transfer'?'Transfer':($paymentMethod==='ota'?'Piutang OTA':'Tunai'));
                $insertTx(['id'=>'tx_pay_'.substr(hash('sha256',$operationId),0,29),'operationId'=>$operationId.':payment','category'=>$category,'subcategory'=>$roomType,'roomNumber'=>$roomNumber,'amount'=>$remaining,'date'=>$paymentDate,'description'=>"{$word} {$category} {$roomNumber} - {$guestName} ({$label}) [{$bookingSource}]",'bankAccountId'=>$paymentMethod==='ota'?'ota_receivable':$bankAccountId,'baseAmount'=>$remainingBase,'taxAmount'=>$remainingTax,'taxRate'=>$vatRate,'transactionKind'=>$kind,'taxRuleId'=>$roomTax['taxRuleId']??null,'taxRuleName'=>$roomTax['taxRuleName']??null]);
            }
        }
        $securityDepositSummary=null;
        if($securityDepositRequired){
            $securityDepositSummary=tamasyaEnsureSecurityDepositRow($pdo,$bookingId,$securityDepositRequiredAmount,$actor,$source);
            tamasyaSyncSecurityDepositProjection($pdo,$bookingId,$securityDepositSummary,$actor,$source);
        }
        if($securityDepositReceivedAmount>0){
            $securityDepositShiftRequirement=tamasyaManualTransactionRequiresOpenShift(['type'=>'income','amount'=>$securityDepositReceivedAmount,'bankAccountId'=>$securityDepositBankAccountId]);
            $securityDepositShiftSessionId=resolveOpenShiftSessionId($pdo,$actor,$securityDepositShiftRequirement);
            $securityDepositResult=tamasyaReceiveGuestSecurityDepositInTransaction($pdo,$actor,$bookingId,[
                'amount'=>$securityDepositReceivedAmount,'requiredAmount'=>$securityDepositRequiredAmount,
                'paymentMethod'=>$securityDepositPaymentMethod,'bankAccountId'=>$securityDepositBankAccountId,
                'date'=>$securityDepositDate,'operationId'=>'gsd_receive_'.substr(hash('sha256',$operationId),0,70)
            ],$source,$securityDepositShiftSessionId);
            $securityDepositSummary=$securityDepositResult['summary'];
            if(!empty($securityDepositResult['transactionId']))$txIds[]=$securityDepositResult['transactionId'];
        }

        recalculateBookingFinancials($pdo,$bookingId,true);
        assertBookingLedgerInvariant($pdo,$bookingId,$actor,$source,false);
        $finalStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$finalStmt->execute([$bookingId]);$final=$finalStmt->fetch(PDO::FETCH_ASSOC)?:[];
        $notifId='n_book_'.substr(hash('sha256',$operationId),0,32);
        $notifMessage=$lifecycleStatus==='active'
            ?"Check-in sukses! Kamar {$roomNumber} atas nama {$guestName} ({$bookingSource}) tercatat. Status pembayaran: ".strtoupper((string)($final['paymentStatus']??'unpaid'))
            :"Reservasi dibuat! Kamar {$roomNumber} atas nama {$guestName} untuk {$checkIn} s.d. {$checkOut}.";
        $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'booking') ON DUPLICATE KEY UPDATE message=VALUES(message)")->execute([$notifId,$notifMessage]);

        if($publicRequestId!==''){
            $convert=$pdo->prepare("UPDATE public_reservation_requests SET status='converted',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,linked_booking_id=?,rejection_reason=NULL,updated_at=CURRENT_TIMESTAMP WHERE public_request_id=? AND status IN ('pending_review','reviewing') AND linked_booking_id IS NULL");
            $convert->execute([(string)($actor['id']??''),$bookingId,$publicRequestId]);
            if($convert->rowCount()!==1)throw new RuntimeException('Status permintaan reservasi berubah saat booking diproses.');
            writeRequiredEnterpriseAudit($pdo,$actor,'Konversi reservasi website ke booking','public_reservation_request',$publicRequestId,$publicRow,['status'=>'converted','linkedBookingId'=>$bookingId,'roomNumber'=>$roomNumber,'roomType'=>$roomType],$source);
        }
        $identityFingerprint=hash('sha256',strtolower($guestName).'|'.strtolower($guestEmail).'|'.$guestPhone);
        writeRequiredEnterpriseAudit($pdo,$actor,$lifecycleStatus==='active'?'Membuat check-in melalui workflow pusat':'Membuat reservasi melalui workflow pusat','booking',$bookingId,null,[
            'guestFingerprint'=>$identityFingerprint,'roomNumber'=>$roomNumber,'roomType'=>$roomType,'checkIn'=>$checkIn,'checkOut'=>$checkOut,'stayMode'=>$stayMode,'scheduledCheckInAt'=>$scheduledCheckInAt,'scheduledCheckOutAt'=>$scheduledCheckOutAt,'status'=>$lifecycleStatus,
            'paymentStatus'=>$final['paymentStatus']??'unpaid','ledgerNet'=>$final['amountPaid']??0,'totalAmount'=>$totalAmount,'bookingSource'=>$bookingSource,
            'operationId'=>$operationId,'lifecycleIntent'=>$lifecycleIntent,'transactionIds'=>$txIds,'securityDeposit'=>$securityDepositSummary,
                'wholesale'=>$wholesaleQuote===null?null:['planId'=>$wholesaleQuote['planId'],'planCode'=>$wholesaleQuote['planCode'],'nights'=>$wholesaleQuote['nightsCount'],'total'=>$wholesaleQuote['total'],'currency'=>$wholesaleQuote['currency']]
        ],$source,['channelId'=>$channel]);
        logActivity($pdo,$lifecycleStatus==='active'?'create_checkin':'create_reservation',($lifecycleStatus==='active'?'Check-in ':'Reservasi ').$guestName.' di Kamar '.$roomNumber.' melalui workflow pusat '.$channel,(string)($actor['id']??''),currentStaffLabel($actor));
        bumpServerRevision($pdo);

        $taxDetail=$vatAmount>0?"   • Harga Dasar: *Rp ".number_format($totalAmount-$vatAmount,0,',','.')."*\n   • Pajak ({$vatRate}%): *Rp ".number_format($vatAmount,0,',','.')."*\n":'';
        $messagePayload=['guestName'=>$guestName,'guestPhone'=>$guestPhone,'roomNumber'=>$roomNumber,'checkIn'=>$checkIn,'checkOut'=>$checkOut,'bookingSource'=>$bookingSource,'totalAmount'=>$totalAmount,'paymentStatus'=>$final['paymentStatus']??'unpaid','paymentMethod'=>$paymentMethod,'isSplitPayment'=>$isSplitPayment,'splitCashAmount'=>$splitCashAmount,'splitTransferAmount'=>$splitTransferAmount];
        $telegramMessage=$lifecycleStatus==='active'
            ?getCheckInTelegramMessage($messagePayload,$roomType,$taxDetail,currentStaffLabel($actor))
            :"📅 *RESERVASI DIBUAT*\n\n👤 Tamu: *{$guestName}*\n🔑 Kamar: *{$roomNumber}* ({$roomType})\n🗓 Menginap: *{$checkIn}* s.d. *{$checkOut}*\n💳 Status: *".strtoupper((string)($final['paymentStatus']??'unpaid'))."*\n📡 Sumber: *{$bookingSource}*";
        $result=['bookingId'=>$bookingId,'bookingStatus'=>$lifecycleStatus,'stayMode'=>$stayMode,'scheduledCheckInAt'=>$scheduledCheckInAt,'scheduledCheckOutAt'=>$scheduledCheckOutAt,'paymentStatus'=>$final['paymentStatus']??'unpaid','amountPaid'=>(float)($final['amountPaid']??0),'balanceDue'=>(float)($final['balanceDue']??0),'roomNumber'=>$roomNumber,'roomType'=>$roomType,'totalAmount'=>$totalAmount,'discountAmount'=>$discountAmount,'vatRate'=>$vatRate,'vatAmount'=>$vatAmount,'transactionIds'=>$txIds,'operationId'=>$operationId,'lifecycleIntent'=>$lifecycleIntent,'securityDeposit'=>$securityDepositSummary,'telegramMessage'=>$telegramMessage];
        tamasyaCompleteCanonicalBookingReceipt($pdo,$operationId,$result);
        if(!$joinGroupTransaction)tamasyaFinancialCommit($pdo);
        if(!$joinGroupTransaction&&$broadcast&&$telegramMessage!=='')broadcastTelegramNotification($pdo,$telegramMessage);
        return ['duplicate'=>false]+$result;
    }catch(Throwable $e){
        if(!$joinGroupTransaction&&$pdo->inTransaction())$pdo->rollBack();
        tamasyaFailCanonicalBookingReceipt($pdo,$operationId,$e);
        throw $e;
    }
}

/**
 * Canonical transaction for Telegram extension and room-service charges.
 * Owns lock, idempotency receipt, booking/ledger mutation, required audit,
 * notification, and commit. External Telegram broadcast remains caller-side.
 */
function applyCanonicalTelegramBookingChargeWorkflow($pdo,$actor,array $payload,string $operationId) {
    // Selaras gate Telegram (r_extend/r_layanan): baseline role ATAU centangan
    // manage_stay_access (owner read-only tetap ditolak). Daftar role keras lama
    // memblokir peran non-baseline yang dicentang pada commit biaya canonical.
    if(function_exists('hasCapability')){
        if(!hasCapability($actor,'manage_stay_access',['admin','manager','receptionist']))throw new RuntimeException('Peran akun tidak berhak menambah biaya booking.');
    }
    elseif(!in_array(strtolower((string)($actor['role']??'')),['admin','manager','receptionist'],true))throw new RuntimeException('Peran akun tidak berhak menambah biaya booking.');
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'biaya/perpanjangan booking via Telegram');
    $bookingId=trim((string)($payload['bookingId']??''));
    $roomNumber=trim((string)($payload['roomNumber']??''));
    $action=strtolower(trim((string)($payload['action']??'')));
    $amount=round((float)($payload['amount']??0),2);
    $paymentStatus=strtolower(trim((string)($payload['paymentStatus']??'unpaid')));
    $chargePaymentMethod=strtolower(trim((string)($payload['paymentMethod']??'')));
    $chargeBankAccountId=trim((string)($payload['bankAccountId']??''));
    if($bookingId===''||$roomNumber===''||!in_array($action,['extension','extra'],true)||$amount<=0)throw new InvalidArgumentException('Data biaya booking Telegram tidak lengkap.');
    if(!in_array($paymentStatus,['paid','unpaid'],true))throw new InvalidArgumentException('Status pembayaran biaya booking tidak valid.');
    if($paymentStatus==='paid' && !in_array($chargePaymentMethod,['cash','transfer','qris'],true))throw new InvalidArgumentException('Metode pembayaran biaya booking Telegram tidak valid.');
    if($paymentStatus!=='paid'){$chargePaymentMethod='';$chargeBankAccountId='';}
    $operationId=normalizeTelegramMutationOperationId($operationId);
    try{
        $pdo->beginTransaction();
        claimTelegramMutation($pdo,$operationId,(string)$actor['id'],'bookings',$bookingId,[
            'action'=>$action,'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'amount'=>$amount,
            'paymentStatus'=>$paymentStatus,'paymentMethod'=>$chargePaymentMethod,'bankAccountId'=>$chargeBankAccountId,
            'nights'=>(int)($payload['nights']??0),'serviceName'=>(string)($payload['serviceName']??''),'qty'=>(int)($payload['qty']??0),
        ],'callback');
        $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");
        $bookingStmt->execute([$bookingId,$roomNumber]);
        $booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
        if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
        $roomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
        $roomStmt->execute([$roomNumber]);
        $chargeRoom=$roomStmt->fetch(PDO::FETCH_ASSOC);
        if(!$chargeRoom)throw new RuntimeException('Kamar booking tidak ditemukan.');
        if(isset($payload['expectedCheckOut']) && ((string)$booking['checkOut']!==(string)$payload['expectedCheckOut'] || abs((float)$booking['totalAmount']-(float)$payload['expectedTotalAmount'])>0.01))throw new RuntimeException('Booking berubah sejak konfirmasi. Mulai kembali perpanjangan/layanan.');
        if(isset($payload['expectedBookingVersion'])&&(int)($booking['version']??0)!==(int)$payload['expectedBookingVersion'])throw new RuntimeException('Booking berubah sejak konfirmasi. Buka ulang perpanjangan.');
        if(isset($payload['quotedBaseAmount'])){
            $base=$action==='extension'?round((float)$chargeRoom['price']*(int)$payload['nights'],2):round((float)$payload['unitPrice']*(int)$payload['qty'],2);
            $quoteRate=resolveConfiguredTaxRate($pdo,(string)($booking['bookingSource']??'Direct'),$action,null,date('Y-m-d'));
            if($action==='extension'&&isset($payload['quotedMasterBase'])){
                if(abs($base-(float)$payload['quotedMasterBase'])>0.01||abs($quoteRate-(float)$payload['quotedTaxRate'])>0.0001)throw new RuntimeException('Tarif/pajak berubah. Buka kembali perpanjangan.');
                if(($payload['priceMode']??'master')==='negotiated'){
                    if(tamasyaStringLength(trim((string)($payload['negotiationReason']??'')))<5)throw new InvalidArgumentException('Alasan harga nego wajib minimal 5 karakter.');
                    $base=round((float)$payload['quotedBaseAmount'],2);
                }
            }
            // Extension master quote must use the same per-night rupiah pricing
            // rule as the Telegram preview; negotiated gross remains exact cents.
            $quotedGross=$action==='extension'&&($payload['priceMode']??'master')==='master'
                ?tamasyaPublishedRoomGrossTotal((float)$chargeRoom['price'],(int)$payload['nights'],(float)$quoteRate)
                :round($base+round($base*$quoteRate/100,2),2);
            if($action==='extension'&&($payload['priceMode']??'master')==='master'){
                $base=calculateInclusiveTaxBreakdown($quotedGross,$quoteRate)['baseAmount'];
            }
            if(($payload['priceMode']??'master')==='negotiated'){
                $inclusive=calculateInclusiveTaxBreakdown($amount,$quoteRate);
                if(abs($inclusive['baseAmount']-$base)>0.01)throw new RuntimeException('Harga nego dan PBJT tidak sesuai quote.');
                $quotedGross=$amount;
            }
            if(abs($base-(float)$payload['quotedBaseAmount'])>0.01 || abs($amount-$quotedGross)>0.01)throw new RuntimeException('Tarif atau pajak berubah sejak konfirmasi. Mulai kembali agar nominalnya sesuai.');
        }
        if($paymentStatus==='paid'){
            $chargeBankAccountId=tamasyaResolvePaymentAccount($pdo,$chargePaymentMethod,$chargeBankAccountId,[
                'allowedMethods'=>['cash','transfer','qris'],'lock'=>true,'context'=>'Pembayaran Telegram booking'
            ]);
        }
        $before=tamasyaBookingAuditSnapshot($booking);
        $bookingSource=(string)($booking['bookingSource']??'Direct');
        $txId=null;$tax=null;$newCheckOut=(string)($booking['checkOut']??'');$serviceName=null;$qty=0;$bookingExtraId=null;$smartLockRefreshJobId=null;$smartLockRefreshResult=null;
        $category=null;$categoryId=null;$categorySystemKey=null;$subcategory=null;$subcategoryId=null;$subcategorySystemKey=null;

        if($action==='extension'){
            $nights=max(1,(int)($payload['nights']??0));
            $currentCheckout=(string)($booking['checkOut']??'');
            if($currentCheckout===''||strtotime($currentCheckout)===false)throw new RuntimeException('Tanggal checkout booking tidak valid.');
            $newCheckOut=date('Y-m-d',strtotime($currentCheckout.' +'.$nights.' day'));
            $stayExtension=tamasyaR3ExtendStay($pdo,$actor,$bookingId,$newCheckOut,'telegram-extension');
            $smartLockRefreshJobId=$stayExtension['smartLockRefreshJobId']??null;
            $tax=resolveBookingChargeTaxPolicy($booking,[
                'action'=>'extension','bookingSource'=>$bookingSource,'bankAccountId'=>$chargeBankAccountId?:null,'date'=>date('Y-m-d')
            ],$amount);
            $oldGross=max(0.0,(float)($booking['totalAmount']??0));
            $oldVat=max(0.0,(float)($booking['vatAmount']??0));
            $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
            $rate=(float)$tax['taxRate'];
            $newVat=$oldVat+(float)$tax['taxAmount'];
            $newRate=$oldGross<=0.0001?$rate:(($oldRate!==null&&abs($oldRate-$rate)<0.0001)?$oldRate:null);
            $newTotal=$oldGross+$amount;
            $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
            $bookingExtraId='ex_ext_tg_'.substr(hash('sha256',$operationId),0,20);
            $roomCategoryRow=tamasyaRequireSystemFinanceCategory($pdo,'room_rental','income',true);
            $category=(string)$roomCategoryRow['name'];$categoryId=(string)$roomCategoryRow['id'];$categorySystemKey='room_rental';
            if(trim((string)($booking['roomType']??''))==='')throw new RuntimeException('Tipe kamar booking kosong. Perbaiki data booking sebelum perpanjangan.');
            $extras[]=[
                'id'=>$bookingExtraId,'name'=>'Perpanjangan '.$nights.' malam','price'=>$amount,'qty'=>1,'total'=>$amount,
                'baseAmount'=>(float)$tax['baseAmount'],'taxAmount'=>(float)$tax['taxAmount'],'taxRate'=>(float)$tax['taxRate'],
                'taxSnapshotStatus'=>(string)($tax['taxSnapshotStatus']??'confirmed'),'taxSource'=>(string)($tax['taxSource']??'live_rule'),'taxRuleId'=>$tax['taxRuleId']??null,
                'taxKind'=>'extension','allocationType'=>'room','revenueType'=>'room',
                'category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'paymentStatus'=>$paymentStatus,
                'paymentMethod'=>$paymentStatus==='paid'?$chargePaymentMethod:null,'bankAccountId'=>$paymentStatus==='paid'&&$chargeBankAccountId!==''?$chargeBankAccountId:null,
                'paymentOperationId'=>$paymentStatus==='paid'?$operationId:null,
                'createdAt'=>date('Y-m-d H:i:s'),'previousCheckOut'=>$currentCheckout,'newCheckOut'=>$newCheckOut,
            ];
            $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=?,vatRate=?,vatAmount=?,paymentStatus=?,version=version+1,updatedBy=?,updatedSource='telegram' WHERE id=?")
                ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$newTotal,$newRate,$newVat,$paymentStatus==='unpaid'?'unpaid':$booking['paymentStatus'],$actor['id'],$bookingId]);
        }else{
            $serviceName=trim((string)($payload['serviceName']??''));
            $qty=max(1,(int)($payload['qty']??0));
            $unitPrice=round((float)($payload['unitPrice']??($amount/$qty)),2);
            if($serviceName==='')throw new InvalidArgumentException('Nama layanan wajib diisi.');
            $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',true);
            $catalogSelection=tamasyaResolveFinanceCatalogSelection($pdo,[
                'type'=>'income',
                'categoryId'=>trim((string)($payload['categoryId']??''))?:$extraRoot['id'],
                'category'=>trim((string)($payload['category']??''))?:$extraRoot['name'],
                'subcategoryId'=>trim((string)($payload['subcategoryId']??''))?:null,
                'subcategory'=>trim((string)($payload['subcategory']??''))?:null,
            ],true,true);
            $category=$catalogSelection['categoryName'];$categoryId=$catalogSelection['categoryId'];$categorySystemKey=$catalogSelection['categorySystemKey']?:null;
            $subcategory=$catalogSelection['subcategoryName'];$subcategoryId=$catalogSelection['subcategoryId'];$subcategorySystemKey=$catalogSelection['subcategorySystemKey']?:null;
            $extras=[];
            if(!empty($booking['extras'])){$decoded=json_decode((string)$booking['extras'],true);if(is_array($decoded))$extras=$decoded;}
            $tax=resolveBookingChargeTaxPolicy($booking,[
                'action'=>'extra','bookingSource'=>$bookingSource,'bankAccountId'=>$chargeBankAccountId?:null,'date'=>date('Y-m-d')
            ],$amount);
            $bookingExtraId='ex_tg_'.substr(hash('sha256',$operationId),0,24);
            $extras[]=[
                'id'=>$bookingExtraId,'name'=>$serviceName,'price'=>$unitPrice,'qty'=>$qty,'total'=>$amount,
                'baseAmount'=>(float)$tax['baseAmount'],'taxAmount'=>(float)$tax['taxAmount'],'taxRate'=>(float)$tax['taxRate'],
                'taxSnapshotStatus'=>(string)($tax['taxSnapshotStatus']??'confirmed'),'taxSource'=>(string)($tax['taxSource']??'live_rule'),'taxRuleId'=>$tax['taxRuleId']??null,
                'taxKind'=>'extra','allocationType'=>'extra','revenueType'=>'extra',
                'category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                'subcategory'=>$subcategory,'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,'paymentStatus'=>$paymentStatus,
                'paymentMethod'=>$paymentStatus==='paid'?$chargePaymentMethod:null,'bankAccountId'=>$paymentStatus==='paid'&&$chargeBankAccountId!==''?$chargeBankAccountId:null,
                'paymentOperationId'=>$paymentStatus==='paid'?$operationId:null,
                'createdAt'=>date('Y-m-d H:i:s')
            ];
            $oldGross=max(0.0,(float)($booking['totalAmount']??0));
            $oldVat=max(0.0,(float)($booking['vatAmount']??0));
            $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
            $rate=(float)$tax['taxRate'];$newVat=$oldVat+(float)$tax['taxAmount'];
            $newRate=$oldGross<=0.0001?$rate:(($oldRate!==null&&abs($oldRate-$rate)<0.0001)?$oldRate:null);
            $newTotal=$oldGross+$amount;
            $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=?,vatAmount=?,vatRate=?,paymentStatus=?,version=version+1,updatedBy=?,updatedSource='telegram' WHERE id=?")
                ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$newTotal,$newVat,$newRate,$paymentStatus==='unpaid'?'unpaid':$booking['paymentStatus'],$actor['id'],$bookingId]);
        }

        if($paymentStatus==='paid'){
            $catalogSelection=tamasyaResolveFinanceCatalogSelection($pdo,[
                'type'=>'income','categoryId'=>$categoryId,'category'=>$category,
                'subcategoryId'=>$subcategoryId,'subcategory'=>$subcategory,
            ],true,true);
            $category=$catalogSelection['categoryName'];$categoryId=$catalogSelection['categoryId'];$categorySystemKey=$catalogSelection['categorySystemKey']?:null;
            $subcategory=$catalogSelection['subcategoryName'];$subcategoryId=$catalogSelection['subcategoryId'];$subcategorySystemKey=$catalogSelection['subcategorySystemKey']?:null;
            $txId='tx_'.$action.'_tg_'.substr(hash('sha256',$operationId),0,28);
            $description=$action==='extension'
                ? 'Perpanjangan Kamar '.$roomNumber.' (Telegram Bot)'
                : 'Tambahan '.$serviceName.' Kamar '.$roomNumber.' ('.$qty.'x - Telegram Bot)';
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$txId,'type'=>'income','category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                'subcategory'=>$subcategory,'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,'roomNumber'=>$roomNumber,
                'amount'=>$amount,'date'=>date('Y-m-d'),'description'=>$description,'bookingId'=>$bookingId,'createdBy'=>$actor['username']??$actor['id'],
                'bookingSource'=>$bookingSource,'bankAccountId'=>$chargeBankAccountId?:null,'baseAmount'=>$tax['baseAmount'],'taxAmount'=>$tax['taxAmount'],
                'taxRate'=>$tax['taxRate'],'taxSnapshotStatus'=>$tax['taxSnapshotStatus']??'confirmed','taxSource'=>$tax['taxSource']??'live_rule',
                'taxRuleId'=>$tax['taxRuleId']??null,'transactionKind'=>'booking_charge','sourceEntity'=>'booking','sourceEntityId'=>$bookingId,
                'isSystemGenerated'=>1,'operationId'=>$operationId,'updatedBy'=>$actor['id'],'updatedSource'=>'telegram','version'=>1
            ],$actor,'booking',['lockCatalog'=>true,'source'=>'telegram']);
            attachTransactionToActorShift($pdo,$txId,$actor);
            // The Telegram charge already changed the folio total above. This
            // allocation is accounting evidence only (delta=0) and binds the
            // receipt to the exact component so replay/later receipts cannot
            // classify or pay the same extension/service twice.
            if($bookingExtraId===null||$bookingExtraId==='')throw new RuntimeException('Komponen biaya booking Telegram tidak memiliki ID canonical.');
            $allocationType=$action==='extension'?'extension':'extra';
            $allocationId='al_tg_'.substr(hash('sha256',$operationId.'|'.$txId.'|'.$bookingExtraId),0,40);
            $allocationOperation='tg_charge:'.substr(hash('sha256',$operationId.'|allocation'),0,48);
            $alloc=$pdo->prepare("INSERT INTO transaction_allocations
                (id,operation_id,transaction_id,booking_id,allocation_type,amount,base_amount,tax_amount,tax_rate,tax_snapshot_status,tax_source,tax_rule_id,
                 category,category_id,category_system_key,subcategory,subcategory_id,subcategory_system_key,booking_extra_id,description,
                 booking_total_delta,extra_was_existing,reporting_only,transaction_booking_link_added,transaction_room_link_added,transaction_source_link_added,status,created_by,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,'confirmed',?,?,?,?,?,?,?,?,?,?,0,1,0,0,0,0,'active',?,CURRENT_TIMESTAMP)");
            $alloc->execute([$allocationId,$allocationOperation,$txId,$bookingId,$allocationType,$amount,$tax['baseAmount'],$tax['taxAmount'],$tax['taxRate'],
                $tax['taxSource']??'live_rule',$tax['taxRuleId']??null,
                $category?:null,$categoryId?:null,$categorySystemKey,$subcategory?:null,$subcategoryId?:null,$subcategorySystemKey,
                $bookingExtraId,$description,$actor['id']??null]);
        }
        recalculateBookingFinancials($pdo,$bookingId,true);
        assertBookingLedgerInvariant($pdo,$bookingId,$actor,'telegram',false);
        $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$afterStmt->execute([$bookingId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC);
        $txRow=null;if($txId){$txStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$txStmt->execute([$txId]);$txRow=$txStmt->fetch(PDO::FETCH_ASSOC);}
        $label=$action==='extension'?'Memperpanjang booking melalui Telegram':'Menambah layanan booking melalui Telegram';
        writeRequiredEnterpriseAudit($pdo,$actor,$label,'booking',$bookingId,$before,[
            'booking'=>tamasyaBookingAuditSnapshot($after),'transaction'=>tamasyaTransactionAuditSnapshot($txRow),
            'action'=>$action,'amount'=>$amount,'priceMode'=>$payload['priceMode']??'master','negotiationReason'=>$payload['negotiationReason']??null,'quotedMasterBase'=>$payload['quotedMasterBase']??null,'nights'=>(int)($payload['nights']??0),'serviceName'=>$serviceName,'qty'=>$qty,'smartLockRefreshJobId'=>$smartLockRefreshJobId
        ],'telegram');
        $notif=$action==='extension'
            ? 'Perpanjangan sewa Kamar '.$roomNumber.' sukses ('.(int)($payload['nights']??0).' Malam).'
            : 'Tambahan layanan '.$serviceName.' Kamar '.$roomNumber.' sukses ('.$qty.'x).';
        $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
            ->execute([generateServerId('n_tg_charge'),$notif]);
        completeTelegramMutation($pdo,$operationId,['bookingId'=>$bookingId,'transactionId'=>$txId,'action'=>$action,'amount'=>$amount]);
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        if($smartLockRefreshJobId!==null){
            try{$smartLockRefreshResult=processSmartLockBridgeJobById($pdo,(string)$smartLockRefreshJobId,$actor,'telegram-extension-refresh');}
            catch(Throwable $smartLockError){$smartLockRefreshResult=['status'=>'error','message'=>clientExceptionMessage('Refresh smart-lock perpanjangan belum selesai',$smartLockError)];}
        }
        return ['booking'=>$after,'transactionId'=>$txId,'tax'=>$tax,'newCheckOut'=>$newCheckOut,'serviceName'=>$serviceName,'qty'=>$qty,'amount'=>$amount,'smartLockRefreshJobId'=>$smartLockRefreshJobId,'smartLockRefresh'=>$smartLockRefreshResult];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        try{failTelegramMutation($pdo,$operationId,$e);}catch(Throwable $ignored){}
        throw $e;
    }
}

/** Only unpaid room/extension charges can be negotiated; receipts and services stay intact. */
function tamasyaNegotiatedPricePlan(array $booking,array $components,array $paid,float $ledgerNet,float $finalTotal): array {
    $old=round((float)$booking['totalAmount'],2);$finalTotal=round($finalTotal,2);
    if(!is_finite($finalTotal)||$finalTotal<=0||$finalTotal>$old||$finalTotal>1000000000000)throw new InvalidArgumentException('Harga final harus lebih dari nol dan tidak melebihi tagihan lama.');
    $available=0.0;
    foreach($components as $c)if($c['allocationType']==='room')$available=round($available+max(0,round($c['gross']-($paid[$c['key']]??0),2)),2);
    $cut=round($old-$finalTotal,2);
    if($cut>$available+0.001||$finalTotal<round($ledgerNet,2)-0.001)throw new InvalidArgumentException('Harga nego tidak boleh mengurangi pembayaran yang sudah diterima atau biaya layanan/POS.');
    if($cut<=0)throw new InvalidArgumentException('Harga nego harus lebih rendah dari tagihan lama.');
    $left=(int)round($cut*100);$capacity=(int)round($available*100);$cuts=[];$taxCut=0.0;
    foreach($components as $c){
        if($c['allocationType']!=='room')continue;
        $cap=(int)round(max(0,round($c['gross']-($paid[$c['key']]??0),2))*100);
        if($cap<=0)continue;
        $cents=$capacity===$cap?$left:min($cap,(int)round($left*$cap/$capacity));
        $capacity-=$cap;$left-=$cents;
        if($cents<=0)continue;
        $delta=$cents/100;$tax=round($c['gross']>0?$c['tax']*$delta/$c['gross']:0,2);
        $cuts[$c['key']]=['amount'=>$delta,'tax'=>$tax];$taxCut=round($taxCut+$tax,2);
    }
    if($left!==0)throw new RuntimeException('Alokasi harga nego tidak seimbang.');
    $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
    foreach($extras as $index=>&$extra){
        $key='extra:'.(trim((string)($extra['id']??''))?:('idx'.$index));
        if(!isset($cuts[$key]))continue;
        $component=null;foreach($components as $c)if($c['key']===$key){$component=$c;break;}
        $extra['total']=round($component['gross']-$cuts[$key]['amount'],2);
        $extra['price']=$extra['total']/max(1,(float)($extra['qty']??1));
        $extra['taxAmount']=round($component['tax']-$cuts[$key]['tax'],2);
        $extra['baseAmount']=round($extra['total']-$extra['taxAmount'],2);
        $extra['taxRate']=$component['rate'];$extra['taxSource']='negotiated_snapshot';
    }
    unset($extra);
    return ['totalAmount'=>$finalTotal,'vatAmount'=>round((float)$booking['vatAmount']-$taxCut,2),'vatRate'=>$booking['vatRate']??null,'extras'=>$extras,'discountAmount'=>$cut,'taxReduction'=>$taxCut,'componentCuts'=>$cuts];
}

function tamasyaBookingNegotiationQuote(PDO $pdo,array $booking): array {
    if(($booking['status']??'')!=='active'||!empty($booking['isOpenEnded']))throw new RuntimeException('Harga nego checkout hanya untuk booking aktif dengan durasi tetap. Durasi terbuka memakai total aktual.');
    if(strtolower((string)($booking['financialProjectionMode']??'live_ledger'))==='legacy_snapshot'||abs((float)($booking['discountAmount']??0))>0.001)throw new RuntimeException('Booking memakai snapshot/diskon lama. Rekonsiliasi melalui koreksi audit sebelum menetapkan harga nego agar ledger tetap sesuai.');
    $total=round((float)$booking['totalAmount'],2);
    if(tamasyaValidBookingTaxSnapshot($booking,$total)===null)throw new RuntimeException('Snapshot PBJT booking belum valid. Koreksi audit dahulu sebelum negosiasi.');
    if(tamasyaSchemaTableExists($pdo,'growth_folio_charge_allocations')){
        $folio=$pdo->prepare('SELECT id FROM growth_folio_charge_allocations WHERE booking_id=? LIMIT 1');$folio->execute([(string)$booking['id']]);
        if($folio->fetchColumn())throw new RuntimeException('Tagihan sudah dialokasikan ke folio Enterprise. Lepaskan/perbaiki routing folio terlebih dahulu sebelum menetapkan harga nego agar invoice tidak berbeda.');
    }
    $ledger=bookingLedgerTotals($pdo,(string)$booking['id']);
    $bundle=tamasyaBookingReceiptComponents($pdo,$booking);
    $paid=tamasyaBookingComponentPaid($pdo,$booking,$bundle,(float)$ledger['net']);
    $roomBalance=0.0;$extraBalance=0.0;
    foreach($bundle['components'] as $c){
        $due=max(0.0,round($c['gross']-($paid[$c['key']]??0),2));
        if($c['allocationType']==='room')$roomBalance=round($roomBalance+$due,2);else $extraBalance=round($extraBalance+$due,2);
    }
    if(abs(round($total-$ledger['net'],2)-($roomBalance+$extraBalance))>0.01)throw new RuntimeException('Ledger dan komponen booking belum seimbang. Rekonsiliasi sebelum nego.');
    $fingerprint=hash('sha256',json_encode([$booking['id'],$booking['version']??0,$booking['checkOut'],$total,$booking['vatAmount'],$booking['vatRate']??null,$booking['extras']??null,$ledger,$paid],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    return ['bookingId'=>(string)$booking['id'],'roomNumber'=>(string)$booking['roomNumber'],'totalAmount'=>$total,'amountPaid'=>round($ledger['net'],2),'balanceDue'=>round($total-$ledger['net'],2),'roomBalance'=>$roomBalance,'extraBalance'=>$extraBalance,'minimumTotal'=>round($total-$roomBalance,2),'quoteToken'=>$fingerprint,'components'=>$bundle['components'],'paid'=>$paid];
}

function tamasyaApplyBookingNegotiatedPrice(PDO $pdo,array $actor,string $bookingId,array $input,string $source,string $operationId): array {
    // Selaras gate tab Kamar/Telegram: baseline role ATAU centangan
    // manage_stay_access (owner read-only tetap ditolak).
    if(function_exists('hasCapability')){
        if(!hasCapability($actor,'manage_stay_access',['admin','manager','receptionist']))throw new DomainException('Peran ini tidak berhak menetapkan harga nego.',403);
    }
    elseif(!in_array(strtolower((string)($actor['role']??'')),['admin','manager','receptionist'],true))throw new DomainException('Peran ini tidak berhak menetapkan harga nego.',403);
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'harga nego booking');
    $reason=trim((string)($input['reason']??''));
    if(tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Alasan harga nego minimal 5 karakter wajib diisi.');
    if(!isset($input['finalTotal'])||!is_numeric($input['finalTotal'])||!is_finite((float)$input['finalTotal']))throw new InvalidArgumentException('Harga final tidak valid.');
    $operationId=normalizeTelegramMutationOperationId($operationId);
    try{
        $pdo->beginTransaction();
        if($source==='telegram')claimTelegramMutation($pdo,$operationId,(string)$actor['id'],'booking_negotiated_price',$bookingId,$input,'callback');
        $q=$pdo->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$bookingId]);$booking=$q->fetch(PDO::FETCH_ASSOC);
        if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
        $quote=tamasyaBookingNegotiationQuote($pdo,$booking);
        if(!hash_equals($quote['quoteToken'],(string)($input['quoteToken']??'')))throw new DomainException('Tagihan/pembayaran berubah sejak harga ditampilkan. Buka kembali checkout sebelum nego.',409);
        // The receptionist states what the guest hands over at this checkout. Cash
        // received earlier is closed in its own shift and must not be netted off a
        // second time, so it is added back to reach the new booking total.
        $collectedNow=round((float)$input['finalTotal'],2);
        $targetTotal=round($collectedNow+(float)$quote['amountPaid'],2);
        $plan=tamasyaNegotiatedPricePlan($booking,$quote['components'],$quote['paid'],$quote['amountPaid'],$targetTotal);
        $status=$plan['totalAmount']<=$quote['amountPaid']+0.001?'paid':($quote['amountPaid']>0?'partial':'unpaid');
        $pdo->prepare("UPDATE bookings SET totalAmount=?,vatAmount=?,vatRate=?,extras=?,paymentStatus=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
            ->execute([$plan['totalAmount'],$plan['vatAmount'],$plan['vatRate'],json_encode($plan['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,$actor['id'],$source,$bookingId]);
        assertBookingLedgerInvariant($pdo,$bookingId,$actor,$source,false);
        $q->execute([$bookingId]);$after=$q->fetch(PDO::FETCH_ASSOC);
        writeRequiredEnterpriseAudit($pdo,$actor,'Menetapkan harga nego sebelum checkout','booking',$bookingId,tamasyaBookingAuditSnapshot($booking),['booking'=>tamasyaBookingAuditSnapshot($after),'reason'=>$reason,'discountAmount'=>$plan['discountAmount'],'taxReduction'=>$plan['taxReduction'],'componentCuts'=>$plan['componentCuts'],'amountPaidPreserved'=>$quote['amountPaid'],'operationId'=>$operationId],$source);
        if($source==='telegram')completeTelegramMutation($pdo,$operationId,['bookingId'=>$bookingId,'finalTotal'=>$plan['totalAmount']]);
        bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
        $after['amountPaid']=$quote['amountPaid'];$after['balanceDue']=round($plan['totalAmount']-$quote['amountPaid'],2);
        $after['extras']=$plan['extras'];
        return ['booking'=>$after,'discountAmount'=>$plan['discountAmount'],'taxReduction'=>$plan['taxReduction']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
