<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'attendance',
  1 => 'salary-slips',
  2 => 'staff-savings',
  3 => 'inventory',
  4 => 'inventory-maintenance',
  5 => 'salary-payment-correction',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'attendance':
        $method = $_SERVER['REQUEST_METHOD'];
        
        if ($method === 'GET') {
            try {
                if (in_array($loggedInStaff['role'] ?? '', ['admin','manager','owner'], true)) {
                    $stmt = $pdo->query("SELECT id, staff_id AS staffId, staff_name AS staffName, date, clock_in AS clockIn, clock_out AS clockOut, method, location, status, verification_id AS verificationId, device_id AS deviceId, verification_score AS verificationScore, verified_at AS verifiedAt, clock_out_verification_id AS clockOutVerificationId, clock_out_verified_at AS clockOutVerifiedAt, clock_out_source_event_id AS clockOutSourceEventId, clock_out_location AS clockOutLocation, notes, created_at AS createdAt FROM attendance ORDER BY date DESC, clock_in DESC");
                } else {
                    $stmt = $pdo->prepare("SELECT id, staff_id AS staffId, staff_name AS staffName, date, clock_in AS clockIn, clock_out AS clockOut, method, location, status, verification_id AS verificationId, device_id AS deviceId, verification_score AS verificationScore, verified_at AS verifiedAt, clock_out_verification_id AS clockOutVerificationId, clock_out_verified_at AS clockOutVerifiedAt, clock_out_source_event_id AS clockOutSourceEventId, clock_out_location AS clockOutLocation, notes, created_at AS createdAt FROM attendance WHERE staff_id = ? ORDER BY date DESC, clock_in DESC");
                    $stmt->execute([$loggedInStaff['id'] ?? '']);
                }
                $rows = $stmt->fetchAll() ?: [];
                echo json_encode($rows);
            } catch (Exception $e) {
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Operasi gagal", $e)]);
            }
        } elseif ($method === 'POST') {
            $staffId = $input['staffId'] ?? '';
            $staffName = $input['staffName'] ?? '';
            $date = $input['date'] ?? date('Y-m-d');
            $clockIn = $input['clockIn'] ?? date('H:i:s');
            $clockOut = $input['clockOut'] ?? null;
            $method_type = $input['method'] ?? 'manual';
            $location = $input['location'] ?? null;
            $status = $input['status'] ?? 'present';
            $biometricVerificationId = trim((string)($input['biometricVerificationId'] ?? ''));
            $notes = trim((string)($input['notes'] ?? ''));

            if (empty($staffId) || empty($staffName)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "ID dan nama staf wajib diisi!"]);
                break;
            }
            $canManageAttendance = in_array($loggedInStaff['role'] ?? '', ['admin','manager'], true);
            if (!$canManageAttendance && (string)$staffId !== (string)($loggedInStaff['id'] ?? '')) {
                http_response_code(403);
                echo json_encode(["success" => false, "error" => "Anda hanya dapat mencatat absensi untuk akun sendiri."]);
                break;
            }
            if (!$canManageAttendance) {
                $staffId = (string)$loggedInStaff['id'];
                $staffName = (string)$loggedInStaff['name'];
            }
            if (!validIsoDate((string)$date)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Tanggal absensi tidak valid."]);
                break;
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',(string)$clockIn)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Jam masuk tidak valid."]);
                break;
            }
            if (!in_array($method_type,['fingerprint','face','manual'],true)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Metode absensi tidak valid."]);
                break;
            }
            if ($method_type === 'manual' && $clockOut !== null && $clockOut !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',(string)$clockOut)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Jam pulang manual tidak valid."]);
                break;
            }
            if ($method_type === 'manual' && !$canManageAttendance) {
                http_response_code(403);
                echo json_encode(["success"=>false,"error"=>"Absensi manual hanya dapat dibuat oleh Admin atau Manager."]);
                break;
            }
            if ($method_type === 'manual' && $notes === '') {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Absensi manual wajib memiliki alasan/catatan."]);
                break;
            }
            if ($method_type !== 'manual' && $biometricVerificationId === '') {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Bukti verifikasi biometrik wajib disertakan."]);
                break;
            }
            if (!in_array($status,['present','late','absent','leave'],true)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Status absensi tidak valid."]);
                break;
            }
            try {
                // GPS tetap backward-compatible: default tidak wajib. Jika koordinat dikirim,
                // server menormalisasi bukti lokasi dan dapat menegakkan accuracy/geofence via ENV.
                $locationEvidence=tamasyaAttendanceValidateLocationEvidence($input,(string)$method_type);
                $location=(string)$locationEvidence['location'];
            } catch (Throwable $e) {
                tamasyaApplyExceptionHttpStatus($e,422);
                echo json_encode(["success"=>false,"error"=>clientExceptionMessage("Lokasi absensi tidak valid",$e)]);
                break;
            }

            try {
                $pdo->beginTransaction();
                $staffStmt=$pdo->prepare("SELECT id,name,status FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                $staffStmt->execute([$staffId]);
                $staffRow=$staffStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$staffRow||($staffRow['status']??'')!=='active')throw new RuntimeException('Akun staf aktif tidak ditemukan.');
                $staffName=(string)$staffRow['name'];
                // Bukti biometrik membuktikan kehadiran saat ini. Tanggal/jam browser
                // tidak boleh dipakai untuk membuat absensi biometrik historis.
                if ($method_type !== 'manual') {
                    $date = date('Y-m-d');
                    $clockIn = date('H:i:s');
                    // Clock-in biometrik hanya boleh membuka record. Jam pulang wajib
                    // melalui PUT + bukti biometrik baru agar tidak dapat ditanamkan
                    // diam-diam pada request clock-in direct API.
                    $clockOut = null;
                    $lateAfter = trim((string)(getenv('ATTENDANCE_LATE_AFTER') ?: '08:30'));
                    $status = (preg_match('/^\d{2}:\d{2}$/', $lateAfter) && substr($clockIn,0,5) > $lateAfter) ? 'late' : 'present';
                }
                // Prevent duplicate clock-in for the same day
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE staff_id = ? AND date = ?");
                $stmtCheck->execute([$staffId, $date]);
                if ($stmtCheck->fetchColumn() > 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Staf sudah melakukan absensi masuk hari ini!"]);
                    break;
                }

                $verification = null;
                if ($method_type !== 'manual') {
                    $bioSchema=tamasyaBiometricSchemaStatus($pdo);
                    if(empty($bioSchema['ready'])) throw new RuntimeException('Schema absensi biometrik tidak lengkap untuk baseline fresh V137. Verifikasi database dengan database_verify.php; jangan lakukan auto-patch dari aplikasi.');
                    $verification = tamasyaBiometricConsumeVerification($pdo,$biometricVerificationId,(string)$staffId,(string)$method_type);
                }
                $id = generateServerId('att');
                $stmt = $pdo->prepare("INSERT INTO attendance
                    (id,staff_id,staff_name,date,clock_in,clock_out,method,location,status,fingerprint_token,face_data_url,notes,verification_id,device_id,verification_score,evidence_hash,verified_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $id,$staffId,$staffName,$date,$clockIn,$clockOut,$method_type,$location,$status,
                    null,null,$notes,
                    $verification['id']??null,$verification['device_id']??null,$verification['score']??null,$verification['evidence_hash']??null,$verification['verified_at']??null
                ]);

                // Notifikasi sistem
                $notifId = generateServerId('n_att');
                $notifMsg = "Absensi masuk: $staffName ($method_type) pada tanggal $date jam $clockIn.";
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')");
                $stmtNotif->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);

                $afterStmt=$pdo->prepare("SELECT * FROM attendance WHERE id=? LIMIT 1");
                $afterStmt->execute([$id]);
                $after=tamasyaAuditAttemptSnapshot($afterStmt->fetch(PDO::FETCH_ASSOC)?:[]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat absensi masuk','attendance',$id,null,$after,'web');
                logActivity($pdo, "clock_in", "Absensi masuk: $staffName ($method_type)", $staffId, $staffName);
                tamasyaFinancialCommit($pdo);

                // Kirim notifikasi Telegram ke grup/channel dan ke Telegram Bot user privat jika ada
                try {
                    // Identitas privat selalu dibaca dari binding terverifikasi.
                    $userChatId = resolveTelegramUserIdForStaff($pdo,$staffId);

                    $stmtConf = $pdo->query("SELECT telegram_bot_token FROM config WHERE id = 'system_default' LIMIT 1");
                    $confRow = $stmtConf->fetch();
                    $token = $confRow ? trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? '')) : '';

                    $tgMessage = "⏰ *ABSEN MASUK BERHASIL*\n\n" .
                                 "Halo *{$staffName}*,\n" .
                                 "Absensi masuk Anda telah berhasil dicatat pada sistem.\n\n" .
                                 "📅 *Tanggal*: `{$date}`\n" .
                                 "⏰ *Jam Masuk*: `{$clockIn}`\n" .
                                 "⚡ *Metode*: `" . strtoupper($method_type) . "`\n" .
                                 "📍 *Lokasi*: " . ($location ? "`{$location}`" : "`Kantor Utama`") . "\n" .
                                 "📝 *Catatan*: " . ($notes ? "`{$notes}`" : "`Tidak ada`") . "\n\n" .
                                 "Selamat bekerja dan tetap semangat! 💪";

                    // 1. Kirim ke bot chat privat milik staf (jika telegram_chat_id terdaftar)
                    if (!empty($token) && !empty($userChatId)) {
                        // tamasyaTelegramApiCallWithFormatRecovery instead of a raw
                        // sendHttpPost: it short-circuits in simulation, honours
                        // TELEGRAM_API_BASE_URL, and retries once without parse_mode when
                        // Telegram rejects the entities. The raw call returned false on
                        // failure and the result was discarded, so a note or staff name
                        // containing a backtick silently lost the clock-in confirmation.
                        $clockInTelegram = tamasyaTelegramApiCallWithFormatRecovery($token, 'sendMessage', [
                            "chat_id" => $userChatId,
                            "text" => $tgMessage,
                            "parse_mode" => "Markdown"
                        ]);
                        if (empty($clockInTelegram['ok'])) {
                            error_log('[Telegram Attendance In] deliver gagal: '.telegramApiErrorMessage($clockInTelegram));
                        }
                    }

                    // 2. Broadcast ke grup/channel yang terdaftar (jika ada)
                    broadcastTelegramNotification($pdo, "⏰ *Absen Masuk Staf*\n\nStaf *{$staffName}* telah melakukan absensi masuk via *" . strtoupper($method_type) . "* pada tanggal `{$date}` jam `{$clockIn}`.");
                } catch (Exception $tgErr) {
                    // Jangan menghalangi proses respon utama jika telegram bermasalah
                }

                echo json_encode([
                    "success" => true,
                    "message" => "Absensi masuk berhasil dicatat!",
                    "attendanceId" => $id,
                    "method" => $method_type,
                    "verificationId" => $verification['id'] ?? null,
                    "locationEvidence" => [
                        "hasGps" => (bool)($locationEvidence['hasGps'] ?? false),
                        "accuracy" => $locationEvidence['accuracy'] ?? null,
                        "distanceMeters" => $locationEvidence['distanceMeters'] ?? null,
                        "insideGeofence" => $locationEvidence['insideGeofence'] ?? null,
                    ],
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal mencatatkan absensi", $e)]);
            }
        } elseif ($method === 'PUT') {
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "ID Absensi wajib disertakan!"]);
                break;
            }

            $clockOutProvided = array_key_exists('clockOut',$input);
            $clockOut = $input['clockOut'] ?? null;
            $status = $input['status'] ?? null;
            $notes = $input['notes'] ?? null;
            $location = $input['location'] ?? null;
            $clockOutVerificationId = trim((string)($input['biometricVerificationId'] ?? ''));
            $clockOutMethod = strtolower(trim((string)($input['method'] ?? '')));
            if ($clockOut !== null && $clockOut !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',(string)$clockOut)) {
                http_response_code(422); echo json_encode(["success"=>false,"error"=>"Jam keluar tidak valid."]); break;
            }
            if ($status !== null && !in_array($status,['present','late','absent','leave'],true)) {
                http_response_code(422); echo json_encode(["success"=>false,"error"=>"Status absensi tidak valid."]); break;
            }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $att = $stmt->fetch();
                if (!$att) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "error" => "Data absensi tidak ditemukan!"]);
                    $pdo->rollBack();
                    break;
                }
                $canManageAttendance = in_array($loggedInStaff['role'] ?? '', ['admin','manager'], true);
                if (!$canManageAttendance && (string)$att['staff_id'] !== (string)($loggedInStaff['id'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(["success" => false, "error" => "Anda hanya dapat memperbarui absensi sendiri."]);
                    $pdo->rollBack();
                    break;
                }

                $existingClockOut=trim((string)($att['clock_out'] ?? ''));
                $clockOutChanged = $clockOutProvided && trim((string)($clockOut ?? '')) !== $existingClockOut;
                $clockOutVerification = null;
                $clockOutLocationEvidence = null;

                if (!$canManageAttendance) {
                    // State staf bersifat satu arah: record terbuka hanya dapat ditutup satu kali.
                    // Koreksi/rollback historis wajib melalui Admin/Manager agar audit trail tidak dapat dimanipulasi via direct API.
                    $allowedKeys=['clockOut','method','biometricVerificationId','notes','location','latitude','longitude','locationAccuracy','locationCapturedAt','id','operationId'];
                    $unexpected=array_values(array_diff(array_keys((array)$input),$allowedKeys));
                    if($unexpected){
                        throw new RuntimeException('Staf hanya dapat melakukan clock-out pada absensi sendiri; perubahan field lain wajib melalui Admin/Manager.');
                    }
                    if (!$clockOutProvided || trim((string)$clockOut)==='') {
                        throw new RuntimeException('Jam pulang tidak boleh dikosongkan oleh staf.');
                    }
                    if ($existingClockOut!=='') {
                        throw new RuntimeException('Absensi ini sudah memiliki jam pulang. Koreksi hanya dapat dilakukan Admin/Manager.');
                    }
                    // Browser clock-out normal hanya untuk shift hari ini/kemarin. Record lebih lama harus dikoreksi secara administratif.
                    $allowedDates=[date('Y-m-d'),date('Y-m-d',strtotime('-1 day'))];
                    if(!in_array((string)($att['date']??''),$allowedDates,true)){
                        throw new RuntimeException('Absensi lama tidak dapat di-clock-out langsung. Minta Admin/Manager melakukan koreksi dengan alasan audit.');
                    }
                    if (!in_array($clockOutMethod,['fingerprint','face'],true) || $clockOutVerificationId==='') {
                        throw new RuntimeException('Verifikasi fingerprint atau wajah wajib dilakukan sebelum absensi pulang.');
                    }
                    $clockOutVerification=tamasyaBiometricConsumeVerification($pdo,$clockOutVerificationId,(string)$att['staff_id'],$clockOutMethod);
                    $clockOutLocationEvidence=tamasyaAttendanceValidateLocationEvidence($input,$clockOutMethod);
                    // Jam biometrik selalu berasal dari server.
                    $clockOut=date('H:i:s');
                } else {
                    // Semua koreksi manual Admin/Manager wajib memiliki alasan. Ini mencakup perubahan status,
                    // lokasi, penghapusan clock-out, dan perubahan clock-out historis.
                    $adminMetadataChange = $status !== null || $location !== null;
                    // Status/lokasi adalah koreksi administratif, bukan sesuatu yang
                    // dibuktikan oleh verificationId clock-out. Wajib manual+alasan;
                    // jangan menerima ID biometrik sembarang sebagai bypass alasan.
                    if ($adminMetadataChange && ($clockOutMethod !== 'manual' || trim((string)$notes) === '')) {
                        throw new RuntimeException('Koreksi status/lokasi absensi wajib override manual Admin/Manager dan memiliki alasan.');
                    }
                    if ($clockOutChanged && !($clockOutVerificationId!=='' && in_array($clockOutMethod,['fingerprint','face'],true))) {
                        if ($clockOutMethod !== 'manual' || trim((string)$notes) === '') {
                            throw new RuntimeException('Koreksi jam pulang tanpa bukti biometrik hanya boleh sebagai override manual Admin/Manager dan wajib memiliki alasan.');
                        }
                    }
                    if ($clockOutChanged && trim((string)$clockOut)!=='' && $clockOutVerificationId!=='' && in_array($clockOutMethod,['fingerprint','face'],true)) {
                        $clockOutVerification=tamasyaBiometricConsumeVerification($pdo,$clockOutVerificationId,(string)$att['staff_id'],$clockOutMethod);
                        $clockOutLocationEvidence=tamasyaAttendanceValidateLocationEvidence($input,$clockOutMethod);
                        $clockOut=date('H:i:s');
                    }
                }

                $fields = [];
                $params = [];
                if ($clockOutProvided) {
                    $fields[] = "clock_out = ?"; $params[] = ($clockOut===null||$clockOut==='')?null:$clockOut;
                    if ($clockOutVerification) {
                        $fields[] = "clock_out_verification_id = ?"; $params[] = $clockOutVerification['id'];
                        $fields[] = "clock_out_device_id = ?"; $params[] = $clockOutVerification['device_id'] ?? null;
                        $fields[] = "clock_out_verification_score = ?"; $params[] = $clockOutVerification['score'] ?? null;
                        $fields[] = "clock_out_verified_at = ?"; $params[] = $clockOutVerification['verified_at'] ?? date('Y-m-d H:i:s');
                        $fields[] = "clock_out_location = ?"; $params[] = (string)($clockOutLocationEvidence['location'] ?? 'Lokasi tidak terdeteksi');
                    } elseif ($canManageAttendance && $clockOutChanged) {
                        // Jika Admin/Manager mengubah/menghapus jam pulang secara manual, jangan biarkan bukti biometrik lama
                        // seolah-olah masih membuktikan nilai clock_out yang telah dikoreksi.
                        $fields[] = "clock_out_verification_id = NULL";
                        $fields[] = "clock_out_device_id = NULL";
                        $fields[] = "clock_out_verification_score = NULL";
                        $fields[] = "clock_out_verified_at = NULL";
                        $fields[] = "clock_out_location = NULL";
                    }
                }
                if ($status !== null) { $fields[] = "status = ?"; $params[] = $status; }
                if ($notes !== null) {
                    if (!$canManageAttendance) {
                        // Clock-out staf tidak boleh menghapus/mengganti catatan yang
                        // sudah ada pada clock-in atau koreksi admin. Tambahkan sebagai
                        // catatan lanjutan dan batasi ukuran payload.
                        $staffClockOutNote=trim((string)$notes);
                        if ($staffClockOutNote!=='') {
                            $existingNotes=trim((string)($att['notes']??''));
                            $combinedNotes=$existingNotes==='' ? $staffClockOutNote : ($existingNotes."\n[Clock-out staf] ".$staffClockOutNote);
                            $fields[] = "notes = ?"; $params[] = substr($combinedNotes,0,2000);
                        }
                    } else {
                        $fields[] = "notes = ?"; $params[] = substr(trim((string)$notes),0,2000);
                    }
                }
                // location adalah bukti clock-in/field koreksi manual. Clock-out biometrik memakai clock_out_location terpisah.
                if ($location !== null && $canManageAttendance && !$clockOutVerification) { $fields[] = "location = ?"; $params[] = trim((string)$location); }

                if (empty($fields)) {
                    throw new RuntimeException('Tidak ada perubahan absensi yang valid untuk disimpan.');
                }
                $params[] = $id;
                $sql = "UPDATE attendance SET " . implode(", ", $fields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $afterStmt=$pdo->prepare("SELECT * FROM attendance WHERE id=? LIMIT 1");
                $afterStmt->execute([$id]);
                $after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                writeRequiredEnterpriseAudit(
                    $pdo,$loggedInStaff,'Memperbarui absensi','attendance',$id,
                    tamasyaAuditAttemptSnapshot((array)$att),
                    tamasyaAuditAttemptSnapshot($after),
                    'web'
                );

                if ($clockOutProvided && !empty($clockOut)) {
                    logActivity($pdo, "clock_out", "Absensi pulang: " . $att['staff_name'] . " pada jam " . $clockOut, $att['staff_id'], $att['staff_name']);
                } else {
                    logActivity($pdo, "update_attendance", "Memperbarui data absensi: " . $att['staff_name'], $att['staff_id'], $att['staff_name']);
                }
                tamasyaFinancialCommit($pdo);

                if ($clockOutProvided && !empty($clockOut)) {
                    try {
                        $userChatId = resolveTelegramUserIdForStaff($pdo,$att['staff_id']);
                        $stmtConf = $pdo->query("SELECT telegram_bot_token FROM config WHERE id = 'system_default' LIMIT 1");
                        $confRow = $stmtConf->fetch();
                        $token = $confRow ? trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? '')) : '';
                        $effectiveClockOutLocation=(string)($clockOutLocationEvidence['location'] ?? ($att['clock_out_location'] ?? $att['location'] ?? 'Kantor Utama'));
                        $tgMessage = "⏰ *ABSEN KELUAR (PULANG) BERHASIL*\n\n" .
                                     "Halo *{$att['staff_name']}*,\n" .
                                     "Absensi keluar Anda telah berhasil dicatat pada sistem.\n\n" .
                                     "📅 *Tanggal*: `{$att['date']}`\n" .
                                     "⏰ *Jam Masuk*: `{$att['clock_in']}`\n" .
                                     "⏰ *Jam Keluar*: `{$clockOut}`\n" .
                                     "📍 *Lokasi Pulang*: `{$effectiveClockOutLocation}`\n" .
                                     "📝 *Catatan*: " . ($notes ? "`{$notes}`" : ($att['notes'] ? "`{$att['notes']}`" : "`Tidak ada`")) . "\n\n" .
                                     "Hati-hati di jalan saat pulang dan selamat beristirahat! 🏠✨";
                        if (!empty($token) && !empty($userChatId)) {
                            $clockOutTelegram = tamasyaTelegramApiCallWithFormatRecovery($token, 'sendMessage', ["chat_id" => $userChatId,"text" => $tgMessage,"parse_mode" => "Markdown"]);
                            if (empty($clockOutTelegram['ok'])) {
                                error_log('[Telegram Attendance Out] deliver gagal: '.telegramApiErrorMessage($clockOutTelegram));
                            }
                        }
                        broadcastTelegramNotification($pdo, "⏰ *Absen Keluar Staf*\n\nStaf *{$att['staff_name']}* telah melakukan absensi keluar pada tanggal `{$att['date']}` jam `{$clockOut}`.");
                    } catch (Exception $tgErr) {
                        // Side effect Telegram tidak boleh membatalkan commit absensi.
                    }
                }

                echo json_encode([
                    "success" => true,
                    "message" => "Absensi berhasil diperbarui!",
                    "clockOut" => $clockOut,
                    "clockOutVerificationId" => $clockOutVerification['id'] ?? null,
                    "clockOutLocationEvidence" => $clockOutLocationEvidence ? [
                        "hasGps" => (bool)($clockOutLocationEvidence['hasGps'] ?? false),
                        "accuracy" => $clockOutLocationEvidence['accuracy'] ?? null,
                        "distanceMeters" => $clockOutLocationEvidence['distanceMeters'] ?? null,
                        "insideGeofence" => $clockOutLocationEvidence['insideGeofence'] ?? null,
                    ] : null,
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal memperbarui absensi", $e)]);
            }
        } elseif ($method === 'DELETE' || (isset($_GET['delete_id']) && $method === 'POST')) {
            requireRoles($loggedInStaff, ['admin','manager']);
            $id = $_GET['id'] ?? $_GET['delete_id'] ?? $input['id'] ?? '';
            if(trim((string)$id)===''){http_response_code(400);echo json_encode(["success"=>false,"error"=>"ID absensi wajib diisi."]);break;}
            $deleteReason=trim((string)($input['reason'] ?? $_GET['reason'] ?? ''));
            if(strlen($deleteReason)<8){http_response_code(422);echo json_encode(["success"=>false,"error"=>"Penghapusan absensi wajib memiliki alasan minimal 8 karakter."]);break;}
            try {
                $pdo->beginTransaction();
                $stmtSelect = $pdo->prepare("SELECT * FROM attendance WHERE id = ? FOR UPDATE");
                $stmtSelect->execute([$id]);
                $row = $stmtSelect->fetch();
                if(!$row){$pdo->rollBack();http_response_code(404);echo json_encode(["success"=>false,"error"=>"Data absensi tidak ditemukan."]);break;}
                $staffName = $row['staff_name'];
                $date = $row['date'];

                $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
                $stmt->execute([$id]);

                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus absensi — alasan: '.substr($deleteReason,0,500),'attendance',(string)$id,tamasyaAuditAttemptSnapshot((array)$row),null,'web');
                logActivity($pdo, "delete_attendance", "Menghapus data absensi: $staffName tanggal $date — alasan: ".substr($deleteReason,0,500));
                tamasyaFinancialCommit($pdo);

                echo json_encode([
                    "success" => true,
                    "message" => "Data absensi berhasil dihapus!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal menghapus absensi", $e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/DELETE /api/salary-slips
    // Manajemen Slip Gaji Karyawan & Integrasi Telegram Bot
    // ----------------------------------------------------------------
    case 'salary-slips':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            try {
                $stmt = $pdo->query("SELECT id, staff_id AS staffId, staff_name AS staffName, period,
                    basic_salary AS basicSalary, allowances, deductions, bonus, net_salary AS netSalary,
                    notes, detailed_allowances AS detailedAllowances, detailed_deductions AS detailedDeductions,
                    status, payment_method AS paymentMethod, bank_account_id AS bankAccountId,
                    paid_at AS paidAt, cancelled_at AS cancelledAt, cancelled_by AS cancelledBy,
                    cancellation_reason AS cancellationReason, reversal_transaction_id AS reversalTransactionId,
                    correction_of_slip_id AS correctionOfSlipId, corrected_by_slip_id AS correctedBySlipId,
                    correction_reason AS correctionReason, created_at AS createdAt, updated_at AS updatedAt
                    FROM salary_slips ORDER BY created_at DESC");
                $rows = $stmt->fetchAll() ?: [];
                foreach ($rows as &$slip) {
                    foreach (['basicSalary','allowances','deductions','bonus','netSalary'] as $moneyField) {
                        $slip[$moneyField] = (float)($slip[$moneyField] ?? 0);
                    }
                    $slip['detailedAllowances'] = !empty($slip['detailedAllowances']) ? (json_decode($slip['detailedAllowances'], true) ?: []) : [];
                    $slip['detailedDeductions'] = !empty($slip['detailedDeductions']) ? (json_decode($slip['detailedDeductions'], true) ?: []) : [];
                }
                echo json_encode($rows);
            } catch (Throwable $e) {
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Operasi gagal", $e)]);
            }
        } elseif ($method === 'POST') {
            $id = trim((string)($input['id'] ?? '')) ?: generateServerId('slip');
            $staffId = trim((string)($input['staffId'] ?? ''));
            $period = trim((string)($input['period'] ?? ''));
            $basicSalary = round((float)($input['basicSalary'] ?? 0), 2);
            $bonus = round((float)($input['bonus'] ?? 0), 2);
            $notes = trim((string)($input['notes'] ?? ''));
            $status = strtolower(trim((string)($input['status'] ?? 'draft')));
            $sendTelegram = !empty($input['sendTelegram']);
            $paymentMethod = strtolower(trim((string)($input['paymentMethod'] ?? '')));
            $bankAccountId = trim((string)($input['bankAccountId'] ?? '')) ?: null;

            $normalizeComponents = static function($raw, $label) {
                if (!is_array($raw)) return [];
                $normalized = [];
                foreach ($raw as $item) {
                    if (!is_array($item)) continue;
                    $name = trim((string)($item['name'] ?? '')) ?: $label;
                    $amount = round((float)($item['amount'] ?? 0), 2);
                    if ($amount < 0) throw new InvalidArgumentException($label . ' tidak boleh bernilai negatif.');
                    $normalized[] = ['name'=>$name, 'amount'=>$amount];
                }
                return $normalized;
            };

            try {
                $detailAllowanceRows = $normalizeComponents($input['detailedAllowances'] ?? [], 'Tunjangan');
                $detailDeductionRows = $normalizeComponents($input['detailedDeductions'] ?? [], 'Potongan');
            } catch (InvalidArgumentException $e) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Komponen gaji tidak valid',$e)]);
                break;
            }
            $allowances = count($detailAllowanceRows) > 0
                ? round(array_sum(array_column($detailAllowanceRows, 'amount')), 2)
                : round((float)($input['allowances'] ?? 0), 2);
            $deductions = count($detailDeductionRows) > 0
                ? round(array_sum(array_column($detailDeductionRows, 'amount')), 2)
                : round((float)($input['deductions'] ?? 0), 2);
            $netSalary = round($basicSalary + $allowances + $bonus - $deductions, 2);
            $detailedAllowances = json_encode($detailAllowanceRows, JSON_UNESCAPED_UNICODE);
            $detailedDeductions = json_encode($detailDeductionRows, JSON_UNESCAPED_UNICODE);

            if ($staffId === '' || $period === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "ID Karyawan dan Periode wajib diisi!"]);
                break;
            }
            if (!in_array($status, ['draft','sent','printed','paid'], true)) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>'Status slip gaji tidak valid.']);
                break;
            }
            if ($basicSalary < 0 || $bonus < 0 || $allowances < 0 || $deductions < 0 || $netSalary < 0) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>'Komponen gaji tidak valid atau menghasilkan gaji bersih negatif.']);
                break;
            }
            if ($status === 'paid' && $netSalary <= 0) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>'Slip berstatus dibayar wajib mempunyai gaji bersih lebih dari nol. Simpan sebagai draft bila hanya untuk pengujian komponen.']);
                break;
            }
            if (isset($input['netSalary']) && !moneyMatches((float)$input['netSalary'], $netSalary, 0.01)) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>'Gaji bersih dari browser tidak sesuai perhitungan server.']);
                break;
            }
            if ($status === 'paid') {
                if (!in_array($paymentMethod, ['cash','transfer','qris'], true)) {
                    http_response_code(422);
                    echo json_encode(['success'=>false,'error'=>'Metode pembayaran gaji wajib tunai, transfer, atau QRIS.']);
                    break;
                }
                if (in_array($paymentMethod, ['transfer','qris'], true) && !$bankAccountId) {
                    http_response_code(422);
                    echo json_encode(['success'=>false,'error'=>'Akun bank/QRIS pembayaran gaji wajib dipilih.']);
                    break;
                }
            } else {
                $paymentMethod = '';
                $bankAccountId = null;
            }

            try {
                $pdo->beginTransaction();
                if($status==='paid'){
                    if($paymentMethod==='cash')$bankAccountId=null;
                    else $bankAccountId=tamasyaSecurityDepositPaymentAccount($pdo,$paymentMethod,(string)$bankAccountId);
                }
                $existingStmt = $pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1 FOR UPDATE");
                $existingStmt->execute([$id]);
                $existingSlip = $existingStmt->fetch();
                if ($existingSlip && strtolower((string)$existingSlip['status']) === 'paid') {
                    if ($status !== 'paid') {
                        http_response_code(409);
                        throw new RuntimeException('Slip yang sudah dibayar tidak boleh dikembalikan menjadi draft/sent/printed. Gunakan koreksi transaksi pembalik yang diaudit.');
                    }
                    $existingAllowanceJson = json_encode(json_decode((string)($existingSlip['detailed_allowances'] ?? '[]'), true) ?: [], JSON_UNESCAPED_UNICODE);
                    $existingDeductionJson = json_encode(json_decode((string)($existingSlip['detailed_deductions'] ?? '[]'), true) ?: [], JSON_UNESCAPED_UNICODE);
                    $existingBank = trim((string)($existingSlip['bank_account_id'] ?? ''));
                    $incomingBank = trim((string)($bankAccountId ?? ''));
                    $paidChanged =
                        (string)($existingSlip['staff_id'] ?? '') !== $staffId ||
                        (string)($existingSlip['period'] ?? '') !== $period ||
                        !moneyMatches((float)($existingSlip['basic_salary'] ?? 0), $basicSalary, 0.01) ||
                        !moneyMatches((float)($existingSlip['allowances'] ?? 0), $allowances, 0.01) ||
                        !moneyMatches((float)($existingSlip['deductions'] ?? 0), $deductions, 0.01) ||
                        !moneyMatches((float)($existingSlip['bonus'] ?? 0), $bonus, 0.01) ||
                        !moneyMatches((float)($existingSlip['net_salary'] ?? 0), $netSalary, 0.01) ||
                        trim((string)($existingSlip['notes'] ?? '')) !== $notes ||
                        $existingAllowanceJson !== $detailedAllowances ||
                        $existingDeductionJson !== $detailedDeductions ||
                        strtolower(trim((string)($existingSlip['payment_method'] ?? ''))) !== $paymentMethod ||
                        $existingBank !== $incomingBank;
                    if ($paidChanged) {
                        http_response_code(409);
                        throw new RuntimeException('Slip gaji yang sudah dibayar bersifat final dan tidak boleh diubah. Buat koreksi transaksi pembalik yang diaudit.');
                    }
                }

                // Nama dan Telegram selalu diambil dari akun server, bukan dari payload browser.
                $stmtStaff = $pdo->prepare("SELECT s.id,s.name,s.role,
                    COALESCE((SELECT tb.telegram_user_id FROM telegram_bindings tb
                              WHERE tb.staff_id=s.id AND tb.status='active'
                              ORDER BY tb.verified_at DESC LIMIT 1), s.telegram_chat_id) AS telegram_chat_id
                    FROM staff s WHERE s.id=? AND s.status='active' LIMIT 1 FOR UPDATE");
                $stmtStaff->execute([$staffId]);
                $staffObj = $stmtStaff->fetch();
                if (!$staffObj) {
                    http_response_code(404);
                    throw new RuntimeException('Karyawan aktif tidak ditemukan.');
                }
                $staffName = (string)$staffObj['name'];

                $paidAtSql = $status === 'paid' ? 'COALESCE(paid_at,CURRENT_TIMESTAMP)' : 'NULL';
                $stmt = $pdo->prepare("INSERT INTO salary_slips
                    (id,staff_id,staff_name,period,basic_salary,allowances,deductions,bonus,net_salary,notes,
                     detailed_allowances,detailed_deductions,status,payment_method,bank_account_id,paid_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?," . ($status === 'paid' ? 'CURRENT_TIMESTAMP' : 'NULL') . ")
                    ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id),staff_name=VALUES(staff_name),period=VALUES(period),
                        basic_salary=VALUES(basic_salary),allowances=VALUES(allowances),deductions=VALUES(deductions),
                        bonus=VALUES(bonus),net_salary=VALUES(net_salary),notes=VALUES(notes),
                        detailed_allowances=VALUES(detailed_allowances),detailed_deductions=VALUES(detailed_deductions),
                        status=VALUES(status),payment_method=VALUES(payment_method),bank_account_id=VALUES(bank_account_id),
                        paid_at=" . $paidAtSql);
                $stmt->execute([$id,$staffId,$staffName,$period,$basicSalary,$allowances,$deductions,$bonus,$netSalary,
                    $notes,$detailedAllowances,$detailedDeductions,$status,$paymentMethod ?: null,$bankAccountId]);

                $roleLower = strtolower((string)($staffObj['role'] ?? ''));
                $subcategory = $roleLower === 'receptionist' ? 'Resepsionis'
                    : ($roleLower === 'cleaning_service' ? 'Cleaning Service'
                    : ($roleLower === 'keamanan' ? 'Keamanan' : 'Umum'));
                $txId = 'tx_slip_' . $id;
                $notifId = 'n_slip_' . $id;
                $txDate = date('Y-m-d');

                if ($status === 'paid') {
                    $txDescription = 'Pembayaran Gaji - ' . $staffName . ' (' . $period . ')';
                    $existingPaymentStmt = $pdo->prepare("SELECT type,amount,transactionKind,sourceEntity,sourceEntityId,bankAccountId,shiftSessionId FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
                    $existingPaymentStmt->execute([$txId]);
                    $existingPaymentTx = $existingPaymentStmt->fetch();
                    if ($existingPaymentTx) {
                        $ownedBySlip = (string)($existingPaymentTx['type'] ?? '') === 'expense'
                            && strtolower((string)($existingPaymentTx['transactionKind'] ?? '')) === 'salary_payment'
                            && (string)($existingPaymentTx['sourceEntity'] ?? '') === 'salary_slip'
                            && (string)($existingPaymentTx['sourceEntityId'] ?? '') === $id;
                        if (!$ownedBySlip || !moneyMatches((float)($existingPaymentTx['amount'] ?? 0), $netSalary)) {
                            http_response_code(409);
                            throw new RuntimeException('ID pembayaran gaji bertabrakan dengan transaksi lain atau nominal lama berbeda. Gunakan koreksi audit, jangan menimpa histori kas.');
                        }
                    }
                    // A retry of an already-posted slip must preserve its historical shift.
                    // Only a genuinely new cash movement resolves the actor's current open shift.
                    $salaryShiftSessionId=$existingPaymentTx
                        ? (trim((string)($existingPaymentTx['shiftSessionId']??''))?:null)
                        : ($paymentMethod==='cash'?tamasyaResolvePhysicalCashShift($pdo,$loggedInStaff,null,'Pembayaran gaji tunai'):null);
                    $payrollCategory=tamasyaRequireSystemFinanceCategory($pdo,'payroll_expense','expense');
                    if(!$existingPaymentTx){
                        tamasyaPostFinancialTransaction($pdo,[
                            'id'=>$txId,'type'=>'expense','category'=>(string)$payrollCategory['name'],'categoryId'=>(string)$payrollCategory['id'],'categorySystemKey'=>'payroll_expense',
                            'subcategory'=>$subcategory,'amount'=>$netSalary,'date'=>$txDate,'description'=>$txDescription,'createdBy'=>currentStaffLabel($loggedInStaff),
                            'bankAccountId'=>$bankAccountId,'baseAmount'=>$netSalary,'taxAmount'=>0,'taxRate'=>0,'taxSnapshotStatus'=>'not_applicable','taxSource'=>'payroll',
                            'transactionKind'=>'salary_payment','sourceEntity'=>'salary_slip','sourceEntityId'=>$id,'isSystemGenerated'=>1,
                            'operationId'=>'salary_payment:'.$id,'shiftSessionId'=>$salaryShiftSessionId,'updatedBy'=>$loggedInStaff['id'],'updatedSource'=>'web','version'=>1
                        ],$loggedInStaff,'payroll',['lockCatalog'=>true,'source'=>'web']);
                    }
                    $stmtNotif = $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type)
                        VALUES (?,?,CURRENT_TIMESTAMP,0,'finance') ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP");
                    $stmtNotif->execute([$notifId,'Pembayaran Gaji: Pengeluaran (-) Rp '.number_format($netSalary,0,',','.').' untuk staf '.$staffName.' (Periode '.$period.').']);
                } else {
                    // Menyimpan/mengirim/mencetak slip bukan pembayaran kas. Bila slip
                    // ini sudah pernah diposting sebagai pembayaran, jangan hapus
                    // transaksi/jurnalnya saat status diubah. Gunakan workflow
                    // pembatalan/koreksi gaji yang membuat salary_reversal.
                    $postedPaymentStmt=$pdo->prepare("SELECT id FROM transactions WHERE id=? AND transactionKind='salary_payment' AND sourceEntity='salary_slip' AND sourceEntityId=? LIMIT 1 FOR UPDATE");
                    $postedPaymentStmt->execute([$txId,$id]);
                    if($postedPaymentStmt->fetchColumn()){
                        http_response_code(409);
                        throw new RuntimeException('Slip ini sudah pernah dibayar. Gunakan Pembatalan/Koreksi Pembayaran Gaji agar transaksi asli tetap utuh dan kas dibalik dengan salary_reversal.');
                    }
                    $pdo->prepare("DELETE FROM notifications WHERE id=?")->execute([$notifId]);
                }

                $tgSuccess = false;
                $tgErrorMsg = null;
                $deferredSalaryTelegram = null;
                if ($sendTelegram) {
                    $chatId = trim((string)($staffObj['telegram_chat_id'] ?? ''));
                    if ($chatId === '') {
                        $tgErrorMsg = 'Karyawan ini belum mempunyai binding Telegram aktif.';
                    } else {
                        $confRow = $pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1")->fetch() ?: [];
                        $token = trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? ''));
                        if ($token === '') {
                            $tgErrorMsg = 'Token bot Telegram belum dikonfigurasi di sistem.';
                        } else {
                            $msgLines = [
                                '💵 *SLIP GAJI KARYAWAN RESMI* 💵','🏨 *'.strtoupper(tamasyaPropertyDisplayName($pdo)).'*','------------------------------------------',
                                '👤 *Nama*: '.$staffName,'💼 *Jabatan*: '.strtoupper((string)$staffObj['role']),'📅 *Periode*: '.$period,
                                '------------------------------------------','➕ *Gaji Pokok*: Rp '.number_format($basicSalary,0,',','.')
                            ];
                            foreach ($detailAllowanceRows as $component) if ($component['amount'] > 0) $msgLines[]='   ▫️ _'.$component['name'].'_: Rp '.number_format($component['amount'],0,',','.');
                            if ($bonus > 0) $msgLines[]='➕ *Bonus / Lembur*: Rp '.number_format($bonus,0,',','.');
                            foreach ($detailDeductionRows as $component) if ($component['amount'] > 0) $msgLines[]='   ▪️ _'.$component['name'].'_: Rp '.number_format($component['amount'],0,',','.');
                            $msgLines[]='------------------------------------------';
                            $msgLines[]='💰 *GAJI BERSIH (NET)*: *Rp '.number_format($netSalary,0,',','.').'*';
                            $msgLines[]=$status === 'paid' ? '✅ *Status: SUDAH DIBAYAR*' : '📄 *Status: SLIP INFORMASI / BELUM DIBAYAR*';
                            if ($notes !== '') $msgLines[]='📝 *Keterangan*: _'.$notes.'_';
                            $deferredSalaryTelegram=['chatId'=>$chatId,'token'=>$token,'message'=>implode("\n",$msgLines)];
                        }
                    }
                }

                $salarySlipAfterStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1");$salarySlipAfterStmt->execute([$id]);$salarySlipAfter=$salarySlipAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $salaryPaymentAfterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$salaryPaymentAfterStmt->execute([$txId]);$salaryPaymentAfter=$salaryPaymentAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$status==='paid'?'Membayar slip gaji':'Menyimpan slip gaji','salary_slip',$id,
                    ['slip'=>tamasyaSalarySlipAuditSnapshot($existingSlip?:null)],
                    ['slip'=>tamasyaSalarySlipAuditSnapshot($salarySlipAfter),'payment'=>tamasyaTransactionAuditSnapshot($salaryPaymentAfter)],'web');
                logActivity($pdo,'save_salary_slip',($status==='paid'?'Membayar':'Menyimpan').' slip gaji staf '.$staffName.' periode '.$period.' senilai Rp '.number_format($netSalary,0,',','.'),$staffId,$staffName);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);

                if($deferredSalaryTelegram){
                    try{
                        // Same reason as attendance: a raw sendHttpPost discarded its
                        // result, so a parse failure left the slip silently unsent.
                        $resObj=tamasyaTelegramApiCallWithFormatRecovery($deferredSalaryTelegram['token'],'sendMessage',[
                            'chat_id'=>$deferredSalaryTelegram['chatId'],'text'=>$deferredSalaryTelegram['message'],'parse_mode'=>'Markdown'
                        ]);
                        if(!empty($resObj['ok'])){
                            $tgSuccess=true;
                            if($status!=='paid'){
                                $pdo->beginTransaction();
                                $salaryTelegramBeforeStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1 FOR UPDATE");$salaryTelegramBeforeStmt->execute([$id]);$salaryTelegramBefore=$salaryTelegramBeforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                                if($salaryTelegramBefore && !in_array(strtolower((string)($salaryTelegramBefore['status']??'')),['paid','cancelled','corrected'],true)){
                                    $pdo->prepare("UPDATE salary_slips SET status='sent',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
                                }
                                $salaryTelegramAfterStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1");$salaryTelegramAfterStmt->execute([$id]);$salaryTelegramAudit=$salaryTelegramAfterStmt->fetch(PDO::FETCH_ASSOC)?:$salaryTelegramBefore;
                                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengirim slip gaji ke Telegram','salary_slip',$id,tamasyaSalarySlipAuditSnapshot($salaryTelegramBefore),tamasyaSalarySlipAuditSnapshot($salaryTelegramAudit),'telegram');
                                tamasyaFinancialCommit($pdo);
                            }
                            logActivity($pdo,'send_telegram_payslip','Mengirim slip gaji ke Telegram staf '.$staffName,$staffId,$staffName);
                        }else{$tgErrorMsg='Gagal mengirim ke Telegram: '.($resObj['description']??'Unknown error');}
                    }catch(Throwable $telegramError){
                        if($pdo->inTransaction())$pdo->rollBack();
                        $tgErrorMsg=clientExceptionMessage('Slip tersimpan, tetapi pengiriman Telegram gagal',$telegramError);
                    }
                }
                echo json_encode([
                    'success'=>true,
                    'message'=>$status==='paid' ? 'Gaji berhasil dibayar dan otomatis mengurangi kas/bank.' : 'Slip gaji berhasil disimpan tanpa memengaruhi kas.',
                    'tg_success'=>$tgSuccess,
                    'tg_error'=>$tgErrorMsg,
                    'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal menyimpan slip gaji',$e)]);
            }
        } elseif ($method === 'DELETE') {
            $id = trim((string)($_GET['id'] ?? $input['id'] ?? ''));
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['success'=>false,'error'=>'ID Slip Gaji harus disertakan!']);
                break;
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $slip = $stmt->fetch();
                if (!$slip) {
                    http_response_code(404);
                    throw new RuntimeException('Slip gaji tidak ditemukan.');
                }
                $txStmt = $pdo->prepare("SELECT id FROM transactions WHERE id=? AND transactionKind='salary_payment' LIMIT 1 FOR UPDATE");
                $txStmt->execute(['tx_slip_'.$id]);
                if (strtolower((string)$slip['status']) === 'paid' || $txStmt->fetchColumn()) {
                    http_response_code(409);
                    throw new RuntimeException('Slip gaji yang sudah dibayar tidak boleh dihapus karena akan merusak histori kas.');
                }
                $pdo->prepare("DELETE FROM salary_slips WHERE id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM notifications WHERE id=?")->execute(['n_slip_'.$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus slip gaji non-pembayaran','salary_slip',$id,tamasyaSalarySlipAuditSnapshot($slip),['deleted'=>true],'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Slip gaji non-pembayaran berhasil dihapus.','db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Operasi gagal',$e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
        }
        break;


    // ----------------------------------------------------------------
    // POST /api/salary-payment-correction
    // Audited cancellation/correction. Original paid slip and payment are never
    // deleted or overwritten; the server creates a reversal and, for correction,
    // a new paid slip/payment in one database transaction.
    // ----------------------------------------------------------------
    case 'salary-payment-correction':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        $command=strtolower(trim((string)($input['command'] ?? '')));
        $sourceSlipId=trim((string)($input['slipId'] ?? ''));
        $reason=trim((string)($input['reason'] ?? ''));
        $operationId=substr(trim((string)($input['operationId'] ?? '')),0,100);
        $confirmation=trim((string)($input['confirmation'] ?? ''));
        if(!in_array($command,['cancel','correct'],true) || $sourceSlipId==='' || $operationId==='' || tamasyaStringLength($reason)<8){
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>'Perintah, ID slip, operationId, dan alasan minimal 8 karakter wajib diisi.']);
            break;
        }
        $expectedPhrase=$command==='cancel'?'BATALKAN GAJI':'KOREKSI GAJI';
        if($confirmation!==$expectedPhrase){
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>'Ketik '.$expectedPhrase.' untuk mengonfirmasi.']);
            break;
        }

        $normalizeComponents=static function($raw,$label){
            if(!is_array($raw))return [];
            $rows=[];
            foreach($raw as $item){
                if(!is_array($item))continue;
                $name=trim((string)($item['name'] ?? '')) ?: $label;
                $amount=round((float)($item['amount'] ?? 0),2);
                if($amount<0)throw new InvalidArgumentException($label.' tidak boleh negatif.');
                $rows[]=['name'=>$name,'amount'=>$amount];
            }
            return $rows;
        };

        try{
            $pdo->beginTransaction();
            $duplicate=$pdo->prepare("SELECT id,type,sourceEntityId FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");
            $duplicate->execute([$operationId]);
            if($existingOperation=$duplicate->fetch(PDO::FETCH_ASSOC)){
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'duplicate'=>true,'message'=>'Operasi koreksi gaji ini sudah diproses.','transactionId'=>$existingOperation['id'],'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
                break;
            }

            $slipStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1 FOR UPDATE");
            $slipStmt->execute([$sourceSlipId]);
            $sourceSlip=$slipStmt->fetch(PDO::FETCH_ASSOC);
            if(!$sourceSlip){http_response_code(404);throw new RuntimeException('Slip gaji sumber tidak ditemukan.');}
            if(strtolower((string)($sourceSlip['status'] ?? ''))!=='paid'){
                http_response_code(409);throw new RuntimeException('Hanya slip berstatus Dibayar yang dapat dibatalkan atau dikoreksi.');
            }
            $paymentStmt=$pdo->prepare("SELECT * FROM transactions WHERE transactionKind='salary_payment' AND sourceEntity='salary_slip' AND sourceEntityId=? ORDER BY createdAt DESC,id DESC LIMIT 1 FOR UPDATE");
            $paymentStmt->execute([$sourceSlipId]);
            $paymentTx=$paymentStmt->fetch(PDO::FETCH_ASSOC);
            if(!$paymentTx){http_response_code(409);throw new RuntimeException('Transaksi pembayaran gaji sumber tidak ditemukan.');}

            $reversalId='tx_salary_rev_'.substr(hash('sha256',$operationId.'|'.$sourceSlipId),0,32);
            $reversalDescription=($command==='cancel'?'Pembatalan':'Koreksi').' Pembayaran Gaji - '.(string)$sourceSlip['staff_name'].' ('.(string)$sourceSlip['period'].')';
            $sourcePaymentMethod=tamasyaNormalizePaymentMethod((string)($sourceSlip['payment_method']??''));
            if($sourcePaymentMethod==='')$sourcePaymentMethod=trim((string)($paymentTx['bankAccountId']??''))===''?'cash':'transfer';
            $reversalShiftSessionId=$sourcePaymentMethod==='cash'
                ? tamasyaResolvePhysicalCashShift($pdo,$loggedInStaff,null,'Pembalik pembayaran gaji tunai')
                : null;
            $payrollCategory=tamasyaRequireSystemFinanceCategory($pdo,'payroll_expense','expense');
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$reversalId,'type'=>'income','category'=>(string)$payrollCategory['name'],'subcategory'=>'Pembalik Pembayaran',
                'amount'=>(float)$paymentTx['amount'],'date'=>date('Y-m-d'),'description'=>$reversalDescription,'createdBy'=>currentStaffLabel($loggedInStaff),
                'bankAccountId'=>$paymentTx['bankAccountId']??null,'baseAmount'=>(float)$paymentTx['amount'],'taxAmount'=>0,'taxRate'=>0,
                'taxSnapshotStatus'=>'not_applicable','taxSource'=>'payroll_reversal','transactionKind'=>'salary_reversal','sourceEntity'=>'salary_slip',
                'sourceEntityId'=>$sourceSlipId,'isSystemGenerated'=>1,'operationId'=>$operationId,'shiftSessionId'=>$reversalShiftSessionId,
                'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
            ],$loggedInStaff,'payroll',['source'=>'web']);

            $newSlipId=null;
            if($command==='cancel'){
                $update=$pdo->prepare("UPDATE salary_slips SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason=?,reversal_transaction_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $update->execute([$loggedInStaff['id'] ?? null,$reason,$reversalId,$sourceSlipId]);
                $message='Pembayaran gaji dibatalkan. Kas/bank dikembalikan melalui transaksi pembalik; histori asli tetap tersimpan.';
                logActivity($pdo,'cancel_salary_payment','Membatalkan pembayaran slip '.$sourceSlipId.': '.$reason,(string)($loggedInStaff['id']??''),currentStaffLabel($loggedInStaff));
            }else{
                $staffId=trim((string)($input['staffId'] ?? $sourceSlip['staff_id'] ?? ''));
                $period=trim((string)($input['period'] ?? $sourceSlip['period'] ?? ''));
                $basic=round((float)($input['basicSalary'] ?? 0),2);
                $bonus=round((float)($input['bonus'] ?? 0),2);
                $allowanceRows=$normalizeComponents($input['detailedAllowances'] ?? [],'Tunjangan');
                $deductionRows=$normalizeComponents($input['detailedDeductions'] ?? [],'Potongan');
                $allowances=count($allowanceRows)?round(array_sum(array_column($allowanceRows,'amount')),2):round((float)($input['allowances'] ?? 0),2);
                $deductions=count($deductionRows)?round(array_sum(array_column($deductionRows,'amount')),2):round((float)($input['deductions'] ?? 0),2);
                $net=round($basic+$allowances+$bonus-$deductions,2);
                $notes=trim((string)($input['notes'] ?? ''));
                $paymentMethod=strtolower(trim((string)($input['paymentMethod'] ?? $sourceSlip['payment_method'] ?? 'cash')));
                $bankAccountId=trim((string)($input['bankAccountId'] ?? $sourceSlip['bank_account_id'] ?? '')) ?: null;
                if($staffId==='' || $period==='' || $net<0 || !in_array($paymentMethod,['cash','transfer','qris'],true)){
                    http_response_code(422);throw new InvalidArgumentException('Data slip koreksi tidak valid.');
                }
                if(in_array($paymentMethod,['transfer','qris'],true) && !$bankAccountId){http_response_code(422);throw new InvalidArgumentException('Akun bank/QRIS wajib dipilih.');}
                if($paymentMethod==='cash')$bankAccountId=null;
                else $bankAccountId=tamasyaSecurityDepositPaymentAccount($pdo,$paymentMethod,(string)$bankAccountId);
                $staffStmt=$pdo->prepare("SELECT id,name,role FROM staff WHERE id=? AND status='active' LIMIT 1 FOR UPDATE");
                $staffStmt->execute([$staffId]);$staff=$staffStmt->fetch(PDO::FETCH_ASSOC);
                if(!$staff){http_response_code(404);throw new RuntimeException('Karyawan aktif untuk slip koreksi tidak ditemukan.');}
                $newSlipId='slip_corr_'.substr(hash('sha256',$operationId.'|new-slip'),0,28);
                $detailAllowanceJson=json_encode($allowanceRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $detailDeductionJson=json_encode($deductionRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $insertSlip=$pdo->prepare("INSERT INTO salary_slips
                    (id,staff_id,staff_name,period,basic_salary,allowances,deductions,bonus,net_salary,notes,detailed_allowances,detailed_deductions,
                     status,payment_method,bank_account_id,paid_at,correction_of_slip_id,correction_reason)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'paid',?,?,CURRENT_TIMESTAMP,?,?)");
                $insertSlip->execute([$newSlipId,$staffId,$staff['name'],$period,$basic,$allowances,$deductions,$bonus,$net,$notes,$detailAllowanceJson,$detailDeductionJson,$paymentMethod,$bankAccountId,$sourceSlipId,$reason]);
                $newPaymentId='tx_slip_'.$newSlipId;
                $newPaymentOp='salary_correction_payment:'.$operationId;
                $newPaymentShiftSessionId=$paymentMethod==='cash'
                    ? tamasyaResolvePhysicalCashShift($pdo,$loggedInStaff,null,'Pembayaran gaji koreksi tunai')
                    : null;
                $roleLower=strtolower((string)($staff['role'] ?? ''));
                $subcategory=$roleLower==='receptionist'?'Resepsionis':($roleLower==='cleaning_service'?'Cleaning Service':($roleLower==='keamanan'?'Keamanan':'Umum'));
                $payrollCategory=tamasyaRequireSystemFinanceCategory($pdo,'payroll_expense','expense');
                tamasyaPostFinancialTransaction($pdo,[
                    'id'=>$newPaymentId,'type'=>'expense','category'=>(string)$payrollCategory['name'],'categoryId'=>(string)$payrollCategory['id'],'categorySystemKey'=>'payroll_expense',
                    'subcategory'=>$subcategory,'amount'=>$net,'date'=>date('Y-m-d'),'description'=>'Pembayaran Gaji Koreksi - '.$staff['name'].' ('.$period.')',
                    'createdBy'=>currentStaffLabel($loggedInStaff),'bankAccountId'=>$bankAccountId,'baseAmount'=>$net,'taxAmount'=>0,'taxRate'=>0,
                    'taxSnapshotStatus'=>'not_applicable','taxSource'=>'payroll','transactionKind'=>'salary_payment','sourceEntity'=>'salary_slip','sourceEntityId'=>$newSlipId,
                    'isSystemGenerated'=>1,'operationId'=>$newPaymentOp,'shiftSessionId'=>$newPaymentShiftSessionId,'updatedBy'=>$loggedInStaff['id']??null,
                    'updatedSource'=>'web','version'=>1
                ],$loggedInStaff,'payroll',['lockCatalog'=>true,'source'=>'web']);
                $update=$pdo->prepare("UPDATE salary_slips SET status='corrected',cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancellation_reason=?,reversal_transaction_id=?,corrected_by_slip_id=?,correction_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $update->execute([$loggedInStaff['id'] ?? null,$reason,$reversalId,$newSlipId,$reason,$sourceSlipId]);
                $message='Pembayaran lama dibalik dan slip koreksi baru dibayar secara atomik. Histori lama tidak ditimpa.';
                logActivity($pdo,'correct_salary_payment','Mengoreksi pembayaran slip '.$sourceSlipId.' menjadi '.$newSlipId.': '.$reason,(string)($loggedInStaff['id']??''),currentStaffLabel($loggedInStaff));
            }
            $sourceSlipAfterStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1");$sourceSlipAfterStmt->execute([$sourceSlipId]);$sourceSlipAfter=$sourceSlipAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            $reversalAfterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$reversalAfterStmt->execute([$reversalId]);$reversalAfter=$reversalAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            $newSlipAfter=null;$newPaymentAfter=null;
            if($newSlipId){$newSlipAfterStmt=$pdo->prepare("SELECT * FROM salary_slips WHERE id=? LIMIT 1");$newSlipAfterStmt->execute([$newSlipId]);$newSlipAfter=$newSlipAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;$newPaymentAfterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$newPaymentAfterStmt->execute(['tx_slip_'.$newSlipId]);$newPaymentAfter=$newPaymentAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;}
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$command==='cancel'?'Membatalkan pembayaran gaji':'Mengoreksi pembayaran gaji','salary_slip',$sourceSlipId,
                ['slip'=>tamasyaSalarySlipAuditSnapshot($sourceSlip),'payment'=>tamasyaTransactionAuditSnapshot($paymentTx)],
                ['slip'=>tamasyaSalarySlipAuditSnapshot($sourceSlipAfter),'reversal'=>tamasyaTransactionAuditSnapshot($reversalAfter),'newSlip'=>tamasyaSalarySlipAuditSnapshot($newSlipAfter),'newPayment'=>tamasyaTransactionAuditSnapshot($newPaymentAfter),'operationId'=>$operationId],'web');
            $notifId='n_salary_correction_'.substr(hash('sha256',$operationId),0,24);
            $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance')")
                ->execute([$notifId,$message.' Alasan: '.$reason]);
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>$message,'reversalTransactionId'=>$reversalId,'newSlipId'=>$newSlipId,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memproses koreksi pembayaran gaji',$e)]);
        }
        break;



    // ----------------------------------------------------------------
    // GET/POST /api/staff-savings
    // Ledger titipan karyawan yang sepenuhnya terpisah dari kas hotel.
    // Semua mutasi online-only dan tervalidasi/atomik di server.
    // ----------------------------------------------------------------
    case 'staff-savings':
        $method=$_SERVER['REQUEST_METHOD'];
        $allStaffRoles=['admin','manager','receptionist','finance','koki','tukang_kebun','cleaning_service','keamanan','lain_lain'];
        requireDesktopTabAccess($loggedInStaff,'savings',$allStaffRoles);
        $canManageSavings=canManageStaffSavings($loggedInStaff);

        if($method==='GET'){
            try{
                $scope=strtolower(trim((string)($_GET['scope']??'self')));
                $requestedStaffId=trim((string)($_GET['staffId']??''));
                $limit=max(10,min(100,(int)($_GET['limit']??50)));
                $accounts=[];
                if($scope==='all' && ($canManageSavings || tamasyaIsOwnerRole($loggedInStaff))){
                    $rows=$pdo->query("SELECT s.id AS staffId,s.name AS staffName,s.role,s.status,
                        COALESCE(a.balance,0) AS balance,
                        COALESCE((SELECT SUM(r.amount) FROM staff_savings_requests r WHERE r.staff_id=s.id AND r.status IN ('pending','approved')),0) AS reservedBalance,
                        COALESCE((SELECT COUNT(*) FROM staff_savings_requests r2 WHERE r2.staff_id=s.id AND r2.status='pending'),0) AS pendingCount
                        FROM staff s LEFT JOIN staff_savings_accounts a ON a.staff_id=s.id
                        WHERE s.status='active' ORDER BY s.name,s.id")->fetchAll(PDO::FETCH_ASSOC)?:[];
                    foreach($rows as $row){
                        $row['balance']=(float)$row['balance'];
                        $row['reservedBalance']=(float)$row['reservedBalance'];
                        $row['availableBalance']=max(0,$row['balance']-$row['reservedBalance']);
                        $row['pendingCount']=(int)$row['pendingCount'];
                        $accounts[]=$row;
                    }
                }
                $target=staffSavingsTargetStaff($pdo,$loggedInStaff,$requestedStaffId);
                $accountStmt=$pdo->prepare("SELECT balance,status,version,updated_at FROM staff_savings_accounts WHERE staff_id=? LIMIT 1");
                $accountStmt->execute([$target['id']]);$account=$accountStmt->fetch(PDO::FETCH_ASSOC)?:['balance'=>0,'status'=>'active','version'=>0,'updated_at'=>null];
                $balance=round((float)($account['balance']??0),2);
                $reserved=staffSavingsReservedAmount($pdo,(string)$target['id']);
                $ledgerStmt=$pdo->prepare("SELECT l.*,s.name AS staffName FROM staff_savings_ledger l JOIN staff s ON s.id=l.staff_id WHERE l.staff_id=? ORDER BY l.created_at DESC,l.id DESC LIMIT {$limit}");
                $ledgerStmt->execute([$target['id']]);$ledger=$ledgerStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                foreach($ledger as &$row){
                    foreach(['amount','balance_before','balance_after'] as $field)$row[$field]=(float)$row[$field];
                } unset($row);
                $requestStmt=$pdo->prepare("SELECT r.*,s.name AS staffName FROM staff_savings_requests r JOIN staff s ON s.id=r.staff_id WHERE r.staff_id=? ORDER BY r.requested_at DESC,r.id DESC LIMIT {$limit}");
                $requestStmt->execute([$target['id']]);$requests=$requestStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                foreach($requests as &$row){$row['amount']=(float)$row['amount'];} unset($row);
                echo json_encode([
                    'success'=>true,'canManage'=>$canManageSavings,'onlineOnly'=>true,
                    'currentStaffId'=>(string)($loggedInStaff['id']??''),'accounts'=>$accounts,
                    'account'=>[
                        'staffId'=>(string)$target['id'],'staffName'=>(string)$target['name'],'role'=>(string)$target['role'],
                        'balance'=>$balance,'reservedBalance'=>$reserved,'availableBalance'=>max(0,$balance-$reserved),
                        'status'=>(string)($account['status']??'active'),'version'=>(int)($account['version']??0),'updatedAt'=>$account['updated_at']??null
                    ],
                    'ledger'=>$ledger,'requests'=>$requests
                ]);
            }catch(Throwable $e){
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memuat Simpanan Karyawan',$e)]);
            }
            break;
        }

        if($method!=='POST'){
            http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;
        }

        $command=strtolower(trim((string)($input['command']??'')));
        $operationId=substr(trim((string)($input['operationId']??'')),0,100);
        try{
            if($operationId==='' && in_array($command,['deposit','request','approve','reject','pay','cancel','adjust'],true)){
                throw new InvalidArgumentException('operationId wajib diisi untuk mencegah transaksi ganda.');
            }

            if($command==='deposit'){
                if(!$canManageSavings){http_response_code(403);throw new RuntimeException('Hanya Admin, Manager, atau Finance yang dapat mencatat setoran.');}
                $target=staffSavingsTargetStaff($pdo,$loggedInStaff,(string)($input['staffId']??''));
                $amount=normalizeStaffSavingsAmount($input['amount']??0);
                $source=normalizeStaffSavingsSource($input['sourceType']??'other');
                $methodValue=normalizeStaffSavingsMethod($input['paymentMethod']??'cash');
                $description=trim((string)($input['description']??''));
                $storageReference=substr(trim((string)($input['storageReference']??'')),0,190);
                $pdo->beginTransaction();
                $existing=$pdo->prepare("SELECT id,receipt_number FROM staff_savings_ledger WHERE operation_id=? LIMIT 1 FOR UPDATE");$existing->execute([$operationId]);
                if($row=$existing->fetch(PDO::FETCH_ASSOC)){
                    tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Setoran ini sudah tercatat.','entryId'=>$row['id'],'receiptNumber'=>$row['receipt_number']]);break;
                }
                $account=lockStaffSavingsAccount($pdo,(string)$target['id']);
                $before=round((float)$account['balance'],2);$after=round($before+$amount,2);
                $entryId=generateServerId('saving');$receipt=nextDocumentNumber($pdo,staffSavingsReceiptPrefix('deposit'));
                $stmt=$pdo->prepare("INSERT INTO staff_savings_ledger(id,operation_id,receipt_number,staff_id,entry_type,amount,balance_before,balance_after,source_type,payment_method,storage_reference,description,status,created_by,created_by_name,created_at) VALUES (?,?,?,?, 'deposit',?,?,?,?,?,?,?,'posted',?,?,CURRENT_TIMESTAMP)");
                $stmt->execute([$entryId,$operationId,$receipt,$target['id'],$amount,$before,$after,$source,$methodValue,$storageReference!==''?$storageReference:null,$description!==''?$description:null,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
                $pdo->prepare("UPDATE staff_savings_accounts SET balance=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE staff_id=?")->execute([$after,$target['id']]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Setoran Simpanan Karyawan','staff_savings_account',(string)$target['id'],
                    ['balance'=>$before],
                    ['balance'=>$after,'ledgerEntryId'=>$entryId,'receiptNumber'=>$receipt,'amount'=>$amount,'sourceType'=>$source,'paymentMethod'=>$methodValue,'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_deposit','Setoran Simpanan Karyawan '.$target['name'].' Rp '.number_format($amount,0,',','.'),$target['id'],$target['name']);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Setoran berhasil dicatat tanpa memengaruhi kas hotel.','entryId'=>$entryId,'receiptNumber'=>$receipt,'balance'=>$after]);
                break;
            }

            if($command==='request'){
                $target=staffSavingsTargetStaff($pdo,$loggedInStaff,(string)($input['staffId']??''));
                $amount=normalizeStaffSavingsAmount($input['amount']??0);
                $purpose=trim((string)($input['purpose']??''));
                if(strlen($purpose)<3)throw new InvalidArgumentException('Keperluan penarikan minimal 3 karakter.');
                $payoutMethod=normalizeStaffSavingsMethod($input['payoutMethod']??'cash');
                $pdo->beginTransaction();
                $existing=$pdo->prepare("SELECT id,status FROM staff_savings_requests WHERE operation_id=? LIMIT 1 FOR UPDATE");$existing->execute([$operationId]);
                if($row=$existing->fetch(PDO::FETCH_ASSOC)){
                    tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Permintaan ini sudah tersimpan.','requestId'=>$row['id'],'status'=>$row['status']]);break;
                }
                $account=lockStaffSavingsAccount($pdo,(string)$target['id']);
                $reserved=staffSavingsReservedAmount($pdo,(string)$target['id']);
                $available=round((float)$account['balance']-$reserved,2);
                if($amount>$available+0.001){http_response_code(409);throw new RuntimeException('Saldo tersedia tidak mencukupi. Saldo tersedia Rp '.number_format(max(0,$available),0,',','.').'.');}
                $requestId=generateServerId('saving_req');
                $stmt=$pdo->prepare("INSERT INTO staff_savings_requests(id,operation_id,staff_id,amount,purpose,payout_method,status,requested_by,requested_by_name,requested_at,created_at,updated_at) VALUES (?,?,?,?,?,?,'pending',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                $stmt->execute([$requestId,$operationId,$target['id'],$amount,$purpose,$payoutMethod,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengajukan penarikan Simpanan','staff_savings_request',$requestId,
                    null,
                    ['staffId'=>(string)$target['id'],'amount'=>$amount,'purpose'=>$purpose,'payoutMethod'=>$payoutMethod,'status'=>'pending','availableBalanceBefore'=>$available,'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_request','Permintaan penarikan Simpanan '.$target['name'].' Rp '.number_format($amount,0,',','.'),$target['id'],$target['name']);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Permintaan penarikan berhasil diajukan. Saldo baru berkurang setelah dibayarkan.','requestId'=>$requestId]);
                break;
            }

            if($command==='approve' || $command==='reject'){
                if(!$canManageSavings){http_response_code(403);throw new RuntimeException('Hanya Admin, Manager, atau Finance yang dapat memutuskan permintaan.');}
                $requestId=trim((string)($input['requestId']??''));if($requestId==='')throw new InvalidArgumentException('requestId wajib diisi.');
                $notes=trim((string)($input['notes']??''));
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT r.*,s.name AS staffName FROM staff_savings_requests r JOIN staff s ON s.id=r.staff_id WHERE r.id=? LIMIT 1 FOR UPDATE");$stmt->execute([$requestId]);$request=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$request){http_response_code(404);throw new RuntimeException('Permintaan penarikan tidak ditemukan.');}
                if(!in_array($request['status'],['pending','approved'],true)){http_response_code(409);throw new RuntimeException('Permintaan ini sudah berstatus '.$request['status'].'.');}
                if($command==='approve'){
                    if($request['status']==='approved'){tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Permintaan sudah disetujui.']);break;}
                    $pdo->prepare("UPDATE staff_savings_requests SET status='approved',approved_by=?,approved_by_name=?,approved_at=CURRENT_TIMESTAMP,decision_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                        ->execute([$loggedInStaff['id'],currentStaffLabel($loggedInStaff),$notes!==''?$notes:null,$requestId]);
                    $message='Permintaan penarikan disetujui. Saldo belum berkurang sebelum pembayaran.';
                }else{
                    $pdo->prepare("UPDATE staff_savings_requests SET status='rejected',approved_by=?,approved_by_name=?,approved_at=CURRENT_TIMESTAMP,decision_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                        ->execute([$loggedInStaff['id'],currentStaffLabel($loggedInStaff),$notes!==''?$notes:'Ditolak oleh bagian keuangan',$requestId]);
                    $message='Permintaan penarikan ditolak dan dana kembali tersedia.';
                }
                $newStatus=$command==='approve'?'approved':'rejected';
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,ucfirst($command).' permintaan Simpanan','staff_savings_request',$requestId,
                    ['status'=>$request['status'],'decisionNotes'=>$request['decision_notes']??null],
                    ['status'=>$newStatus,'decisionNotes'=>$notes!==''?$notes:($command==='reject'?'Ditolak oleh bagian keuangan':null),'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_'.$command,ucfirst($command).' permintaan Simpanan '.$request['staffName'].' Rp '.number_format((float)$request['amount'],0,',','.'),$request['staff_id'],$request['staffName']);
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'message'=>$message]);break;
            }

            if($command==='pay'){
                if(!$canManageSavings){http_response_code(403);throw new RuntimeException('Hanya Admin, Manager, atau Finance yang dapat membayarkan penarikan.');}
                $requestId=trim((string)($input['requestId']??''));if($requestId==='')throw new InvalidArgumentException('requestId wajib diisi.');
                $storageReference=substr(trim((string)($input['storageReference']??'')),0,190);
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT r.*,s.name AS staffName FROM staff_savings_requests r JOIN staff s ON s.id=r.staff_id WHERE r.id=? LIMIT 1 FOR UPDATE");$stmt->execute([$requestId]);$request=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$request){http_response_code(404);throw new RuntimeException('Permintaan penarikan tidak ditemukan.');}
                $existing=$pdo->prepare("SELECT id,receipt_number FROM staff_savings_ledger WHERE request_id=? LIMIT 1 FOR UPDATE");$existing->execute([$requestId]);
                if($row=$existing->fetch(PDO::FETCH_ASSOC)){
                    if($request['status']!=='paid'){
                        $pdo->prepare("UPDATE staff_savings_requests SET status='paid',ledger_entry_id=?,paid_at=COALESCE(paid_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$row['id'],$requestId]);
                        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memulihkan status pembayaran Simpanan','staff_savings_request',$requestId,
                            ['status'=>$request['status'],'ledgerEntryId'=>$request['ledger_entry_id']??null],
                            ['status'=>'paid','ledgerEntryId'=>$row['id'],'operationId'=>$operationId]);
                    }
                    tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Penarikan ini sudah dibayarkan.','entryId'=>$row['id'],'receiptNumber'=>$row['receipt_number']]);break;
                }
                if($request['status']!=='approved'){http_response_code(409);throw new RuntimeException('Permintaan harus disetujui sebelum dibayarkan.');}
                $account=lockStaffSavingsAccount($pdo,(string)$request['staff_id']);
                $amount=round((float)$request['amount'],2);$before=round((float)$account['balance'],2);
                if($amount>$before+0.001){http_response_code(409);throw new RuntimeException('Saldo rekening Simpanan tidak mencukupi untuk pembayaran ini.');}
                $after=round($before-$amount,2);$entryId=generateServerId('saving');$receipt=nextDocumentNumber($pdo,staffSavingsReceiptPrefix('withdrawal'));
                $stmt=$pdo->prepare("INSERT INTO staff_savings_ledger(id,operation_id,receipt_number,staff_id,entry_type,amount,balance_before,balance_after,source_type,payment_method,storage_reference,description,request_id,status,created_by,created_by_name,created_at) VALUES (?,?,?,?, 'withdrawal',?,?,?,?,?,?,?,?, 'posted',?,?,CURRENT_TIMESTAMP)");
                $stmt->execute([$entryId,$operationId,$receipt,$request['staff_id'],$amount,$before,$after,'withdrawal_request',normalizeStaffSavingsMethod($request['payout_method']),$storageReference!==''?$storageReference:null,$request['purpose'],$requestId,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
                $pdo->prepare("UPDATE staff_savings_accounts SET balance=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE staff_id=?")->execute([$after,$request['staff_id']]);
                $pdo->prepare("UPDATE staff_savings_requests SET status='paid',paid_by=?,paid_by_name=?,paid_at=CURRENT_TIMESTAMP,ledger_entry_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$loggedInStaff['id'],currentStaffLabel($loggedInStaff),$entryId,$requestId]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membayar penarikan Simpanan','staff_savings_request',$requestId,
                    ['requestStatus'=>$request['status'],'accountBalance'=>$before],
                    ['requestStatus'=>'paid','accountBalance'=>$after,'ledgerEntryId'=>$entryId,'receiptNumber'=>$receipt,'amount'=>$amount,'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_pay','Pembayaran Simpanan '.$request['staffName'].' Rp '.number_format($amount,0,',','.'),$request['staff_id'],$request['staffName']);
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'message'=>'Penarikan berhasil dibayarkan dan saldo Simpanan telah berkurang.','entryId'=>$entryId,'receiptNumber'=>$receipt,'balance'=>$after]);break;
            }

            if($command==='cancel'){
                $requestId=trim((string)($input['requestId']??''));if($requestId==='')throw new InvalidArgumentException('requestId wajib diisi.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT r.*,s.name AS staffName FROM staff_savings_requests r JOIN staff s ON s.id=r.staff_id WHERE r.id=? LIMIT 1 FOR UPDATE");$stmt->execute([$requestId]);$request=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$request){http_response_code(404);throw new RuntimeException('Permintaan penarikan tidak ditemukan.');}
                $ownsRequest=(string)$request['staff_id']===(string)($loggedInStaff['id']??'');
                if(!$canManageSavings && !$ownsRequest){http_response_code(403);throw new RuntimeException('Anda tidak dapat membatalkan permintaan milik karyawan lain.');}
                if(!in_array($request['status'],['pending','approved'],true)){http_response_code(409);throw new RuntimeException('Permintaan berstatus '.$request['status'].' tidak dapat dibatalkan.');}
                if(!$canManageSavings && $request['status']!=='pending'){http_response_code(409);throw new RuntimeException('Permintaan yang sudah disetujui hanya dapat dibatalkan bagian keuangan.');}
                $pdo->prepare("UPDATE staff_savings_requests SET status='cancelled',cancelled_by=?,cancelled_by_name=?,cancelled_at=CURRENT_TIMESTAMP,decision_notes=COALESCE(NULLIF(?,''),decision_notes),updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$loggedInStaff['id'],currentStaffLabel($loggedInStaff),trim((string)($input['notes']??'')),$requestId]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membatalkan permintaan Simpanan','staff_savings_request',$requestId,
                    ['status'=>$request['status'],'decisionNotes'=>$request['decision_notes']??null],
                    ['status'=>'cancelled','decisionNotes'=>trim((string)($input['notes']??''))?:($request['decision_notes']??null),'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_cancel','Membatalkan permintaan Simpanan '.$request['staffName'].' Rp '.number_format((float)$request['amount'],0,',','.'),$request['staff_id'],$request['staffName']);
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'message'=>'Permintaan dibatalkan dan dana kembali tersedia.']);break;
            }

            if($command==='adjust'){
                if(!$canManageSavings){http_response_code(403);throw new RuntimeException('Hanya Admin, Manager, atau Finance yang dapat membuat koreksi.');}
                $target=staffSavingsTargetStaff($pdo,$loggedInStaff,(string)($input['staffId']??''));
                $amount=normalizeStaffSavingsAmount($input['amount']??0);
                $direction=strtolower(trim((string)($input['direction']??'')));
                if(!in_array($direction,['credit','debit'],true))throw new InvalidArgumentException('Arah koreksi harus credit atau debit.');
                $description=trim((string)($input['description']??''));if(strlen($description)<5)throw new InvalidArgumentException('Alasan koreksi minimal 5 karakter.');
                $referenceEntryId=substr(trim((string)($input['referenceEntryId']??'')),0,80);
                $pdo->beginTransaction();
                $existing=$pdo->prepare("SELECT id,receipt_number FROM staff_savings_ledger WHERE operation_id=? LIMIT 1 FOR UPDATE");$existing->execute([$operationId]);
                if($row=$existing->fetch(PDO::FETCH_ASSOC)){tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Koreksi ini sudah tercatat.','entryId'=>$row['id'],'receiptNumber'=>$row['receipt_number']]);break;}
                $account=lockStaffSavingsAccount($pdo,(string)$target['id']);$before=round((float)$account['balance'],2);
                if($direction==='debit'){
                    $reserved=staffSavingsReservedAmount($pdo,(string)$target['id']);
                    $available=round($before-$reserved,2);
                    if($amount>$available+0.001){http_response_code(409);throw new RuntimeException('Koreksi debit melebihi saldo tersedia setelah dana permintaan dicadangkan.');}
                }
                $after=$direction==='credit'?round($before+$amount,2):round($before-$amount,2);
                if($after<0){http_response_code(409);throw new RuntimeException('Koreksi debit akan membuat saldo negatif.');}
                $entryType=$direction==='credit'?'correction_credit':'correction_debit';$entryId=generateServerId('saving');$receipt=nextDocumentNumber($pdo,staffSavingsReceiptPrefix($entryType));
                $stmt=$pdo->prepare("INSERT INTO staff_savings_ledger(id,operation_id,receipt_number,staff_id,entry_type,amount,balance_before,balance_after,source_type,payment_method,description,reference_entry_id,status,created_by,created_by_name,created_at) VALUES (?,?,?,?,?,?,?,?, 'correction','cash',?,?, 'posted',?,?,CURRENT_TIMESTAMP)");
                $stmt->execute([$entryId,$operationId,$receipt,$target['id'],$entryType,$amount,$before,$after,$description,$referenceEntryId!==''?$referenceEntryId:null,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
                $pdo->prepare("UPDATE staff_savings_accounts SET balance=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE staff_id=?")->execute([$after,$target['id']]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Koreksi saldo Simpanan','staff_savings_account',(string)$target['id'],
                    ['balance'=>$before],
                    ['balance'=>$after,'direction'=>$direction,'amount'=>$amount,'reason'=>$description,'referenceEntryId'=>$referenceEntryId?:null,'ledgerEntryId'=>$entryId,'receiptNumber'=>$receipt,'operationId'=>$operationId]);
                logActivity($pdo,'staff_savings_adjust','Koreksi '.$direction.' Simpanan '.$target['name'].' Rp '.number_format($amount,0,',','.'),$target['id'],$target['name']);
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'message'=>'Koreksi berhasil dicatat sebagai ledger baru.','entryId'=>$entryId,'receiptNumber'=>$receipt,'balance'=>$after]);break;
            }

            http_response_code(400);echo json_encode(['success'=>false,'error'=>'Perintah Simpanan Karyawan tidak dikenali.']);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Operasi Simpanan Karyawan gagal',$e)]);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/PUT/DELETE /api/inventory ATAU api.php?action=inventory
    // Manajemen Inventaris & Aset Hotel (Hotel Assets)
    // ----------------------------------------------------------------
    case 'inventory':
        $method = $_SERVER['REQUEST_METHOD'];
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);

        if ($method === 'POST') {
            $code = trim((string)($input['code'] ?? ''));
            $name = trim((string)($input['name'] ?? ''));
            $category = trim((string)($input['category'] ?? ''));
            $location = trim((string)($input['location'] ?? ''));
            $quantity = (int)($input['quantity'] ?? 1);
            $unit = trim((string)($input['unit'] ?? 'Pcs')) ?: 'Pcs';
            $condition = trim((string)($input['condition_status'] ?? 'baik')) ?: 'baik';
            $purchaseDate = empty($input['purchase_date']) ? null : trim((string)$input['purchase_date']);
            $price = (float)($input['price'] ?? 0.00);
            $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;

            if ($code === '' || $name === '' || $category === '' || $location === '') {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Format input aset salah / tidak lengkap!']);
                break;
            }
            if ($quantity < 0 || $price < 0 || ($purchaseDate !== null && !validIsoDate($purchaseDate))) {
                http_response_code(422);
                echo json_encode(['success'=>false,'message'=>'Jumlah, harga, atau tanggal pembelian aset tidak valid.']);
                break;
            }

            try {
                $pdo->beginTransaction();
                $duplicateCode=$pdo->prepare("SELECT id FROM inventory WHERE code=? LIMIT 1 FOR UPDATE");
                $duplicateCode->execute([$code]);
                if($duplicateCode->fetchColumn())throw new RuntimeException('Kode aset sudah digunakan.');
                $id = generateServerId('inv');
                $stmt = $pdo->prepare("INSERT INTO inventory (id, code, name, category, location, quantity, unit, condition_status, purchase_date, price, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id, $code, $name, $category, $location, $quantity, $unit, $condition, $purchaseDate, $price, $notes]);

                $notifId = generateServerId('n_inv');
                $notifMsg = "Aset hotel baru dicatat: $name ($code) di $location sejumlah $quantity $unit.";
                $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')")
                    ->execute([$notifId, $notifMsg, date('Y-m-d H:i:s')]);
                $afterStmt=$pdo->prepare("SELECT * FROM inventory WHERE id=? LIMIT 1");
                $afterStmt->execute([$id]);
                $after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'code'=>$code,'name'=>$name];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat aset inventaris','inventory',$id,null,$after);
                logActivity($pdo,'create_inventory',"Mencatat aset baru: $name ($code), Lokasi: $location, Jumlah: $quantity $unit",$loggedInStaff['id']??null,$loggedInStaff['name']??null);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Aset hotel berhasil disimpan ke MySQL!','db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            } catch (Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal menyimpan aset',$e)]);
            }
        } elseif ($method === 'PUT') {
            $id = trim((string)($_GET['id'] ?? $input['id'] ?? ''));
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'ID Aset harus disertakan!']);
                break;
            }
            try {
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM inventory WHERE id=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $old=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$old){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Aset tidak ditemukan!']);$pdo->rollBack();break;}

                $fields=[];$params=[];
                if(array_key_exists('code',$input)){
                    $newCode=trim((string)$input['code']);if($newCode==='')throw new InvalidArgumentException('Kode aset tidak boleh kosong.');
                    $dupe=$pdo->prepare("SELECT id FROM inventory WHERE code=? AND id<>? LIMIT 1 FOR UPDATE");$dupe->execute([$newCode,$id]);if($dupe->fetchColumn())throw new RuntimeException('Kode aset sudah digunakan.');
                    $fields[]='code=?';$params[]=$newCode;
                }
                foreach(['name','category','location','unit','condition_status','notes'] as $field){if(array_key_exists($field,$input)){$value=trim((string)$input[$field]);if(in_array($field,['name','category','location','unit'],true)&&$value==='')throw new InvalidArgumentException('Kolom aset wajib tidak boleh kosong.');$fields[]="$field=?";$params[]=$value===''&&$field==='notes'?null:$value;}}
                if(array_key_exists('quantity',$input)){if((int)$input['quantity']<0)throw new InvalidArgumentException('Jumlah aset tidak boleh negatif.');$fields[]='quantity=?';$params[]=(int)$input['quantity'];}
                if(array_key_exists('purchase_date',$input)){$value=trim((string)$input['purchase_date']);if($value!==''&&!validIsoDate($value))throw new InvalidArgumentException('Tanggal pembelian aset tidak valid.');$fields[]='purchase_date=?';$params[]=$value===''?null:$value;}
                if(array_key_exists('price',$input)){if((float)$input['price']<0)throw new InvalidArgumentException('Harga aset tidak boleh negatif.');$fields[]='price=?';$params[]=(float)$input['price'];}
                if(!$fields){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Tidak ada data aset yang diubah!']);$pdo->rollBack();break;}
                $params[]=$id;
                $pdo->prepare('UPDATE inventory SET '.implode(',',$fields).' WHERE id=?')->execute($params);
                $afterStmt=$pdo->prepare("SELECT * FROM inventory WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui aset inventaris','inventory',$id,$old,$after);
                logActivity($pdo,'update_inventory','Mengubah data aset: '.($after['name']??$old['name'])." (ID: $id)",$loggedInStaff['id']??null,$loggedInStaff['name']??null);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Aset hotel berhasil diperbarui!','db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            } catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal memperbarui aset',$e)]);
            }
        } elseif ($method === 'DELETE') {
            requireRoles($loggedInStaff, ['admin','manager']);
            $id=trim((string)($_GET['id']??$input['id']??''));
            if($id===''){http_response_code(400);echo json_encode(['success'=>false,'message'=>'ID Aset harus disertakan!']);break;}
            try{
                $pdo->beginTransaction();
                $assetStmt=$pdo->prepare("SELECT * FROM inventory WHERE id=? LIMIT 1 FOR UPDATE");$assetStmt->execute([$id]);$asset=$assetStmt->fetch(PDO::FETCH_ASSOC);
                if(!$asset){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Aset tidak ditemukan!']);$pdo->rollBack();break;}
                $maintenanceStmt=$pdo->prepare("SELECT * FROM inventory_maintenance WHERE inventory_id=? FOR UPDATE");$maintenanceStmt->execute([$id]);$maintenanceRows=$maintenanceStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                $maintenanceIds=array_values(array_filter(array_map(static fn($row)=>(string)($row['id']??''),$maintenanceRows)));
                $transactionRows=[];
                if($maintenanceIds){
                    $ph=implode(',',array_fill(0,count($maintenanceIds),'?'));
                    $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId IN ($ph) FOR UPDATE");$txStmt->execute($maintenanceIds);$transactionRows=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                    if($transactionRows){
                        http_response_code(409);
                        throw new RuntimeException('Aset mempunyai log pemeliharaan yang sudah diposting ke keuangan. Aset tidak boleh dihapus karena akan memutus histori transaksi/jurnal; pertahankan aset sebagai arsip dan lakukan koreksi keuangan dari workflow sumber.');
                    }
                    if(tamasyaTableExists($pdo,'approval_requests')){
                        $approvalStmt=$pdo->prepare("SELECT COUNT(*) FROM approval_requests WHERE entity_type='inventory_maintenance' AND entity_id IN ($ph) AND status='pending'");
                        $approvalStmt->execute($maintenanceIds);
                        if((int)$approvalStmt->fetchColumn()>0){
                            http_response_code(409);
                            throw new RuntimeException('Aset mempunyai usulan biaya pemeliharaan yang masih menunggu approval. Putuskan/batalkan approval sebelum menghapus aset.');
                        }
                    }
                }
                $pdo->prepare("DELETE FROM inventory_maintenance WHERE inventory_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM inventory WHERE id=?")->execute([$id]);
                $tombstones=[['type'=>'inventory','id'=>$id]];
                foreach($maintenanceIds as $maintenanceId)$tombstones[]=['type'=>'inventory_maintenance','id'=>$maintenanceId];
                foreach($transactionRows as $transactionRow){$transactionId=(string)($transactionRow['id']??'');if($transactionId!=='')$tombstones[]=['type'=>'transactions','id'=>$transactionId];}
                insertSyncTombstones($pdo,$tombstones,'direct_inventory_delete_'.substr(hash('sha256',$id),0,40),(string)($loggedInStaff['id']??''));
                $notifId=generateServerId('n_inv_del');$notifMsg='Aset "'.$asset['name'].'" ('.$asset['code'].') telah dihapus dari sistem beserta seluruh log perawatannya.';
                $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'system')")->execute([$notifId,$notifMsg,date('Y-m-d H:i:s')]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus aset inventaris','inventory',$id,['asset'=>$asset,'maintenance'=>$maintenanceRows,'transactions'=>$transactionRows],['deleted'=>true,'tombstones'=>count($tombstones)]);
                logActivity($pdo,'delete_inventory','Menghapus aset hotel: '.$asset['name'].' ('.$asset['code'].')',$loggedInStaff['id']??null,$loggedInStaff['name']??null);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Aset berhasil dihapus beserta riwayat perawatannya!','db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal menghapus aset',$e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(['message'=>'Method Not Allowed']);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/DELETE /api/inventory-maintenance ATAU api.php?action=inventory-maintenance
    // Log Pemeliharaan & Perbaikan Aset Hotel
    // ----------------------------------------------------------------
    case 'inventory-maintenance':
        $method=$_SERVER['REQUEST_METHOD'];
        requireRoles($loggedInStaff,['admin','manager','finance','cleaning_service']);
        if($method==='POST'){
            $maint=is_array($input['maintenance']??null)?$input['maintenance']:[];
            $inventoryId=trim((string)($input['inventory_id']??$maint['inventory_id']??''));
            $maintDate=trim((string)($input['maintenance_date']??$maint['maintenance_date']??date('Y-m-d')));
            $actionTaken=trim((string)($input['action_taken']??$maint['action_taken']??''));
            $cost=(float)($input['cost']??$maint['cost']??0);
            $staffName=trim((string)($input['staff_name']??$maint['staff_name']??''));
            $assetConditionAfter=trim((string)($input['asset_condition_after']??$maint['asset_condition_after']??''));
            $notes=isset($input['notes'])?trim((string)$input['notes']):(isset($maint['notes'])?trim((string)$maint['notes']):null);
            $recordAsExpense=!empty($input['recordAsExpense']);
            $bankAccountId=isset($input['bankAccountId'])?trim((string)$input['bankAccountId']):'';
            $paymentMethod=$recordAsExpense?tamasyaInventoryMaintenancePaymentMethod($pdo,$input['paymentMethod']??'', $bankAccountId):'payable';
            $shiftSessionId=isset($input['shiftSessionId'])?trim((string)$input['shiftSessionId']):null;
            if($recordAsExpense && strtolower((string)($loggedInStaff['role']??''))==='cleaning_service' && $paymentMethod==='cash')throw new InvalidArgumentException('Cleaning Service tidak boleh menandai biaya maintenance inventaris sebagai kas sudah dibayar; pilih Hutang/Payable atau ajukan sumber non-tunai yang dapat diverifikasi.');
            if($inventoryId===''||$actionTaken===''||$staffName===''){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Format input perawatan salah / tidak lengkap!']);break;}
            if(!validIsoDate($maintDate)||$cost<0||strlen($assetConditionAfter)>50){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Tanggal, biaya, atau status kondisi aset tidak valid.']);break;}
            $telegramMessage=null;
            try{
                $pdo->beginTransaction();
                $assetStmt=$pdo->prepare("SELECT * FROM inventory WHERE id=? LIMIT 1 FOR UPDATE");$assetStmt->execute([$inventoryId]);$asset=$assetStmt->fetch(PDO::FETCH_ASSOC);
                if(!$asset){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Aset sasaran tidak ditemukan!']);$pdo->rollBack();break;}
                $maintId=generateServerId('maint');
                $pdo->prepare("INSERT INTO inventory_maintenance (id,inventory_id,maintenance_date,action_taken,cost,staff_name,notes) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$maintId,$inventoryId,$maintDate,$actionTaken,$cost,$staffName,$notes]);
                if($assetConditionAfter!==''){
                    $pdo->prepare("UPDATE inventory SET condition_status=?,updated_at=CURRENT_TIMESTAMP,updated_by_staff_id=?,updated_source='maintenance' WHERE id=?")
                        ->execute([$assetConditionAfter,(string)($loggedInStaff['id']??''),$inventoryId]);
                }
                $maintenanceApprovalId=null;$expenseTransactionId=null;
                // Cleaning service boleh mencatat pekerjaan dan usulan biaya,
                // tetapi tidak boleh membuat atau menghapus transaksi kas secara langsung.
                if($recordAsExpense&&$cost>0){
                    if(in_array($loggedInStaff['role']??'', ['admin','manager','finance'],true)){
                        $expenseTransactionId=createInventoryMaintenanceExpense($pdo,$maintId,$cost,$loggedInStaff,$paymentMethod,$bankAccountId,$shiftSessionId);
                        $telegramMessage="🧾 *BIAYA BARU* (Perawatan Aset)\n\n🛠️ Aset: *{$asset['name']}* ({$asset['code']})\n🔧 Pemeliharaan: *{$actionTaken}*\n💰 Biaya: *Rp ".number_format($cost,0,',','.')."*\n👤 Pelaksana: *{$staffName}*\n📅 Tanggal: {$maintDate}";
                    }else{
                        $maintenanceApprovalId=generateServerId('approval');
                        $pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,'maintenance_expense','inventory_maintenance',?,?,?, ?,?,?,'pending',CURRENT_TIMESTAMP)")
                            ->execute([$maintenanceApprovalId,$maintId,$cost,'Biaya perawatan aset memerlukan persetujuan finance/manager',json_encode(['actualCost'=>$cost,'paymentMethod'=>$paymentMethod,'bankAccountId'=>$bankAccountId,'shiftSessionId'=>$shiftSessionId],JSON_UNESCAPED_UNICODE),$loggedInStaff['id'],$loggedInStaff['name']]);
                    }
                }
                $notifId=generateServerId('n_maint');$notifMsg='Tindakan pemeliharaan dicatat untuk '.$asset['name'].': '.$actionTaken.' dengan biaya Rp '.number_format($cost,0,',','.').'.';
                $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'finance')")->execute([$notifId,$notifMsg,date('Y-m-d H:i:s')]);
                $afterStmt=$pdo->prepare("SELECT * FROM inventory_maintenance WHERE id=? LIMIT 1");$afterStmt->execute([$maintId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$maintId,'inventory_id'=>$inventoryId];
                $assetAfterStmt=$pdo->prepare("SELECT id,condition_status,updated_at,updated_by_staff_id,updated_source FROM inventory WHERE id=? LIMIT 1");
                $assetAfterStmt->execute([$inventoryId]);
                $after['approvalRequestId']=$maintenanceApprovalId;$after['expenseTransactionId']=$expenseTransactionId;
                $after['assetCondition']=['before'=>$asset['condition_status']??null,'after'=>($assetAfterStmt->fetch(PDO::FETCH_ASSOC)?:[])];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mencatat pemeliharaan inventaris','inventory_maintenance',$maintId,null,$after);
                logActivity($pdo,'create_maintenance','Mencatat pemeliharaan aset '.$asset['name'].' ('.$maintId.') oleh '.$staffName,$loggedInStaff['id']??null,$loggedInStaff['name']??null);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                if($telegramMessage!==null)broadcastTelegramNotification($pdo,$telegramMessage,true);
                echo json_encode(['success'=>true,'message'=>$maintenanceApprovalId?'Log pemeliharaan tersimpan. Biaya menunggu persetujuan Finance/Manager.':'Log pemeliharaan berhasil dicatat dan terintegrasi keuangan!','approvalRequestId'=>$maintenanceApprovalId,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal mencatat pemeliharaan',$e)]);
            }
        }elseif($method==='DELETE'){
            requireRoles($loggedInStaff, ['admin','manager']);
            $id=trim((string)($_GET['id']??$input['id']??''));
            if($id===''){http_response_code(400);echo json_encode(['success'=>false,'message'=>'ID Log Perawatan harus disertakan!']);break;}
            try{
                $pdo->beginTransaction();
                $maintStmt=$pdo->prepare("SELECT * FROM inventory_maintenance WHERE id=? LIMIT 1 FOR UPDATE");$maintStmt->execute([$id]);$maintenance=$maintStmt->fetch(PDO::FETCH_ASSOC);
                if(!$maintenance){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Log perawatan tidak ditemukan!']);$pdo->rollBack();break;}
                $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId=? FOR UPDATE");$txStmt->execute([$id]);$transactions=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                if($transactions){
                    http_response_code(409);
                    throw new RuntimeException('Log perawatan sudah menghasilkan transaksi keuangan. Jangan hapus transaksi/jurnalnya; pertahankan log sebagai audit trail dan lakukan koreksi keuangan dari workflow sumber.');
                }
                if(tamasyaTableExists($pdo,'approval_requests')){
                    $approvalStmt=$pdo->prepare("SELECT COUNT(*) FROM approval_requests WHERE entity_type='inventory_maintenance' AND entity_id=? AND status='pending'");
                    $approvalStmt->execute([$id]);
                    if((int)$approvalStmt->fetchColumn()>0){
                        http_response_code(409);
                        throw new RuntimeException('Log perawatan masih mempunyai approval biaya pending. Putuskan/batalkan approval terlebih dahulu.');
                    }
                }
                $pdo->prepare("DELETE FROM inventory_maintenance WHERE id=?")->execute([$id]);
                $tombstones=[['type'=>'inventory_maintenance','id'=>$id]];
                insertSyncTombstones($pdo,$tombstones,'direct_maintenance_delete_'.substr(hash('sha256',$id),0,40),(string)($loggedInStaff['id']??''));
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus draft pemeliharaan inventaris tanpa transaksi keuangan','inventory_maintenance',$id,['maintenance'=>$maintenance],['deleted'=>true,'tombstones'=>1]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Draft log perawatan tanpa transaksi keuangan berhasil dihapus!','db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal menghapus log perawatan',$e)]);
            }
        }else{
            http_response_code(405);
            echo json_encode(['message'=>'Method Not Allowed']);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST /api/config ATAU api.php?action=config
    // Konfigurasi API & System Keys
    // ----------------------------------------------------------------
}
