<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Role yang boleh mengoperasikan laci/shift kas pada web dan Telegram. */
function tamasyaShiftOperatorRoles(): array {
    return ['admin','manager','finance','receptionist'];
}

function tamasyaCanOperateShift(?array $staff): bool {
    return is_array($staff) && in_array(strtolower((string)($staff['role'] ?? '')), tamasyaShiftOperatorRoles(), true);
}

/** Source line 4778: getOpenShiftForStaff */
function getOpenShiftForStaff(PDO $pdo, string $staffId, bool $forUpdate = false): ?array {
    $staffId = trim($staffId);
    if ($staffId === '') return null;
    $sql = "SELECT * FROM shift_sessions
            WHERE status='open' AND (staff_id=? OR companion_staff_id=?)
            ORDER BY opened_at DESC LIMIT 2" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$staffId,$staffId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) > 1) {
        throw new RuntimeException('Akun terikat pada lebih dari satu shift terbuka. Hubungi Manager untuk koreksi data shift.');
    }
    return $rows[0] ?? null;
}

/** Source line 4793: getShiftParticipantIds */
function getShiftParticipantIds(array $shift): array {
    $ids=[];
    foreach (['staff_id','companion_staff_id'] as $key) {
        $value=trim((string)($shift[$key]??''));
        if($value!=='' && !in_array($value,$ids,true))$ids[]=$value;
    }
    return $ids;
}

/** Source line 4802: getShiftDisplayName */
function getShiftDisplayName(array $shift): string {
    $primary=trim((string)($shift['staff_name']??'')) ?: 'Staf';
    $companion=trim((string)($shift['companion_staff_name']??''));
    return $companion!=='' ? $primary.' & '.$companion : $primary;
}

/** Source line 4808: resolveOpenShiftSessionId */
function resolveOpenShiftSessionId(PDO $pdo, array $staff, bool $requiredForFinancialOperation = false): ?string {
    $staffId=trim((string)($staff['id']??''));
    // FIX28R1: when an operational/financial mutation requires an open shift and
    // already owns a DB transaction, lock that shift row. This prevents any
    // transaction attributed to the shift from committing after shift-close has
    // frozen its totals/report. Read-only callers keep the previous non-locking path.
    $lockForMutation=$requiredForFinancialOperation && $pdo->inTransaction();
    $shift=$staffId!=='' ? getOpenShiftForStaff($pdo,$staffId,$lockForMutation) : null;
    if(!$shift && $requiredForFinancialOperation) {
        throw new RuntimeException('Buka shift kas terlebih dahulu atau pastikan akun Anda sudah dipilih sebagai pendamping shift sebelum menjalankan transaksi operasional.');
    }
    return $shift ? (string)$shift['id'] : null;
}

function tamasyaManualTransactionRequiresOpenShift(array $transaction): bool {
    return tamasyaCanonicalCashLegAmount($transaction) > 0.009;
}

/**
 * Canonical physical check-in shift invariant.
 *
 * Reservasi (reserved) boleh dibuat tanpa shift karena belum memulai okupansi.
 * Namun ketika kebijakan require_open_shift_for_sale aktif, setiap transisi
 * fisik ke active/check-in wajib dilakukan oleh aktor yang terikat pada shift
 * terbuka (pemilik atau pendamping), terlepas dari apakah pembayaran diterima
 * pada saat yang sama. Cash/payment guard tetap berdiri sendiri.
 */
function tamasyaRequireOpenShiftForPhysicalCheckIn(PDO $pdo, array $staff, ?array $settings = null): ?string {
    $settings=is_array($settings)?$settings:($pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[]);
    if((int)($settings['require_open_shift_for_sale']??1)!==1)return null;
    $staffId=trim((string)($staff['id']??''));
    if($staffId==='')throw new RuntimeException('Check-in aktual membutuhkan identitas petugas yang valid.');
    // FIX28R1: lock the exact open shift row until the physical check-in transaction
    // commits/rolls back. This serializes check-in against shift-close and prevents
    // a receipt/occupancy commit from landing after the shift report is frozen.
    $shift=getOpenShiftForStaff($pdo,$staffId,true);
    if(!$shift){
        throw new RuntimeException('Buka shift terlebih dahulu atau pastikan akun Anda menjadi pendamping shift sebelum check-in. Reservasi tetap dapat disimpan tanpa shift; kamar baru boleh menjadi TERISI setelah check-in dilakukan pada shift aktif.');
    }
    return (string)$shift['id'];
}


/**
 * Canonical receptionist room-access issuance shift invariant.
 *
 * Key/PIN issuance is an operational custody mutation. When the hotel policy
 * requires an open shift for sales/front-office work, a receptionist must own
 * or accompany exactly one open shift and that exact row is locked until the
 * key-issue transaction commits/rolls back. Admin/Manager semantics remain
 * unchanged: they may issue access without a cashier shift, as before FIX28R3.
 */
/** Roles that may hold the room-access capability, i.e. may physically hand over a key.
 * Receptionist keeps its original behaviour; any other role needs an explicit
 * manage_room_access capability grant, which is exactly the permission that admitted
 * them to the key screens in the first place.
 */
function tamasyaHasRoomAccessCapabilityForShift(array $staff): bool {
    $role = strtolower(trim((string)($staff['role'] ?? '')));
    if ($role === 'receptionist') return true;
    if (!function_exists('hasCapability')) return $role === 'receptionist';
    return hasCapability($staff,'manage_room_access',['receptionist']);
}

function tamasyaRequireOpenShiftForRoomAccessIssue(PDO $pdo, array $staff, ?array $settings = null): ?string {
    // Keyed on the capability that authorised the action, not on the literal role
    // string. hasCapability() honours a per-staff override for any role, so a finance
    // or cleaning account granted manage_room_access passed the dispatcher gate and
    // then skipped the shift requirement entirely, leaving a key issuance with no
    // cashier and no shift attribution. A genuinely read-only role still skips it
    // because it can never reach this function.
    if(!tamasyaHasRoomAccessCapabilityForShift($staff))return null;
    $settings=is_array($settings)?$settings:($pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[]);
    if((int)($settings['require_open_shift_for_sale']??1)!==1)return null;
    $staffId=trim((string)($staff['id']??''));
    if($staffId==='')throw new RuntimeException('Penyerahan kunci/PIN membutuhkan identitas petugas yang valid.');
    if(!$pdo->inTransaction())throw new RuntimeException('Penyerahan kunci/PIN wajib memvalidasi shift di dalam transaksi database.');
    $shift=getOpenShiftForStaff($pdo,$staffId,true);
    if(!$shift){
        throw new RuntimeException('Buka shift kas terlebih dahulu atau pastikan akun sudah dipilih sebagai pendamping sebelum menyerahkan kunci/PIN.');
    }
    return (string)$shift['id'];
}

/** Source line 4818: attachTransactionToActorShift */
function attachTransactionToActorShift(PDO $pdo, string $transactionId, array $actor, ?bool $required = null): ?string {
    if(!$pdo->inTransaction()) throw new RuntimeException('Pengaitan transaksi ke shift wajib berada dalam transaksi database.');
    $transactionId=trim($transactionId);
    if($transactionId==='')throw new InvalidArgumentException('ID transaksi kosong saat mengaitkan shift.');
    $txStmt=$pdo->prepare("SELECT recordOrigin,shiftExempt,shiftSessionId FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([$transactionId]);
    $transaction=$txStmt->fetch(PDO::FETCH_ASSOC);
    if(!$transaction)throw new RuntimeException('Transaksi tidak ditemukan saat mengaitkan shift.');
    if(tamasyaTransactionIsHistorical($transaction)){
        // Defense in depth: historical backfill is an accounting archive, not
        // a cash movement of the staff currently logged in.
        return null;
    }
    $existingShift=trim((string)($transaction['shiftSessionId']??''));
    if($existingShift!=='')return $existingShift;
    if($required===null){
        $required=(int)$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn()===1;
    }
    $shiftSessionId=resolveOpenShiftSessionId($pdo,$actor,$required);
    if($shiftSessionId!==null){
        tamasyaAssignTransactionShiftIfEligible($pdo,$transactionId,$shiftSessionId,$actor);
    }
    return $shiftSessionId;
}

/** Source line 4830: validateOptionalShiftCompanion */
function validateOptionalShiftCompanion(PDO $pdo, string $primaryStaffId, string $companionStaffId, bool $forUpdate = true): ?array {
    $companionStaffId=trim($companionStaffId);
    if($companionStaffId==='') return null;
    if(hash_equals($primaryStaffId,$companionStaffId)) throw new InvalidArgumentException('Pendamping harus berbeda dari petugas utama.');
    $sql="SELECT id,name,username,role,status FROM staff WHERE id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$companionStaffId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || strtolower((string)($row['status']??''))!=='active') throw new InvalidArgumentException('Akun pendamping tidak aktif atau tidak ditemukan.');
    if(strtolower((string)($row['role']??''))!=='receptionist') throw new InvalidArgumentException('Pendamping shift lobi harus memiliki role Receptionist.');
    $existing=getOpenShiftForStaff($pdo,$companionStaffId,$forUpdate);
    if($existing) throw new RuntimeException('Pendamping sudah terikat pada shift terbuka lain.');
    return $row;
}


/**
 * Mengaitkan hanya transaksi live milik peserta yang dibuat selama sesi.
 * Backfill historis dan transaksi shift-exempt tidak boleh pernah terseret ke
 * kas operasional hanya karena dicatat ketika shift sedang terbuka.
 */
function claimUnassignedLiveTransactionsForShift(PDO $pdo, array $shift): int {
    if(!$pdo->inTransaction()) throw new RuntimeException('Klaim transaksi shift wajib berada dalam transaksi database.');
    $shiftId=trim((string)($shift['id']??''));
    $openedAt=trim((string)($shift['opened_at']??''));
    if($shiftId===''||$openedAt==='')throw new InvalidArgumentException('Identitas shift untuk klaim transaksi tidak lengkap.');
    $participantIds=getShiftParticipantIds($shift);
    if(!$participantIds)return 0;
    $ph=implode(',',array_fill(0,count($participantIds),'?'));
    $qParticipants=$pdo->prepare("SELECT id,username,name FROM staff WHERE id IN ($ph)");
    $qParticipants->execute($participantIds);
    $participantRows=$qParticipants->fetchAll(PDO::FETCH_ASSOC)?:[];
    return tamasyaClaimEligibleTransactionsForShift($pdo,$shiftId,$openedAt,$participantRows);
}

function assertShiftNightAuditReady(PDO $pdo, array $shift, array $settings): void {
    if(!tamasyaNightAuditIsEnabled($settings) || (int)($settings['require_night_audit_for_night_shift']??1)!==1 || !isNightShiftRow($shift,tamasyaShiftBoundariesMinutes($settings)))return;
    $auditStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE shift_session_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 1");
    $auditStmt->execute([(string)$shift['id']]);
    $nightAudit=$auditStmt->fetch(PDO::FETCH_ASSOC);
    if(!$nightAudit)throw new RuntimeException('Shift malam tidak dapat ditutup sebelum Night Audit seluruh kamar selesai.');
    $unresolvedStmt=$pdo->prepare("SELECT COUNT(*) FROM night_audit_items WHERE audit_id=? AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved'");
    $unresolvedStmt->execute([(string)$nightAudit['id']]);
    $unresolved=(int)$unresolvedStmt->fetchColumn();
    if($unresolved>0)throw new RuntimeException("Masih ada {$unresolved} temuan Night Audit yang belum diselesaikan Manager.");
}

function tamasyaShiftSettlementTotals(array $transactions): array {
    $cashInc = 0;
    $cashExp = 0;
    $digitalInc = 0;

    foreach ($transactions as $t) {
        if(tamasyaTransactionIsHistorical($t) || !empty($t['shiftExempt']))continue;
        $amount = (float)$t['amount'];
        $bankAccId = isset($t['bankAccountId']) ? strtolower(trim((string)$t['bankAccountId'])) : '';
        $kind = strtolower(trim((string)($t['transactionKind'] ?? 'manual')));
        $hasBank = !empty($bankAccId) && $bankAccId !== 'cash' && $bankAccId !== 'none';
        $isTechnicalNonLiquid = in_array($bankAccId,['ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true);
        $isNonCashForfeit = $kind === 'security_deposit_forfeit';
        $isInternal = in_array($kind,['internal_transfer','ota_transfer'],true) || strtolower(trim((string)($t['sourceEntity'] ?? '')))==='ota_disbursement';

        if ($isTechnicalNonLiquid || $isNonCashForfeit) continue;
        $splitActive=!empty($t['isSplitPayment']);
        $splitCash=round((float)($t['splitCashAmount']??0),2);
        $splitTransfer=round((float)($t['splitTransferAmount']??0),2);
        $splitBank=trim((string)($t['splitTransferBankAccountId']??''));
        $validSplit=$splitActive && $splitCash>0 && $splitTransfer>0 && !in_array(strtolower($splitBank),['','cash','none','ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true)
            && abs(round($splitCash+$splitTransfer-$amount,2))<=0.01;
        if ($t['type'] === 'income') {
            if($validSplit){
                // One canonical split transaction represents two settlement legs.
                // The cash leg changes the physical drawer; the transfer leg is
                // digital revenue unless this is an internal movement.
                $cashInc += $splitCash;
                if(!$isInternal)$digitalInc += $splitTransfer;
            } elseif ($hasBank) {
                if (!$isInternal) $digitalInc += $amount;
            } else {
                // Kaki kas dari mutasi internal tetap memengaruhi isi laci fisik.
                $cashInc += $amount;
            }
        } elseif ($t['type'] === 'expense') {
            if($validSplit){
                $cashExp += $splitCash;
            } elseif (!$hasBank) {
                $cashExp += $amount;
            }
        }
    }

    return ['cashInc'=>round($cashInc,2),'cashExp'=>round($cashExp,2),'digitalInc'=>round($digitalInc,2),'txCount'=>count($transactions)];
}


/** Source line 4844: getShiftFinancials */
function getShiftFinancials($pdo, $staffMember, $shiftTime, $shiftDate, $companionId = 'none', bool $strict = false, ?string $explicitShiftSessionId = null) {
    $calculationError = false;
    try {
        $explicitShiftSessionId=trim((string)$explicitShiftSessionId);
        if($explicitShiftSessionId!==''){
            $stmtExplicit=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1");
            $stmtExplicit->execute([$explicitShiftSessionId]);
            $session=$stmtExplicit->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$session && $strict) throw new RuntimeException('Sesi shift target tidak lagi terbuka.');
        }else{
            $session = getOpenShiftForStaff($pdo,(string)($staffMember['id']??''),false);
        }
        $usesSession = $session
            && (string)($session['shift_date']??'') === (string)$shiftDate
            && (string)($session['shift_time']??'') === (string)$shiftTime;
        if($strict && $explicitShiftSessionId!=='' && !$usesSession)throw new RuntimeException('Tanggal/jadwal tidak cocok dengan sesi shift target.');
        if($usesSession){
            $stmt=$pdo->prepare("SELECT * FROM transactions WHERE shiftSessionId=? ORDER BY createdAt,id");
            $stmt->execute([(string)$session['id']]);
        }else{
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE date = ?");
            $stmt->execute([$shiftDate]);
        }
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filteredTx = [];
        $creator = isset($staffMember['username']) ? strtolower(trim($staffMember['username'])) : "";
        $staffId = isset($staffMember['id']) ? strtolower(trim((string)$staffMember['id'])) : "";
        // Nama tampilan tidak pernah digunakan sebagai identitas pemilik transaksi.
        // Hanya ID immutable atau username akun yang diterima.
        $compCreator = "";
        $compStaffId = "";
        if ($companionId !== 'none') {
            $stmtComp = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
            $stmtComp->execute([$companionId]);
            $companionMember = $stmtComp->fetch(PDO::FETCH_ASSOC);
            if ($companionMember) {
                $compCreator = isset($companionMember['username']) ? strtolower(trim($companionMember['username'])) : "";
                $compStaffId = isset($companionMember['id']) ? strtolower(trim((string)$companionMember['id'])) : "";
            }
        }
        
        foreach ($transactions as $t) {
            if($usesSession){$filteredTx[]=$t;continue;}
            $txCreator = isset($t['createdBy']) ? strtolower(trim($t['createdBy'])) : "";
            $txUpdatedBy = isset($t['updatedBy']) ? strtolower(trim((string)$t['updatedBy'])) : "";
            // Identitas role (mis. "receptionist") tidak boleh dipakai sebagai pemilik
            // transaksi karena dapat mencampur kas beberapa user dengan role yang sama.
            $matchesPrimary = (
                (!empty($staffId) && ($txCreator === $staffId || $txUpdatedBy === $staffId)) ||
                (!empty($creator) && $txCreator === $creator)
            );
            $matchesCompanion = (
                (!empty($compStaffId) && ($txCreator === $compStaffId || $txUpdatedBy === $compStaffId)) ||
                (!empty($compCreator) && $txCreator === $compCreator)
            );
            $matchesStaff = $matchesPrimary || $matchesCompanion;
            
            if (!$matchesStaff) {
                continue;
            }
            
            $txHour = -1;
            if (isset($t['createdAt']) && !empty($t['createdAt'])) {
                if (preg_match('/(\d{2}):(\d{2}):(\d{2})/', $t['createdAt'], $matches)) {
                    $txHour = (int)$matches[1];
                }
            }
            
            if ($shiftTime === 'pagi') {
                if ($txHour !== -1 && ($txHour < 7 || $txHour >= 15)) continue;
            } elseif ($shiftTime === 'siang') {
                if ($txHour !== -1 && ($txHour < 15 || $txHour >= 23)) continue;
            } elseif ($shiftTime === 'malam') {
                if ($txHour !== -1 && ($txHour >= 7 && $txHour < 23)) continue;
            }
            
            $filteredTx[] = $t;
        }
        
        $startingCash = 0;
        try {
            $opening = $usesSession ? ($session['opening_cash'] ?? false) : false;
            if($opening===false){
                $stmtShift = $pdo->prepare("SELECT opening_cash FROM shift_sessions WHERE (staff_id = ? OR companion_staff_id = ?) AND shift_date = ? AND shift_time = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1");
                $stmtShift->execute([$staffMember['id'] ?? '',$staffMember['id'] ?? '', $shiftDate, $shiftTime]);
                $opening = $stmtShift->fetchColumn();
            }
            if ($opening !== false) {
                $startingCash = (float)$opening;
            } else {
                // Fallback hanya untuk data lama yang belum menggunakan shift_sessions.
                $stmtConf = $pdo->query("SELECT initial_balance FROM config WHERE id = 'system_default' LIMIT 1");
                if ($stmtConf) $startingCash = (float)$stmtConf->fetchColumn();
            }
        } catch (Throwable $e) {
            if ($strict) throw $e;
            $calculationError = true;
            error_log('[Shift Financials] Gagal membaca kas awal: ' . $e->getMessage());
        }
        $totals=tamasyaShiftSettlementTotals($filteredTx);
        $cashInc=$totals['cashInc'];$cashExp=$totals['cashExp'];$digitalInc=$totals['digitalInc'];$txCount=$totals['txCount'];

        $expectedCash = round($startingCash + $cashInc - $cashExp,2);
        
        return [
            "startingCash" => $startingCash,
            "cashInc" => $cashInc,
            "cashExp" => $cashExp,
            "digitalInc" => $digitalInc,
            "expectedCash" => $expectedCash,
            "txCount" => $txCount,
            "calculationError" => $calculationError
        ];
    } catch (Throwable $e) {
        if ($strict) throw $e;
        error_log('[Shift Financials] Perhitungan gagal: ' . $e->getMessage());
        return [
            "startingCash" => 0,
            "cashInc" => 0,
            "cashExp" => 0,
            "digitalInc" => 0,
            "expectedCash" => 0,
            "txCount" => 0,
            "calculationError" => true
        ];
    }
}


/** Source line 4975: upsertShiftReportForSession */
function upsertShiftReportForSession(PDO $pdo, array $shift, float $expectedCash, float $actualCash, float $variance, float $digitalRevenue, int $transactionsCount, string $notes, ?string $companionStaffId = null, ?string $displayName = null): string {
    $shiftSessionId=trim((string)($shift['id']??''));
    $staffId=trim((string)($shift['staff_id']??''));
    if($shiftSessionId==='' || $staffId==='') throw new InvalidArgumentException('Identitas sesi shift tidak lengkap.');
    $shiftDate=trim((string)($shift['shift_date']??date('Y-m-d')));
    $shiftTime=trim((string)($shift['shift_time']??'all'));
    if(!validIsoDate($shiftDate)) $shiftDate=date('Y-m-d');
    if(!in_array($shiftTime,['pagi','siang','malam','all'],true)) $shiftTime='all';
    $staffName=trim((string)($displayName??$shift['staff_name']??'Staf')) ?: 'Staf';
    $reportId='sr_shift_'.substr(hash('sha256',$shiftSessionId),0,32);
    $sql="INSERT INTO shift_reports
        (id,staffId,companionStaffId,shiftSessionId,staffName,shiftDate,shiftTime,startingCash,expectedCash,actualPhysicalCash,variance,digitalRevenue,transactionsCount,notes,createdAt)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE staffId=VALUES(staffId),companionStaffId=VALUES(companionStaffId),staffName=VALUES(staffName),shiftDate=VALUES(shiftDate),shiftTime=VALUES(shiftTime),startingCash=VALUES(startingCash),expectedCash=VALUES(expectedCash),actualPhysicalCash=VALUES(actualPhysicalCash),variance=VALUES(variance),digitalRevenue=VALUES(digitalRevenue),transactionsCount=VALUES(transactionsCount),notes=VALUES(notes)";
    $pdo->prepare($sql)->execute([
        $reportId,$staffId,$companionStaffId,$shiftSessionId,$staffName,$shiftDate,$shiftTime,
        (float)($shift['opening_cash']??0),$expectedCash,$actualCash,$variance,$digitalRevenue,$transactionsCount,$notes
    ]);
    return $reportId;
}

function tamasyaShiftVariancePolicy(array $staff, float $variance, float $tolerance, string $notes, string $overrideReason=''): array {
    if(!tamasyaCanOperateShift($staff))throw new RuntimeException('Akun tidak diizinkan menutup shift.');
    if(!is_finite($variance)||!is_finite($tolerance))throw new InvalidArgumentException('Perhitungan selisih tidak valid.');
    $variance=round($variance,2);$tolerance=max(0,round($tolerance,2));
    $notes=trim($notes);$overrideReason=trim($overrideReason);
    $manager=in_array(strtolower((string)($staff['role']??'')),['admin','manager'],true);
    if($variance!=0.0 && tamasyaStringLength($notes)<5 && !($manager && tamasyaStringLength($overrideReason)>=5))
        throw new InvalidArgumentException('Uang kurang/lebih wajib disertai keterangan minimal 5 karakter.');
    if(abs($variance)>$tolerance && $manager && tamasyaStringLength($overrideReason)<5)
        throw new InvalidArgumentException('Admin/Manager wajib mengisi alasan override selisih minimal 5 karakter.');
    return ['needsReview'=>abs($variance)>$tolerance&&!$manager,'overrideReason'=>abs($variance)>$tolerance&&$manager?$overrideReason:null,'explanation'=>$notes!==''?$notes:($manager&&$variance!=0.0?$overrideReason:'')];
}

function tamasyaCreateShiftVarianceReview(PDO $pdo, array $staff, array $shift, float $actual, float $expected, string $reason, string $source): string {
    if(!$pdo->inTransaction())throw new RuntimeException('Review selisih wajib dalam transaksi penutupan shift.');
    $id='approval_shift_'.substr(hash('sha256',(string)$shift['id']),0,32);
    $payload=['shiftId'=>$shift['id'],'actualCash'=>$actual,'expectedCash'=>$expected,'variance'=>round($actual-$expected,2),'source'=>$source];
    $pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,'shift_cash_variance','shift_session',?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP)")
        ->execute([$id,$shift['id'],abs($payload['variance']),$reason,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$staff['id'],$staff['name']??'Staf']);
    writeRequiredEnterpriseAudit($pdo,$staff,'Mengajukan pemeriksaan selisih kas shift','approval_request',$id,null,$payload+['status'=>'pending','reason'=>$reason],$source);
    return $id;
}

/** Source line 4996: finalizeTelegramShiftReport */
function finalizeTelegramShiftReport($pdo, string $operationId, array $staff, array $context, string $notes, string $action = 'callback'): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'penutupan shift kas via Telegram');
    $staffId = trim((string)($staff['id'] ?? ''));
    $shiftDate = trim((string)($context['shiftDate'] ?? ''));
    $shiftTime = trim((string)($context['shiftTime'] ?? ''));
    $actualCash = isset($context['actualCash']) && is_numeric($context['actualCash']) ? round((float)$context['actualCash'],2) : null;
    $companionId = trim((string)($context['companionId'] ?? 'none')) ?: 'none';
    if ($staffId === '' || !validIsoDate($shiftDate) || !in_array($shiftTime,['pagi','siang','malam','all'],true) || $actualCash === null || !is_finite($actualCash) || $actualCash < 0) {
        throw new InvalidArgumentException('Konteks tutup shift tidak valid.');
    }
    $notes = trim($notes);
    $operationId=normalizeTelegramMutationOperationId($operationId);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $overrideReason=trim((string)($context['overrideReason']??''));
        $targetShiftId=trim((string)($context['shiftId']??''));
        claimTelegramMutation($pdo,$operationId,$staffId,'shift_sessions',$targetShiftId!==''?$targetShiftId:($staffId.'|'.$shiftDate.'|'.$shiftTime),[
            'shiftDate'=>$shiftDate,'shiftTime'=>$shiftTime,'actualCash'=>$actualCash,'companionId'=>$companionId,'notes'=>$notes,'overrideReason'=>$overrideReason
        ],$action);
        $role=strtolower((string)($staff['role']??''));
        if(!tamasyaCanOperateShift($staff)) throw new RuntimeException('Akun tidak memiliki izin mengoperasikan shift kas.');
        if($targetShiftId!==''){
            $stmtSession=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");
            $stmtSession->execute([$targetShiftId]);
            $session=$stmtSession->fetch(PDO::FETCH_ASSOC);
            if($session){
                $participantIds=getShiftParticipantIds($session);
                if($role==='receptionist' && !in_array($staffId,$participantIds,true)){
                    throw new RuntimeException('Resepsionis hanya boleh menutup shift yang diikutinya.');
                }
                if(!in_array($role,['admin','manager','finance','receptionist'],true)){
                    throw new RuntimeException('Peran akun tidak diizinkan menutup shift target.');
                }
            }
        }else{
            $stmtSession = $pdo->prepare("SELECT * FROM shift_sessions WHERE (staff_id=? OR companion_staff_id=?) AND shift_date=? AND shift_time=? AND status='open' ORDER BY opened_at DESC LIMIT 1 FOR UPDATE");
            $stmtSession->execute([$staffId,$staffId,$shiftDate,$shiftTime]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
        }
        if (!$session) throw new RuntimeException('Tidak ada sesi shift terbuka yang cocok untuk ditutup.');
        if((string)($session['shift_date']??'')!==$shiftDate || (string)($session['shift_time']??'')!==$shiftTime){
            throw new RuntimeException('Konteks tutup shift tidak lagi cocok dengan sesi target. Buka ulang menu Tutup Shift.');
        }
        $companionId=trim((string)($session['companion_staff_id']??'')) ?: 'none';
        $settings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        assertShiftNightAuditReady($pdo,$session,$settings);
        claimUnassignedLiveTransactionsForShift($pdo,$session);
        // Penutupan shift harus fail-closed. Kesalahan query tidak boleh dianggap kas Rp0.
        $financials = getShiftFinancials($pdo,$staff,$shiftTime,$shiftDate,$companionId,true,(string)$session['id']);
        if (!empty($financials['calculationError'])) {
            throw new RuntimeException('Perhitungan transaksi shift gagal; penutupan dibatalkan agar saldo tidak dianggap Rp0.');
        }
        $startingCash = (float)$session['opening_cash'];
        $expectedCash = (float)$financials['expectedCash'];
        $digitalRevenue = (float)$financials['digitalInc'];
        $txCount = (int)$financials['txCount'];
        $variance = round($actualCash - $expectedCash,2);
        $tolerance=max(0.0,(float)($settings['cash_variance_tolerance']??0));
        $policy=tamasyaShiftVariancePolicy($staff,$variance,$tolerance,$notes,$overrideReason);
        $overrideReason=$policy['overrideReason'];
        if($notes==='')$notes=$policy['explanation']?:'Serah terima tanpa catatan tambahan.';
        $reviewId=$policy['needsReview']?tamasyaCreateShiftVarianceReview($pdo,$staff,$session,$actualCash,$expectedCash,$notes,'telegram'):null;
        $staffName = (string)($staff['name'] ?? 'Staf');
        $companionName = trim((string)($session['companion_staff_name'] ?? ''));
        $reportStaffName = getShiftDisplayName($session);
        $reportId = upsertShiftReportForSession(
            $pdo,$session,$expectedCash,$actualCash,$variance,$digitalRevenue,$txCount,$notes,
            $companionId !== 'none' ? $companionId : null,$reportStaffName
        );
        $stmtClose = $pdo->prepare("UPDATE shift_sessions SET cash_income=?,cash_expense=?,expected_cash=?,actual_cash=?,variance=?,close_override_reason=?,status='closed',notes=?,closed_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'");
        $stmtClose->execute([(float)$financials['cashInc'],(float)$financials['cashExp'],$expectedCash,$actualCash,$variance,$overrideReason?:null,trim((string)($session['notes']??'')).(empty($session['notes'])?'':"\n").$notes,$session['id']]);
        if ($stmtClose->rowCount() !== 1) throw new RuntimeException('Sesi shift berubah sebelum penutupan disimpan.');
        tamasyaLockTransactionsForShift($pdo,(string)$session['id'],$staff);
        $closed=$session;$closed['status']='closed';$closed['actual_cash']=$actualCash;$closed['expected_cash']=$expectedCash;$closed['variance']=$variance;$closed['notes']=trim((string)($session['notes']??'')).(empty($session['notes'])?'':"\n").$notes;$closed['close_override_reason']=$overrideReason;$closed['reviewId']=$reviewId;
        writeRequiredEnterpriseAudit($pdo,$staff,'Menutup shift kas Telegram','shift_session',(string)$session['id'],$session,$closed,'telegram');
        $notifId = 'notif_tg_shift_' . substr(hash('sha256',$operationId),0,28);
        $varianceText = $variance == 0.0 ? 'PAS / COCOK (Rp 0)' : ($variance > 0
            ? 'LEBIH (+Rp '.tamasyaTelegramFormatAmount($variance).')'
            : 'KURANG (Rp '.tamasyaTelegramFormatAmount(abs($variance)).')');
        $notifMsg = "Tutup Shift {$reportStaffName} ({$shiftTime}, Selisih: {$varianceText}) tersimpan di database.";
        $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'system')")
            ->execute([$notifId,$notifMsg,date('Y-m-d H:i:s')]);
        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$staffId]);
        logActivity($pdo,'close_shift','Tutup Shift (Telegram): '.$reportStaffName.' - '.$shiftTime.' (Selisih: '.$varianceText.')');
        completeTelegramMutation($pdo,$operationId,['reportId'=>$reportId,'shiftSessionId'=>$session['id'],'variance'=>$variance]);
        bumpServerRevision($pdo);
        if ($ownsTransaction) tamasyaFinancialCommit($pdo);
        $reviewText=$reviewId?'Menunggu pemeriksaan Admin/Manager di web.':'Rekonsiliasi tersimpan.';
        $shiftLabel = ['pagi'=>'Shift Pagi (07:00 - 15:00)','siang'=>'Shift Siang (15:00 - 23:00)','malam'=>'Shift Malam (23:00 - 07:00)','all'=>'Satu Hari Penuh (24 Jam)'][$shiftTime];
        $tgMessage = "🔔 *LAPORAN SERAH TERIMA SHIFT & REKONSILIASI KAS*

" .
                     "👤 Staf: *{$reportStaffName}*
📅 Tanggal: *{$shiftDate}*
⏰ Jadwal: *{$shiftLabel}*

" .
                     "💵 Modal awal: *Rp ".tamasyaTelegramFormatAmount($startingCash)."*
" .
                     "📊 Kas seharusnya: *Rp ".tamasyaTelegramFormatAmount($expectedCash)."*
" .
                     "🔍 Kas aktual: *Rp ".tamasyaTelegramFormatAmount($actualCash)."*
" .
                     "⚠️ Selisih: *{$varianceText}*
💳 Non-tunai: *Rp ".tamasyaTelegramFormatAmount($digitalRevenue)."*
" .
                     "📈 Jumlah transaksi: *{$txCount}*
🔎 Status: {$reviewText}
📝 Catatan: _".str_replace(['_','*','`','[',']','(',')'],'',$notes).'_';
        return ['reportId'=>$reportId,'shiftSessionId'=>$session['id'],'variance'=>$variance,'varianceText'=>$varianceText,'overrideReason'=>$overrideReason?:null,'needsReview'=>(bool)$reviewId,'reviewId'=>$reviewId,'broadcastText'=>$tgMessage];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        failTelegramMutation($pdo,$operationId,$e);
        throw $e;
    }
}

/** Source line 5074: getRoomRentalCategoryName */
function getRoomRentalCategoryName($pdo) {
    if(!$pdo instanceof PDO) throw new RuntimeException('Database master kategori tidak tersedia.');
    return tamasyaSystemFinanceCategoryName($pdo,'room_rental','income');
}

/** Source line 5168: generateBotThermalReceipt */
function generateBotThermalReceipt($booking, $staffName) {
    $property=tamasyaPropertyProfile();
    $propertyName=strtoupper((string)($property['hotelName']??'HOTEL'));
    $propertyAddress=trim((string)($property['address']??''));
    $propertyPhone=trim((string)($property['phone']??''));
    $divider = "========================================\n";
    $line = "----------------------------------------\n";
    
    // Hitung durasi menginap (malam)
    $daysCount = 1;
    if (!empty($booking['checkIn']) && !empty($booking['checkOut'])) {
        $start = new DateTime($booking['checkIn']);
        $end = new DateTime($booking['checkOut']);
        $diff = $start->diff($end);
        $daysCount = $diff->days ?: 1;
    }
    
    $vatAmount = isset($booking['vatAmount']) ? (float)$booking['vatAmount'] : 0;
    $vatRate = isset($booking['vatRate']) && $booking['vatRate'] !== null && $booking['vatRate'] !== '' ? (float)$booking['vatRate'] : null;
    $hasVAT = $vatAmount > 0;
    
    // Rincian nota menampilkan dasar pengenaan pajak per komponen. Nilai gross
    // extra tetap tersimpan pada total, sedangkan PBJT ditampilkan satu kali.
    $extrasList = [];
    $extrasBaseTotal = 0;
    if (!empty($booking['extras'])) {
        $extrasData = json_decode($booking['extras'], true);
        if (is_array($extrasData)) {
            $extrasList = $extrasData;
            foreach ($extrasList as $ex) {
                $qty = isset($ex['qty']) ? max(1,(int)$ex['qty']) : 1;
                $price = isset($ex['price']) ? (float)$ex['price'] : 0;
                $gross = isset($ex['total']) && is_numeric($ex['total']) ? (float)$ex['total'] : $price * $qty;
                $base = isset($ex['baseAmount']) && is_numeric($ex['baseAmount'])
                    ? (float)$ex['baseAmount']
                    : max(0.0,$gross-(float)($ex['taxAmount']??0));
                $extrasBaseTotal += $base;
            }
        }
    }
    
    $totalAmount = (float)$booking['totalAmount'];
    $totalRoomRent = max(0.0,$totalAmount - $vatAmount - $extrasBaseTotal);
    
    $idNota = strtoupper(substr($booking['id'], 0, 10));
    $tanggal = date("d/m/Y", strtotime($booking['createdAt'] ?? 'now'));
    
    $text = "";
    $text .= "```\n"; // Blok teks monospace di Telegram
    $text .= $divider;
    $text .= "          " . substr($propertyName,0,34) . "          \n";
    if ($propertyAddress !== '') $text .= substr($propertyAddress,0,40) . "\n";
    if ($propertyPhone !== '') $text .= "Telp: " . substr($propertyPhone,0,34) . "\n";
    $text .= $divider;
    $text .= "ID NOTA   : " . str_pad($idNota, 28) . "\n";
    $text .= "TANGGAL   : " . str_pad($tanggal, 28) . "\n";
    $text .= "STAF      : " . str_pad($staffName, 28) . "\n";
    $text .= $line;
    $text .= "TAMU      : " . str_pad($booking['guestName'] ?? '', 28) . "\n";
    $text .= "TELP      : " . str_pad($booking['guestPhone'] ?? '', 28) . "\n";
    $text .= "KAMAR     : Kamar " . ($booking['roomNumber'] ?? '') . " (" . ($booking['roomType'] ?? '') . ")\n";
    $text .= "CHECK-IN  : " . ($booking['checkIn'] ?? '') . "\n";
    $text .= "CHECK-OUT : " . ($booking['checkOut'] ?? '') . "\n";
    $text .= "DURASI    : " . $daysCount . " Malam\n";
    $text .= $line;
    
    $formatIDR = function($val) {
        return "Rp " . number_format($val, 0, ',', '.');
    };
    
    // Baris sewa kamar
    $rentLabel = "Tarif Kamar (" . $daysCount . "x)";
    $rentVal = $formatIDR($totalRoomRent);
    $paddingWidth = 40 - strlen($rentLabel);
    if ($paddingWidth < 0) $paddingWidth = 0;
    $text .= $rentLabel . str_pad($rentVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    
    // Baris extras/layanan tambahan
    if (!empty($extrasList)) {
        foreach ($extrasList as $ex) {
            $qty = isset($ex['qty']) ? max(1,(int)$ex['qty']) : 1;
            $price = isset($ex['price']) ? (float)$ex['price'] : 0;
            $gross = isset($ex['total']) && is_numeric($ex['total']) ? (float)$ex['total'] : $price * $qty;
            $subEx = isset($ex['baseAmount']) && is_numeric($ex['baseAmount'])
                ? (float)$ex['baseAmount']
                : max(0.0,$gross-(float)($ex['taxAmount']??0));
            $exLabel = ($ex['name'] ?? 'Layanan') . " (x" . $qty . ")";
            $exVal = $formatIDR($subEx);
            $paddingWidth = 40 - strlen($exLabel);
            if ($paddingWidth < 0) $paddingWidth = 0;
            $text .= $exLabel . str_pad($exVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
        }
    }
    
    // Baris pajak/VAT
    if ($hasVAT) {
        $vatLabel = $vatRate === null ? "Pajak PBJT (tarif komponen)" : "Pajak PBJT (" . $vatRate . "%)";
        $vatVal = $formatIDR($vatAmount);
        $paddingWidth = 40 - strlen($vatLabel);
        if ($paddingWidth < 0) $paddingWidth = 0;
        $text .= $vatLabel . str_pad($vatVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    }
    
    $text .= $line;
    $totLabel = "TOTAL TAGIHAN";
    $totVal = $formatIDR($totalAmount);
    $paddingWidth = 40 - strlen($totLabel);
    if ($paddingWidth < 0) $paddingWidth = 0;
    $text .= $totLabel . str_pad($totVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    $text .= $line;
    
    $paymentStatusLabel = (strtolower($booking['paymentStatus'] ?? '') === 'paid') ? "LUNAS (PAID)" : "BELUM BAYAR (UNPAID)";
    $text .= "STATUS    : " . $paymentStatusLabel . "\n";
    
    if (strtolower($booking['paymentStatus'] ?? '') === 'paid') {
        $methodLabel = "CASH / TUNAI";
        $method = strtolower($booking['paymentMethod'] ?? '');
        if ($method === 'qris') {
            $methodLabel = "QRIS";
        } elseif ($method === 'transfer') {
            $methodLabel = "TRANSFER BANK";
        }
        $text .= "METODE    : " . $methodLabel . "\n";
    }
    
    $text .= $divider;
    $text .= "        TERIMA KASIH ATAS KUNJUNGAN      \n";
    $text .= "          " . substr($propertyName,0,34) . "           \n";
    $text .= $divider;
    $text .= "```";
    
    return $text;
}

/** Escaping aman untuk isi template email nota/laporan resmi. */
function tamasyaReceiptEmailEsc($value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}

/**
 * Konten email nota resmi satu booking (web & Telegram).
 * Matematika mengikuti generateBotThermalReceipt supaya angka di layar,
 * nota thermal, dan email selalu identik (dasar pajak + extras + PBJT).
 */
function tamasyaBookingReceiptEmailContent(array $booking,string $staffName,?array $property=null): array {
    $property=$property??tamasyaPropertyProfile();
    $propertyName=trim((string)($property['hotelName']??''))?:'Hotel';
    $propertyAddress=trim((string)($property['address']??''));
    $propertyPhone=trim((string)($property['phone']??''));
    $propertyEmail=trim((string)($property['email']??''));
    $daysCount=1;
    if(!empty($booking['checkIn'])&&!empty($booking['checkOut'])){
        try{$diff=(new DateTime((string)$booking['checkIn']))->diff(new DateTime((string)$booking['checkOut']));$daysCount=$diff->days?:1;}catch(Throwable $ignored){$daysCount=1;}
    }
    $vatAmount=isset($booking['vatAmount'])?(float)$booking['vatAmount']:0;
    $vatRate=(isset($booking['vatRate'])&&$booking['vatRate']!==null&&$booking['vatRate']!=='')?(float)$booking['vatRate']:null;
    $hasVAT=$vatAmount>0;
    $extrasList=[];
    if(!empty($booking['extras'])){
        $decoded=is_array($booking['extras'])?$booking['extras']:json_decode((string)$booking['extras'],true);
        if(is_array($decoded))$extrasList=$decoded;
    }
    $extrasBaseTotal=0.0;
    foreach($extrasList as $ex){
        $qty=isset($ex['qty'])?max(1,(int)$ex['qty']):1;
        $price=isset($ex['price'])?(float)$ex['price']:0;
        $gross=isset($ex['total'])&&is_numeric($ex['total'])?(float)$ex['total']:$price*$qty;
        $extrasBaseTotal+=isset($ex['baseAmount'])&&is_numeric($ex['baseAmount'])?(float)$ex['baseAmount']:max(0.0,$gross-(float)($ex['taxAmount']??0));
    }
    $totalAmount=(float)($booking['totalAmount']??0);
    $totalRoomRent=max(0.0,$totalAmount-$vatAmount-$extrasBaseTotal);
    $idNota=strtoupper(substr((string)($booking['id']??''),0,10));
    $createdTs=strtotime((string)($booking['createdAt']??''))?:time();
    $prefix=trim((string)($property['invoicePrefix']??''))?:'INV';
    $invoiceNumber=$prefix.'/'.date('Y',$createdTs).'/'.(strtoupper(substr((string)($booking['id']??''),0,8))?:'NOTA');
    $paid=strtolower((string)($booking['paymentStatus']??''))==='paid';
    $method=strtolower((string)($booking['paymentMethod']??''));
    $methodLabel=$method==='qris'?'QRIS':($method==='transfer'?'TRANSFER BANK':($method==='cash'?'TUNAI / CASH':''));
    $money=static fn($v)=>'Rp '.number_format((float)$v,0,',','.');
    $esc=static fn($v)=>tamasyaReceiptEmailEsc($v);
    $td='padding:10px 14px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#334155;';
    $tdr=$td.'text-align:right;white-space:nowrap;color:#0f172a;';
    $rows='';
    $rows.='<tr><td style="'.$td.'">Tarif Kamar ('.$esc($daysCount).'x)</td><td style="'.$tdr.'">'.$esc($money($totalRoomRent)).'</td></tr>';
    foreach($extrasList as $ex){
        $qty=isset($ex['qty'])?max(1,(int)$ex['qty']):1;
        $price=isset($ex['price'])?(float)$ex['price']:0;
        $gross=isset($ex['total'])&&is_numeric($ex['total'])?(float)$ex['total']:$price*$qty;
        $base=isset($ex['baseAmount'])&&is_numeric($ex['baseAmount'])?(float)$ex['baseAmount']:max(0.0,$gross-(float)($ex['taxAmount']??0));
        $rows.='<tr><td style="'.$td.'">'.$esc((string)($ex['name']??'Layanan')).' (x'.$esc($qty).')</td><td style="'.$tdr.'">'.$esc($money($base)).'</td></tr>';
    }
    if($hasVAT)$rows.='<tr><td style="'.$td.'">Pajak PBJT'.($vatRate!==null?' ('.$esc($vatRate).'%)':' (tarif komponen)').'</td><td style="'.$tdr.'">'.$esc($money($vatAmount)).'</td></tr>';
    $rows.='<tr><td style="padding:12px 14px;font-size:13px;font-weight:bold;color:#0f172a;background:#f8fafc;">TOTAL TAGIHAN</td><td style="padding:12px 14px;text-align:right;white-space:nowrap;font-size:14px;font-weight:bold;color:#0f172a;background:#f8fafc;">'.$esc($money($totalAmount)).'</td></tr>';
    $badge=$paid
        ?'<span style="display:inline-block;padding:4px 12px;border-radius:999px;background:#ecfdf5;color:#047857;font-weight:bold;font-size:12px;letter-spacing:.5px;">LUNAS'.($methodLabel!==''?' &middot; '.$esc($methodLabel):'').'</span>'
        :'<span style="display:inline-block;padding:4px 12px;border-radius:999px;background:#fffbeb;color:#b45309;font-weight:bold;font-size:12px;letter-spacing:.5px;">BELUM BAYAR</span>';
    $kepada='<strong style="color:#0f172a;font-size:14px;">'.$esc((string)($booking['guestName']??'-')).'</strong>'.(trim((string)($booking['guestPhone']??''))!==''?'<br><span style="color:#64748b;">'.$esc((string)$booking['guestPhone']).'</span>':'');
    $html='<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$esc($invoiceNumber).'</title></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
        .'<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">'
        .'<tr><td style="background:#0f172a;padding:22px 26px;"><div style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">'.$esc($propertyName).'</div>'
        .($propertyAddress!==''?'<div style="color:#cbd5e1;font-size:12px;margin-top:4px;">'.$esc($propertyAddress).'</div>':'')
        .'<div style="color:#cbd5e1;font-size:12px;margin-top:2px;">'.trim(($propertyPhone!==''?'Telp: '.$esc($propertyPhone):'').($propertyEmail!==''?' &middot; '.$esc($propertyEmail):''),' &middot;').'</div></td></tr>'
        .'<tr><td style="padding:22px 26px 6px;"><div style="font-size:15px;font-weight:bold;letter-spacing:1px;color:#0f172a;">NOTA RESMI / INVOICE</div>'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;"><tr>'
        .'<td style="font-size:12px;color:#64748b;">No. Nota<br><strong style="color:#0f172a;font-size:13px;">'.$esc($invoiceNumber).'</strong></td>'
        .'<td style="font-size:12px;color:#64748b;text-align:right;">Tanggal<br><strong style="color:#0f172a;font-size:13px;">'.date('d/m/Y',$createdTs).'</strong></td></tr></table></td></tr>'
        .'<tr><td style="padding:14px 26px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;">'
        .'<tr><td style="padding:12px 14px;width:50%;vertical-align:top;border-right:1px solid #e2e8f0;"><div style="font-size:11px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;">Kepada</div><div style="margin-top:4px;">'.$kepada.'</div></td>'
        .'<td style="padding:12px 14px;width:50%;vertical-align:top;"><div style="font-size:11px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;">Menginap</div>'
        .'<div style="font-size:13px;color:#334155;margin-top:4px;">Kamar '.$esc((string)($booking['roomNumber']??'-')).' ('.$esc((string)($booking['roomType']??'-')).')</div>'
        .'<div style="font-size:12px;color:#64748b;margin-top:2px;">'.$esc((string)($booking['checkIn']??'-')).' &rarr; '.$esc((string)($booking['checkOut']??'-')).'</div>'
        .'<div style="font-size:12px;color:#64748b;margin-top:2px;">Durasi: '.$esc($daysCount).' malam</div></td></tr></table></td></tr>'
        .'<tr><td style="padding:18px 26px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;border-collapse:separate;border-spacing:0;">'
        .'<tr><td style="padding:10px 14px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:bold;letter-spacing:.5px;border-radius:10px 0 0 0;">RINCIAN</td><td style="padding:10px 14px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:bold;text-align:right;letter-spacing:.5px;border-radius:0 10px 0 0;">JUMLAH</td></tr>'
        .$rows.'</table></td></tr>'
        .'<tr><td style="padding:14px 26px 4px;">'.$badge.'</td></tr>'
        .'<tr><td style="padding:12px 26px 8px;font-size:12px;color:#64748b;">Nota ini diterbitkan oleh sistem '.$esc($propertyName).' &middot; Staf: '.$esc($staffName).' &middot; ID Nota: '.$esc($idNota).'</td></tr>'
        .'<tr><td style="padding:16px 26px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Dokumen resmi dibuat otomatis oleh sistem. Terima kasih atas kunjungan Anda di '.$esc($propertyName).'.</td></tr>'
        .'</table></td></tr></table></body></html>';
    $subject='Nota '.$invoiceNumber.' - Kamar '.trim((string)($booking['roomNumber']??'')).' - '.$propertyName;
    return ['subject'=>$subject,'html'=>$html,'invoiceNumber'=>$invoiceNumber,'idNota'=>$idNota,'totalAmount'=>$totalAmount];
}

/**
 * Konten email laporan reservasi grup ke perusahaan (invoice kolektif).
 * Sumber angka = tamasyaMultiRoomDetail supaya sama dengan layar grup.
 */
function tamasyaGroupReportEmailContent(array $detail,string $staffName,?array $property=null,?array $company=null): array {
    $property=$property??tamasyaPropertyProfile();
    $propertyName=trim((string)($property['hotelName']??''))?:'Hotel';
    $propertyAddress=trim((string)($property['address']??''));
    $propertyPhone=trim((string)($property['phone']??''));
    $propertyEmail=trim((string)($property['email']??''));
    $group=is_array($detail['group']??null)?$detail['group']:[];
    $bookings=is_array($detail['bookings']??null)?$detail['bookings']:[];
    $totals=is_array($detail['totals']??null)?$detail['totals']:['total'=>0.0,'paid'=>0.0,'balance'=>0.0];
    $company=is_array($company)?$company:[];
    $reference=trim((string)($group['group_code']??''))?:('GRP-'.strtoupper(substr((string)($group['id']??''),0,8)));
    $statusLabels=['reserved'=>'Reservasi','active'=>'Menginap','completed'=>'Checkout','cancelled'=>'Dibatalkan'];
    $billingLabels=['individual'=>'Per kamar','split'=>'Digabung','master'=>'Satuan grup','corporate'=>'Korporat'];
    $money=static fn($v)=>'Rp '.number_format((float)$v,0,',','.');
    $esc=static fn($v)=>tamasyaReceiptEmailEsc($v);
    $th='padding:9px 10px;background:#0f172a;color:#ffffff;font-size:11px;font-weight:bold;text-align:left;';
    $thr=$th.'text-align:right;white-space:nowrap;';
    $td='padding:9px 10px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#334155;';
    $tdr=$td.'text-align:right;white-space:nowrap;color:#0f172a;';
    $rows='';
    foreach($bookings as $b){
        $cancelled=strtolower((string)($b['status']??''))==='cancelled';
        $lineTotal=$cancelled?'-':$money(max(0,(float)($b['totalAmount']??0)-(float)($b['discountAmount']??0)));
        $rows.='<tr><td style="'.$td.'"><strong style="color:#0f172a;">Kamar '.$esc((string)($b['roomNumber']??'-')).'</strong> <span style="color:#94a3b8;">('.$esc((string)($b['roomType']??'-')).')</span></td>'
            .'<td style="'.$td.'">'.$esc((string)($b['guestName']??'-')).'</td>'
            .'<td style="'.$td.'">'.$esc((string)($b['checkIn']??'-')).'</td>'
            .'<td style="'.$td.'">'.$esc((string)($b['checkOut']??'-')).'</td>'
            .'<td style="'.$td.'">'.$esc($statusLabels[strtolower((string)($b['status']??''))]??(string)($b['status']??'-')).'</td>'
            .'<td style="'.$tdr.'">'.$esc($lineTotal).'</td>'
            .'<td style="'.$tdr.'">'.$esc($money((float)($b['amountPaid']??0))).'</td>'
            .'<td style="'.$tdr.'">'.$esc($money((float)($b['balanceDue']??0))).'</td></tr>';
    }
    $summary='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;border:1px solid #e2e8f0;border-radius:10px;">'
        .'<tr><td style="padding:10px 14px;font-size:12px;color:#64748b;">Total tagihan grup</td><td style="padding:10px 14px;text-align:right;font-size:13px;font-weight:bold;color:#0f172a;">'.$esc($money((float)($totals['total']??0))).'</td></tr>'
        .'<tr><td style="padding:10px 14px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">Sudah dibayar</td><td style="padding:10px 14px;border-top:1px solid #e2e8f0;text-align:right;font-size:13px;color:#047857;">'.$esc($money((float)($totals['paid']??0))).'</td></tr>'
        .'<tr><td style="padding:10px 14px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">Sisa tagihan</td><td style="padding:10px 14px;border-top:1px solid #e2e8f0;text-align:right;font-size:13px;font-weight:bold;color:'.((float)($totals['balance']??0)>0?'#b45309':'#047857').';">'.$esc($money((float)($totals['balance']??0))).'</td></tr></table>';
    $kepada='<strong style="color:#0f172a;font-size:14px;">'.$esc(trim((string)($company['name']??''))?:'Perusahaan').'</strong>';
    if(trim((string)($company['tax_id']??''))!=='')$kepada.='<br><span style="color:#64748b;font-size:12px;">NPWP: '.$esc((string)$company['tax_id']).'</span>';
    if(trim((string)($company['address']??''))!=='')$kepada.='<br><span style="color:#64748b;font-size:12px;">'.$esc((string)$company['address']).'</span>';
    if(trim((string)($company['phone']??''))!=='')$kepada.='<br><span style="color:#64748b;font-size:12px;">'.$esc((string)$company['phone']).'</span>';
    $html='<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$esc($reference).'</title></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
        .'<table role="presentation" width="720" cellpadding="0" cellspacing="0" style="max-width:720px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">'
        .'<tr><td style="background:#0f172a;padding:22px 26px;"><div style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">'.$esc($propertyName).'</div>'
        .($propertyAddress!==''?'<div style="color:#cbd5e1;font-size:12px;margin-top:4px;">'.$esc($propertyAddress).'</div>':'')
        .'<div style="color:#cbd5e1;font-size:12px;margin-top:2px;">'.trim(($propertyPhone!==''?'Telp: '.$esc($propertyPhone):'').($propertyEmail!==''?' &middot; '.$esc($propertyEmail):''),' &middot;').'</div></td></tr>'
        .'<tr><td style="padding:22px 26px 6px;"><div style="font-size:15px;font-weight:bold;letter-spacing:1px;color:#0f172a;">LAPORAN RESERVASI GRUP / INVOICE KOLEKTIF</div>'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;"><tr>'
        .'<td style="font-size:12px;color:#64748b;">Referensi<br><strong style="color:#0f172a;font-size:13px;">'.$esc($reference).'</strong></td>'
        .'<td style="font-size:12px;color:#64748b;text-align:right;">Tanggal cetak<br><strong style="color:#0f172a;font-size:13px;">'.date('d/m/Y H:i').'</strong></td></tr></table></td></tr>'
        .'<tr><td style="padding:14px 26px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;">'
        .'<tr><td style="padding:12px 14px;width:50%;vertical-align:top;border-right:1px solid #e2e8f0;"><div style="font-size:11px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;">Kepada</div><div style="margin-top:4px;">'.$kepada.'</div></td>'
        .'<td style="padding:12px 14px;width:50%;vertical-align:top;"><div style="font-size:11px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;">Detail Grup</div>'
        .'<div style="font-size:13px;color:#334155;margin-top:4px;">'.$esc((string)($group['name']??'-')).'</div>'
        .'<div style="font-size:12px;color:#64748b;margin-top:2px;">'.$esc((string)($group['arrival_date']??'-')).' &rarr; '.$esc((string)($group['departure_date']??'-')).'</div>'
        .'<div style="font-size:12px;color:#64748b;margin-top:2px;">'.count($bookings).' kamar &middot; Tagihan: '.$esc($billingLabels[strtolower((string)($group['billing_mode']??''))]??(string)($group['billing_mode']??'-')).'</div></td></tr></table></td></tr>'
        .'<tr><td style="padding:18px 26px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;border-collapse:separate;border-spacing:0;">'
        .'<tr><th style="'.$th.'border-radius:10px 0 0 0;">Kamar</th><th style="'.$th.'">Tamu</th><th style="'.$th.'">Check-in</th><th style="'.$th.'">Check-out</th><th style="'.$th.'">Status</th><th style="'.$thr.'">Total</th><th style="'.$thr.'">Dibayar</th><th style="'.$thr.'border-radius:0 10px 0 0;">Sisa</th></tr>'
        .$rows.'</table>'.$summary.'</td></tr>'
        .'<tr><td style="padding:12px 26px 8px;font-size:12px;color:#64748b;">Laporan ini dibuat dari reservasi grup '.$esc($reference).' &middot; Staf: '.$esc($staffName).' &middot; '.date('d/m/Y H:i').'</td></tr>'
        .'<tr><td style="padding:16px 26px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Dokumen resmi dibuat otomatis oleh sistem '.$esc($propertyName).'. Rincian per kamar tersedia pada nota masing-masing.</td></tr>'
        .'</table></td></tr></table></body></html>';
    $subject='Laporan Grup '.$reference.' - '.trim((string)($group['name']??'')).' - '.$propertyName;
    return ['subject'=>$subject,'html'=>$html,'reportReference'=>$reference,'totals'=>$totals];
}

/**
 * Pengiriman email resmi (nota/laporan) dengan audit wajib.
 * SMTP utama, fallback PHP mail() seperti alur laporan canonical; bila keduanya
 * gagal, pemanggil menerima RuntimeException dengan pesan yang bisa ditampilkan.
 */
function tamasyaSendReceiptEmail(PDO $pdo,array $actor,string $to,string $subject,string $html,string $auditAction,string $entityType,string $entityId,array $auditData,string $channel='web'): array {
    $to=strtolower(trim($to));
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Alamat email penerima tidak valid.');
    if(function_exists('tamasyaExternalSideEffectsAllowed')&&!tamasyaExternalSideEffectsAllowed())throw new RuntimeException('Pengiriman email resmi hanya boleh dilakukan dari node Primary yang aktif.');
    $confStmt=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1");
    $conf=$confStmt?($confStmt->fetch(PDO::FETCH_ASSOC)?:[]):[];
    $sent=false;$method='';$smtpError='';
    if(trim((string)($conf['smtp_host']??''))!==''){
        try{$sent=sendSmtpMail($to,$subject,$html,$conf,[]);}catch(Exception $smtpFailure){$sent=false;error_log('[receipt-email] SMTP exception, fallback PHP mail(): '.$smtpFailure->getMessage());}
        if($sent){$method='SMTP';}
        else{
            // Alasan kegagalan SMTP nyata (auth/TLS/sender/DATA) dari jejak terakhir,
            // dipakai audit + pesan agar kegagalan tidak lagi tersembunyi diam-diam.
            $trace=is_array($GLOBALS['tamasya_smtp_last']??null)?$GLOBALS['tamasya_smtp_last']:[];
            $smtpError=trim((string)($trace['error']??''));
            if($smtpError==='')$smtpError='SMTP tidak berhasil tanpa detail (lihat log server).';
        }
    }else{
        $smtpError='Host SMTP belum diisi di Pengaturan.';
    }
    if(!$sent&&function_exists('tamasyaPhpMailWithAttachments')){error_log('[receipt-email] SMTP tidak berhasil (host='.trim((string)($conf['smtp_host']??'')).'), fallback PHP mail() untuk sha256:'.substr(hash('sha256',$to),0,12));$sent=tamasyaPhpMailWithAttachments($to,$subject,$html,[],$conf);if($sent)$method='PHP mail()';}
    if(!$sent)throw new RuntimeException('Email tidak berhasil dikirim. Periksa konfigurasi SMTP/email hosting di Pengaturan.');
    // "Terkirim" hanya terverifikasi bila server SMTP benar-benar menerima pesan.
    // Jalur cadangan PHP mail() hanya berarti MTA lokal menerima titipan — belum tentu terantar.
    $verified=($method==='SMTP');
    $queueId='';
    if($verified){
        $trace=is_array($GLOBALS['tamasya_smtp_last']??null)?$GLOBALS['tamasya_smtp_last']:[];
        $queueId=trim((string)($trace['queueId']??''));
    }
    if($verified){
        $deliveryNote='Status pengiriman: diterima server SMTP'.($queueId!==''?' (antrean '.$queueId.')':'').'.';
    }else{
        $smtpReason=function_exists('tamasyaSmtpSanitizeResponse')?tamasyaSmtpSanitizeResponse($smtpError!==''?$smtpError:'SMTP tidak tersedia.',200):(trim((string)preg_replace('/\s+/',' ',$smtpError))?:'SMTP tidak tersedia.');
        $deliveryNote='PENTING: dikirim lewat cadangan PHP mail() hosting — pengiriman BELUM terverifikasi. SMTP gagal: '.$smtpReason.' Perbaiki di Pengaturan > Konfigurasi Server Email.';
    }
    $masked=function_exists('tamasyaMaskEmailAddress')?tamasyaMaskEmailAddress($to):(preg_replace('/(^.).*(@.*$)/','$1***$2',$to)?:'[email]');
    $auditData['recipientHash']=hash('sha256',$to);
    $auditData['method']=$method;
    $auditData['channel']=$channel;
    $auditData['verified']=$verified;
    if($smtpError!=='')$auditData['smtpError']=function_exists('tamasyaSmtpSanitizeResponse')?tamasyaSmtpSanitizeResponse($smtpError,300):substr($smtpError,0,300);
    if($queueId!=='')$auditData['queueId']=$queueId;
    if(function_exists('writeRequiredEnterpriseAudit')){
        writeRequiredEnterpriseAudit($pdo,$actor,$auditAction,$entityType,$entityId,null,$auditData,$channel);
    }
    return ['masked'=>$masked,'method'=>$method,'verified'=>$verified,'queueId'=>$queueId,'smtpError'=>$smtpError,'deliveryNote'=>$deliveryNote];
}

/** Nota resmi satu booking via email (dipakai web & Telegram). */
function tamasyaSendBookingReceiptEmail(PDO $pdo,array $actor,array $booking,string $email,string $channel='web',string $operationId=''): array {
    $property=tamasyaPropertyProfile($pdo);
    $content=tamasyaBookingReceiptEmailContent($booking,(string)($actor['name']??'System'),$property);
    $entityId='receipt_email_'.substr(hash('sha256',($operationId?:($GLOBALS['tamasya_request_operation_id']??'')).'|'.(string)($booking['id']??'').'|'.strtolower(trim($email))),0,40);
    $result=tamasyaSendReceiptEmail($pdo,$actor,$email,$content['subject'],$content['html'],'Mengirim nota kamar via email','booking',(string)($booking['id']??''),[
        'invoiceNumber'=>$content['invoiceNumber'],
        'idNota'=>$content['idNota'],
        'roomNumber'=>(string)($booking['roomNumber']??''),
        'guestNameHash'=>hash('sha256',(string)($booking['guestName']??'')),
        'totalAmount'=>(float)($booking['totalAmount']??0),
        'paymentStatus'=>(string)($booking['paymentStatus']??''),
        'eventId'=>$entityId,
    ],$channel);
    // Alamat yang berhasil dipakai disimpan ke booking agar kirim berikutnya
    // terisi otomatis (prefill dialog web & tombol "Email tersimpan" Telegram).
    // Kolom bookings.guestEmail varchar(100): alamat lebih panjang tidak dipaksa.
    $persistEmail=strtolower(trim($email));
    if(($booking['id']??'')!==''&&mb_strlen($persistEmail)<=100&&strcasecmp($persistEmail,strtolower(trim((string)($booking['guestEmail']??''))))!==0){
        $pdo->prepare('UPDATE bookings SET guestEmail = ? WHERE id = ?')->execute([$persistEmail,(string)$booking['id']]);
    }
    return $result+['invoiceNumber'=>$content['invoiceNumber']];
}

/** Laporan reservasi grup via email ke perusahaan (invoice kolektif). */
function tamasyaSendGroupReportEmail(PDO $pdo,array $actor,string $groupId,string $email,string $channel='web',string $operationId=''): array {
    if(!function_exists('tamasyaMultiRoomDetail'))throw new RuntimeException('Modul reservasi grup belum aktif pada deployment ini.');
    $groupId=trim($groupId);
    if($groupId==='')throw new InvalidArgumentException('Reservasi grup wajib dipilih.');
    $detail=tamasyaMultiRoomDetail($pdo,$groupId);
    $company=null;
    $companyId=trim((string)($detail['group']['company_id']??''));
    if($companyId!==''){
        $stmt=$pdo->prepare('SELECT id,name,tax_id,billing_email,phone,address FROM growth_companies WHERE id=? LIMIT 1');
        $stmt->execute([$companyId]);
        $company=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $property=tamasyaPropertyProfile($pdo);
    $content=tamasyaGroupReportEmailContent($detail,(string)($actor['name']??'System'),$property,$company);
    $entityId='group_report_email_'.substr(hash('sha256',($operationId?:($GLOBALS['tamasya_request_operation_id']??'')).'|'.$groupId.'|'.strtolower(trim($email))),0,40);
    $result=tamasyaSendReceiptEmail($pdo,$actor,$email,$content['subject'],$content['html'],'Mengirim laporan grup via email','growth_group_reservation',$groupId,[
        'reportReference'=>$content['reportReference'],
        'companyId'=>$companyId?:null,
        'bookingCount'=>count($detail['bookings']??[]),
        'total'=>(float)($detail['totals']['total']??0),
        'paid'=>(float)($detail['totals']['paid']??0),
        'balance'=>(float)($detail['totals']['balance']??0),
        'eventId'=>$entityId,
    ],$channel);
    return $result+['reportReference'=>$content['reportReference']];
}

/** Source line 5291: renderBookingConfirmation */
function renderBookingConfirmation($pdo, $loggedInStaff) {
    $currentCtx = $loggedInStaff['telegram_context'] ?? '';
    $parts = explode(":", $currentCtx);
    
    $roomNumber = $parts[0] ?? '';
    $guestName = str_replace('__COLON__', ':', $parts[1] ?? '');
    $ktpPhotoPath = str_replace('__COLON__', ':', $parts[2] ?? '');
    $nights = isset($parts[3]) ? (int)$parts[3] : 1;
    
    $bookingSource = 'Direct';
    $customPrice = null;
    
    // Parse index 4 dan seterusnya secara dinamis
    $remainingParts = array_slice($parts, 4);
    $i = 0;
    while ($i < count($remainingParts)) {
        if ($remainingParts[$i] === 'splitbank') {
            $i += 2; // skip 'splitbank' and the bankId
        } elseif (is_numeric($remainingParts[$i])) {
            $customPrice = (int)$remainingParts[$i];
            $i++;
        } elseif (!empty($remainingParts[$i])) {
            $bookingSource = $remainingParts[$i];
            $i++;
        } else {
            $i++;
        }
    }
    
    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
    $stmtRoom->execute([$roomNumber]);
    $room = $stmtRoom->fetch();
    
    if (!$room) {
        return [
            "text" => "❌ Kamar nomor {$roomNumber} tidak ditemukan.",
            "markup" => ["inline_keyboard" => [[["text" => "🛒 Jual Kamar", "callback_data" => "sell_room_list"]]]]
        ];
    }
    
    $pricePerNight = (int)$room['price'];
    $vatRate = resolveConfiguredTaxRate($pdo, $bookingSource, 'room', 0, date('Y-m-d'));
    
    if ($customPrice !== null && $customPrice > 0) {
        $totalAmount = $customPrice;
        $taxBreakdown = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
        $baseAmount = (int)round($taxBreakdown['baseAmount']);
        $vatAmount = (int)round($taxBreakdown['taxAmount']);
        $isNego = true;
    } else {
        $baseAmount = $pricePerNight * $nights;
        $vatAmount = $vatRate > 0 ? (int)round($baseAmount * ($vatRate / 100)) : 0;
        $totalAmount = $baseAmount + $vatAmount;
        $isNego = false;
    }
    $taxLine = $vatRate > 0
        ? "• Pajak PBJT ({$vatRate}%): Rp " . number_format($vatAmount, 0, ',', '.') . "\n"
        : "• Pajak PBJT: Rp 0 (sesuai tax_rules aktif)\n";
    
    if ($isNego) {
        $replyText = "💰 *KONFIRMASI SEWA KAMAR (HARGA NEGO) {$roomNumber}*\n\n" .
                     "• Nama Tamu: *{$guestName}*\n" .
                     "• Durasi: *{$nights} Malam*\n" .
                     "• Tarif Kamar Standar: Rp " . number_format($pricePerNight, 0, ',', '.') . "/malam\n" .
                     "• *Subtotal Nego: Rp " . number_format($baseAmount, 0, ',', '.') . "* 🔥\n" .
                     $taxLine .
                     "• *Total Tagihan Nego: Rp " . number_format($totalAmount, 0, ',', '.') . "*\n" .
                     "• Sumber Booking: *" . $bookingSource . "*\n\n" .
                     "Silakan pilih status pembayaran di bawah ini atau ketik `lunas` / `belum lunas`:";
    } else {
        $replyText = "💰 *KONFIRMASI SEWA KAMAR {$roomNumber}*\n\n" .
                     "• Nama Tamu: *{$guestName}*\n" .
                     "• Durasi: *{$nights} Malam*\n" .
                     "• Tarif Kamar: Rp " . number_format($pricePerNight, 0, ',', '.') . "/malam\n" .
                     "• Subtotal Standar: Rp " . number_format($baseAmount, 0, ',', '.') . "\n" .
                     $taxLine .
                     "• *Total Tagihan: Rp " . number_format($totalAmount, 0, ',', '.') . "*\n" .
                     "• Sumber Booking: *" . $bookingSource . "*\n\n" .
                     "✍️ *Ada Harga Nego / Tawar?*\n" .
                     "Jika tamu menawar harga lain, silakan klik tombol *Masukan Harga Nego* di bawah terlebih dahulu untuk menginput nominal kustom sebelum check-in.\n\n" .
                     "Silakan pilih status pembayaran atau klik tombol di bawah:";
    }
    
    $replyMarkup = [
        "inline_keyboard" => [
            [
                ["text" => "🟢 Lunas (Paid)", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid"]
            ],
            [
                ["text" => "🔴 Belum Lunas (Unpaid)", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":unpaid"]
            ],
            [
                ["text" => "✍️ " . ($isNego ? "Ubah Harga Nego Lagi" : "Masukan Harga Nego"), "callback_data" => "r_sell_nego_prompt:" . $roomNumber]
            ],
            [
                ["text" => "🌐 Ubah Sumber Booking (" . $bookingSource . ")", "callback_data" => "r_sell_source_prompt:" . $roomNumber]
            ],
            [
                ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
            ]
        ]
    ];
    
    return [
        "text" => $replyText,
        "markup" => $replyMarkup
    ];
}


/**
 * Batas jam shift yang dapat diatur properti (R14.1).
 *
 * Pagi = [day_start, siang_start), siang = [siang_start, malam_start),
 * malam = [malam_start, day_start) melewati tengah malam. Default mempertahankan
 * perilaku historis (07:00 / 15:00 / 23:00) sehingga baris shift lama tidak
 * berubah arti. Nilai rusak/tidak berurutan gagal-amann ke default, bukan ke
 * slot acak: klasifikasi shift menentukan gerbang Night Audit.
 */
function tamasyaShiftBoundariesMinutes(array $settings): array {
    $parse=static function(string $raw,int $fallback): int {
        if(!preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/',trim($raw),$m))return $fallback;
        $hour=(int)$m[1];$minute=(int)$m[2];
        if($hour>23||$minute>59)return $fallback;
        return $hour*60+$minute;
    };
    $day=$parse((string)($settings['shift_day_start']??'07:00:00'),7*60);
    $siang=$parse((string)($settings['shift_siang_start']??'15:00:00'),15*60);
    $malam=$parse((string)($settings['shift_malam_start']??'23:00:00'),23*60);
    if(!($day<$siang && $siang<$malam)){
        return ['day_start'=>7*60,'siang_start'=>15*60,'malam_start'=>23*60];
    }
    return ['day_start'=>$day,'siang_start'=>$siang,'malam_start'=>$malam];
}

/** Slot shift untuk menit-dalam-hari tertentu menurut batas yang dapat diatur. */
function tamasyaShiftSlotForMinutes(array $settings,int $minuteOfDay): string {
    $b=tamasyaShiftBoundariesMinutes($settings);
    if($minuteOfDay>=$b['malam_start']||$minuteOfDay<$b['day_start'])return 'malam';
    if($minuteOfDay>=$b['siang_start'])return 'siang';
    return 'pagi';
}

/** Slot default menurut jam server properti; dipakai web maupun telegram API. */
function tamasyaDefaultShiftSlotForNow(PDO $pdo,?int $timestamp=null): string {
    $timestamp=$timestamp??time();
    return tamasyaShiftSlotForMinutes(getOperationalSettings($pdo),(int)date('G',(int)$timestamp)*60+(int)date('i',(int)$timestamp));
}

/**
 * Owner adalah pemantau read-only di Telegram: boleh MELIHAT semua menu dan
 * layar baca, tidak boleh MENGUBAH apa pun. HTTP method tidak bisa dipakai
 * sebagai pembeda di sini karena webhook Telegram selalu POST, jadi keputusan
 * diambil dari SEMANTIK callback: hanya callback layar-baca yang dibuka.
 * Fail-closed: callback baru yang belum terdaftar otomatis ditolak owner.
 */
function tamasyaTelegramOwnerReadableCallbacks(): array {
    return ['room_list','sell_room_list','reservation_list','room_payment_status_menu','booking_extend_menu','booking_service_menu','booking_transfer_menu','booking_checkout_menu','panjar_menu','housekeeping_menu','vacancy_menu','patrol_reports_menu','guest_service_menu','guest_identity_menu','cash_shift_menu','laporan_kas_refresh','riwayat_shift_menu','receipt_menu'];
}

function tamasyaTelegramOwnerReadablePrefixes(): array {
    return ['room_list:p:','sell_room_list:p:','reservation_list:p:','reservation_detail:','room_select:','room_payment_status_menu:p:','room_payment_status_filter:','booking_extend_menu:p:','booking_service_menu:p:','booking_transfer_menu:p:','booking_checkout_menu:p:','panjar_menu:p:','housekeeping_menu:p:','hk_room:','vacancy_menu:p:','vacancy_report_room:','gsm:p:','gsd:','guest_identity_view:','riwayat_shift_menu:p:','riwayat_shift_detail:','print_receipt:'];
}

function tamasyaTelegramOwnerCallbackReadable(string $callbackData): bool {
    if (in_array($callbackData,tamasyaTelegramOwnerReadableCallbacks(),true)) return true;
    foreach (tamasyaTelegramOwnerReadablePrefixes() as $prefix) {
        if (str_starts_with($callbackData,$prefix)) return true;
    }
    return false;
}

/** Role yang boleh MEMBACA riwayat tutup shift (bukan menutup shift). */
function tamasyaShiftHistoryReaderRoles(): array {
    return ['admin','owner','manager','finance'];
}

/** Daftar shift yang sudah tutup, terbaru dulu. Murni SELECT. */
function tamasyaClosedShiftHistory(PDO $pdo,int $page=1,int $pageSize=5): array {
    $page=max(1,$page);$pageSize=max(1,min(10,$pageSize));
    $count=(int)$pdo->query("SELECT COUNT(*) FROM shift_sessions WHERE status='closed'")->fetchColumn();
    $totalPages=max(1,(int)ceil(max(1,$count)/$pageSize));
    $page=min($page,$totalPages);$offset=($page-1)*$pageSize;
    $stmt=$pdo->prepare("SELECT id,shift_date,shift_time,staff_name,companion_staff_name,opening_cash,cash_income,cash_expense,expected_cash,actual_cash,variance,closed_at FROM shift_sessions WHERE status='closed' ORDER BY closed_at DESC,id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();
    return ['rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)?:[],'page'=>$page,'totalPages'=>$totalPages,'count'=>$count];
}

/** Rincian satu shift tutup termasuk ringkasan deposito. Murni SELECT. */
function tamasyaClosedShiftDetail(PDO $pdo,string $shiftId): ?array {
    $stmt=$pdo->prepare("SELECT s.id,s.shift_date,s.shift_time,s.staff_id,s.staff_name,s.companion_staff_id,s.companion_staff_name,s.opening_cash,s.cash_income,s.cash_expense,s.expected_cash,s.actual_cash,s.variance,s.close_override_reason,s.notes,s.opened_at,s.closed_at,r.digitalRevenue AS digital_revenue,r.transactionsCount AS transactions_count FROM shift_sessions s LEFT JOIN shift_reports r ON r.shiftSessionId=s.id WHERE s.id=? AND s.status='closed' LIMIT 1");
    $stmt->execute([$shiftId]);$shift=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$shift)return null;
    // Shift lama mungkin belum punya baris shift_reports: hitung ulang
    // non-tunai dari transaksi yang terkunci pada sesi ini (murni SELECT).
    if($shift['digital_revenue']===null||$shift['transactions_count']===null){
        try{
            $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE shiftSessionId=?");
            $txStmt->execute([$shiftId]);$txRows=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
            $totals=tamasyaShiftSettlementTotals($txRows);
            if($shift['digital_revenue']===null)$shift['digital_revenue']=$totals['digitalInc'];
            if($shift['transactions_count']===null)$shift['transactions_count']=$totals['txCount'];
        }catch(Throwable $ignored){
            if($shift['digital_revenue']===null)$shift['digital_revenue']=0;
            if($shift['transactions_count']===null)$shift['transactions_count']=0;
        }
    }
    try{
        $dep=$pdo->prepare("SELECT entry_type,SUM(amount) AS total,COUNT(*) AS n FROM guest_security_deposit_ledger WHERE shift_session_id=? GROUP BY entry_type");
        $dep->execute([$shiftId]);$deposits=$dep->fetchAll(PDO::FETCH_ASSOC)?:[];
    }catch(Throwable $ignored){$deposits=[];}
    $shift['depositSummary']=$deposits;
    return $shift;
}
