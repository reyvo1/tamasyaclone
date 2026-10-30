<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'telegram-bot',
  1 => 'telegram-callback',
  2 => 'telegram-clear',
  3 => 'telegram-webhook',
), true)) { return; }
$__f = tamasyaResolveDomainSupportFile('058_employee_self_service.php'); if ($__f) { require_once $__f; } unset($__f);
$routeHandled = true;
$telegramSimulationAllowed = static function (): bool {
    $appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
    if (in_array($appEnv, ['production','prod'], true)) return false;
    if (!in_array($appEnv, ['staging','development','dev','test','local'], true)) return false;
    return filter_var(getenv('TELEGRAM_SIMULATION_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
};
switch ($action) {
    case 'telegram-bot':
        // Simulator: allow unknown users for testing unbound identity scenarios
        // Real operations still require proper binding via /bind KODE
        if (!$telegramSimulationAllowed()) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Simulator Telegram tidak tersedia pada produksi dan hanya dapat diaktifkan pada staging/development."]);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Metode tidak diizinkan"]);
            break;
        }

        $text = $input['text'] ?? '';
        if (empty($text)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Teks pesan tidak boleh kosong!"]);
            break;
        }

        $chatId = isset($input['chatId']) ? (int)$input['chatId'] : 6732841;
        // Role dari browser sengaja tidak dipercaya. Identitas simulasi tetap
        // diselesaikan server melalui binding Telegram User ID -> staf aktif.

        try {
            // Log user message to db
            $userMsgId = generateServerId('tg_sim_u');
            $stmt = $pdo->prepare("INSERT INTO telegram_messages (id, sender, `text`, timestamp, isAi) VALUES (?, 'user', ?, ?, 0)");
            $stmt->execute([$userMsgId, $text, date("Y-m-d H:i:s")]);
        } catch (Exception $e) {
            // Ignore logging errors and proceed
        }

        // Setup update payload for simulation
        $update = [
            "message" => [
                "chat" => [
                    "id" => $chatId
                ],
                "from" => [
                    "id" => $chatId
                ],
                "text" => $text,
                "message_id" => time()
            ]
        ];

        $GLOBALS['is_telegram_simulation'] = true;
        goto telegram_webhook_entry;

    // ----------------------------------------------------------------
    // POST /api/telegram-callback ATAU api.php?action=telegram-callback
    // Simulasi Click Tombol Bot Telegram (Inline Keyboards)
    // ----------------------------------------------------------------
    case 'telegram-callback':
        // Endpoint simulator callback. It is only ever valid where simulation is
        // enabled: the payload below deliberately carries no update_id, so the
        // production rails (update identity, claimTelegramUpdate idempotency,
        // primary-cluster fencing, rate limit, private-chat gate) are switched
        // off for this path. Refusing it outside simulation keeps those rails
        // mandatory for every other caller, including authenticated admins.
        $simulationMode = $telegramSimulationAllowed();
        if (!$simulationMode) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Simulator Telegram tidak tersedia pada produksi dan hanya dapat diaktifkan pada staging/development."]);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Metode tidak diizinkan"]);
            break;
        }

        $callbackData = $input['callbackData'] ?? '';
        $chatId = isset($input['chatId']) ? (int)$input['chatId'] : 6732841;
        $messageId = $input['messageId'] ?? '';

        // In simulation mode, resolve staff from telegram binding if not already logged in
        if ($simulationMode && !$loggedInStaff) {
            $loggedInStaff = findActiveStaffByTelegramUserId($pdo, (string)$chatId);
            if (!$loggedInStaff) {
                http_response_code(401);
                echo json_encode(["success" => false, "error" => "Telegram ID belum terikat ke akun staf. Hubungi Admin."]);
                break;
            }
            // Cache only staff resolved from the simulated Telegram identity itself.
            // A staff loaded from the web session must never be reused here, or an
            // unknown Telegram id would silently inherit the session operator and
            // the identity would report itself bound.
            $GLOBALS['tamasya_sim_callback_staff'] = $loggedInStaff;
        }

        // Setup update payload for callback query simulation
        $update = [
            "callback_query" => [
                "id" => generateServerId('cb'),
                "from" => [
                    "id" => $chatId
                ],
                "message" => [
                    "chat" => [
                        "id" => $chatId
                    ],
                    "message_id" => $messageId,
                    "text" => "Simulated callback message"
                ],
                "data" => $callbackData
            ]
        ];

        $GLOBALS['is_telegram_simulation'] = true;
        goto telegram_webhook_entry;

    // ----------------------------------------------------------------
    // POST /api/telegram-clear ATAU api.php?action=telegram-clear
    // Bersihkan Riwayat Chat Bot Simulator
    // ----------------------------------------------------------------
    case 'telegram-clear':
        requireRoles($loggedInStaff, ['admin']);
        if (!$telegramSimulationAllowed()) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Simulator Telegram tidak tersedia pada produksi dan hanya dapat diaktifkan pada staging/development."]);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Metode tidak diizinkan"]);
            break;
        }

        try {
            $pdo->beginTransaction();
            $lockStmt = $pdo->query("SELECT id FROM telegram_messages ORDER BY id FOR UPDATE");
            $lockedIds = $lockStmt ? $lockStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $before = [
                'messageCount' => count($lockedIds),
                'idHash' => hash('sha256', implode('|', array_map('strval', $lockedIds)))
            ];

            $pdo->exec("DELETE FROM telegram_messages");

            // Insert starter message kembali pada transaksi yang sama.
            $stmt = $pdo->prepare("INSERT INTO telegram_messages (id, sender, `text`, timestamp) VALUES ('tg1', 'user', '/start', ?), ('tg2', 'bot', 'Telegram Bot diaktifkan.', ?)");
            $now = date("Y-m-d H:i:s");
            $stmt->execute([$now, $now]);
            $after = [
                'messageCount' => 2,
                'idHash' => hash('sha256', 'tg1|tg2')
            ];
            writeRequiredEnterpriseAudit(
                $pdo,
                $loggedInStaff,
                'Membersihkan riwayat simulator Telegram',
                'telegram_simulator_history',
                'global',
                $before,
                $after,
                'web'
            );
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);

            echo json_encode(getRoleScopedHotelData($pdo, $loggedInStaff));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal membersihkan riwayat", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/login ATAU api.php?action=login
    // Autentikasi Staf {$propertyName}
    // ----------------------------------------------------------------
    case 'telegram-webhook':
        telegram_webhook_entry:
            try {
                $propertyName=tamasyaPropertyDisplayName($pdo);
            // Password tidak pernah diterima melalui Telegram. Existing
            // staff.telegram_chat_id tetap bekerja; binding baru memakai kode sekali pakai.
        // Verify Telegram secret header before processing public webhook requests.
        if (empty($GLOBALS['is_telegram_simulation']) && empty($GLOBALS['tamasya_cluster_forwarded_telegram'])) {
            ensureTelegramWebhookColumns($pdo);
            try {
                $stmtSecret = $pdo->query("SELECT telegram_webhook_secret FROM config WHERE id = 'system_default' LIMIT 1");
                $secretRow = $stmtSecret ? ($stmtSecret->fetch() ?: []) : [];
                $expectedSecret = trim((string)($secretRow['telegram_webhook_secret'] ?? ''));
                if ($expectedSecret === '') {
                    http_response_code(403);
                    echo json_encode(["success" => false, "error" => "Telegram webhook belum dikonfigurasi"]);
                    break;
                }
                $incomingSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
                if ($incomingSecret === '' || !hash_equals($expectedSecret, $incomingSecret)) {
                    http_response_code(403);
                    echo json_encode(["success" => false, "error" => "Invalid Telegram webhook secret"]);
                    break;
                }
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(["success" => false, "error" => "Webhook secret validation failed"]);
                break;
            }
        }
        if (empty($GLOBALS['is_telegram_simulation']) && empty($GLOBALS['tamasya_cluster_forwarded_telegram']) && tamasyaClusterEnabled() && tamasyaNodeRole()==='local_backup') {
            $clusterForward=tamasyaClusterForwardTelegramWebhook((string)$rawRequestBody);
            if (empty($clusterForward['ok'])) {
                http_response_code((int)($clusterForward['status']??0)>0?(int)$clusterForward['status']:503);
                echo json_encode(['success'=>false,'error'=>'Primary aktif tidak dapat memproses Telegram: '.($clusterForward['json']['error']??$clusterForward['error']??'koneksi gagal')]);
            } else {
                http_response_code((int)($clusterForward['status']??200));
                echo (string)($clusterForward['body']??json_encode(['success'=>true]));
            }
            break;
        }
        // Webhook receives data from Telegram servers as POST JSON
        if (!isset($update)) {
            $update = $input;
        }

        // Telegram selalu menyertakan update_id untuk pesan/callback asli. Tanpa
        // identitas ini claimTelegramUpdate() dan lock Primary akan dilewati;
        // semua mutasi (booking, kas, shift) dapat dieksekusi berulang.
        // Simulator admin memang tidak mempunyai update_id dan tetap terisolasi.
        if (empty($GLOBALS['is_telegram_simulation'])) {
            try { $telegramUpdateIdentity=tamasyaRequireTelegramUpdateId($update); }
            catch (InvalidArgumentException $invalidTelegramUpdate) {
                http_response_code(400);
                echo json_encode(['success'=>false,'error'=>'Telegram update_id tidak valid; transaksi tidak diproses.']);
                break;
            }
        }

        // UX lapangan: hentikan spinner callback secepat mungkin sebelum menunggu
        // mutation lock/DB. Ini hanya acknowledgement ke Telegram, tidak membaca
        // atau mengubah data hotel dan tidak melewati Primary/Standby safety gate.
        $telegramCallbackEarlyAcked = false;
        if (empty($GLOBALS['is_telegram_simulation']) && isset($update['callback_query']['id'])) {
            $earlyChatType = strtolower((string)($update['callback_query']['message']['chat']['type'] ?? ''));
            if ($earlyChatType === 'private') {
                try {
                    $earlyTokenStmt = $pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1");
                    $earlyTokenRow = $earlyTokenStmt ? ($earlyTokenStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
                    $earlyToken = trim(decryptStoredSecret((string)($earlyTokenRow['telegram_bot_token'] ?? '')));
                    if ($earlyToken !== '') {
                        $earlyAckResult = telegramApiCall($earlyToken,'answerCallbackQuery',[
                            'callback_query_id'=>(string)$update['callback_query']['id'],
                            'text'=>'⏳ Memproses…'
                        ],4);
                        $telegramCallbackEarlyAcked = !empty($earlyAckResult['ok']);
                    }
                } catch (Throwable $earlyAckError) {
                    // Best-effort saja. Handler utama tetap menjadi sumber kebenaran.
                    error_log('[Telegram Callback Early ACK] '.clientExceptionMessage('gagal',$earlyAckError));
                }
            }
        }

        // Telegram dapat mengirim update yang sama berulang kali ketika webhook
        // timeout. Klaim update_id mencegah booking/kas ganda. Worker yang masih
        // berjalan dibalas 503 agar Telegram mencoba lagi, bukan dianggap sukses palsu.
        if (empty($GLOBALS['is_telegram_simulation'])) {
            $telegramUpdateId = $telegramUpdateIdentity;
            // The identity is required and validated before any callback ACK or
            // mutation. A missing value must never bypass the Primary lock.
            {
                // Telegram dapat mengubah booking, kas, kamar, shift, dan housekeeping.
                // Gunakan lock yang sama dengan request web dan snapshot supaya mirror
                // tidak membaca commit Telegram sebelum revision diumumkan.
                $telegramPrimaryLockStartedAt = microtime(true);
                $telegramPrimaryLockHeld = tamasyaAcquirePrimaryMutationLock($pdo, 10);
                $GLOBALS['tamasya_telegram_latency_lock_ms'] = (int)round((microtime(true)-$telegramPrimaryLockStartedAt)*1000);
                if (!$telegramPrimaryLockHeld) {
                    http_response_code(503);
                    echo json_encode(["success" => false, "error" => "Server sedang menyelesaikan transaksi lain; Telegram akan mencoba kembali."]);
                    break;
                }
                $claimStatus = claimTelegramUpdate($pdo, $telegramUpdateId);
                if ($claimStatus === 'completed') {
                    tamasyaReleasePrimaryMutationLock($pdo);
                    echo json_encode(["success" => true, "status" => "duplicate_update_already_completed"]);
                    break;
                }
                if ($claimStatus === 'busy' || $claimStatus === 'error') {
                    tamasyaReleasePrimaryMutationLock($pdo);
                    http_response_code(503);
                    echo json_encode(["success" => false, "error" => "Telegram update sedang diproses; silakan retry"]);
                    break;
                }
                if (function_exists('tamasyaCommunicationMirrorTelegramEvent')) tamasyaCommunicationMirrorTelegramEvent($pdo, $update);
                registerTelegramUpdateCompletion($pdo, $telegramUpdateId, true);
            }
        }
        $isCallback = isset($update['callback_query']);
        $isMyChatMember = isset($update['my_chat_member']);
        $isChatMember = isset($update['chat_member']);
        $hasNewMembers = isset($update['message']['new_chat_members']);
        
        if (!$isCallback && !$isMyChatMember && !$isChatMember && !$hasNewMembers && (!isset($update['message']) || (!isset($update['message']['text']) && !isset($update['message']['photo'])))) {
            echo json_encode(["success" => true, "status" => "Ignore non-text/non-photo, callback, or member updates"]);
            break;
        }

        // Process webhook synchronously.
        // We do NOT use fastcgi_finish_request or early connection closing headers here because on serverless
        // container hosts like Google Cloud Run, closing the connection causes the host to immediately throttle
        // the CPU to 0, which freezes the container and prevents the bot from sending messages back to Telegram.
        // Keeping the connection open ensures full CPU allocation is maintained until the response completes.
        ignore_user_abort(true);
        set_time_limit(180);

        try {
            $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmt->fetch();
            $token = $confRow ? trim(decryptStoredSecret($confRow['telegram_bot_token'])) : '';
            $geminiKey = $confRow ? trim(decryptStoredSecret($confRow['gemini_api_key'])) : '';
            if (empty($token)) {
                if (!empty($GLOBALS['is_telegram_simulation'])) {
                    $token = "simulation-token-disabled";
                } else {
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Token bot Telegram belum dikonfigurasi"]);
                    break;
                }
            }

            // Define custom keyboards for real Telegram bot (matches PC simulator)
            $userKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🔗 Hubungkan Akun"]],
                    [["text" => "🏠 Menu Utama"], ["text" => "🤖 Tanya AI"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];

             $repsKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🛒 Jual Kamar"]],
                    [["text" => "📅 Reservasi"]],
                    [["text" => "⏳ Perpanjang Sewa"], ["text" => "🛠️ Tambah Layanan"]],
                    [["text" => "🔄 Pindah Kamar"], ["text" => "🧹 Housekeeping"]],
                    [["text" => "🚪 Check-out Kamar"], ["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🧾 Cetak Nota"], ["text" => "🪪 Lihat KTP"]],
                    [["text" => "💵 Terima Panjar"]],
                    [["text" => "🔑 Buka Shift"], ["text" => "📝 Tutup Shift"]],
                    [["text" => "🚨 Patroli & Laporan"], ["text" => "🤖 Tanya AI"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];

            $adminKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🛒 Jual Kamar"]],
                    [["text" => "📅 Reservasi"]],
                    [["text" => "⏳ Perpanjang Sewa"], ["text" => "🛠️ Tambah Layanan"]],
                    [["text" => "🔄 Pindah Kamar"], ["text" => "🧹 Housekeeping"]],
                    [["text" => "🚪 Check-out Kamar"], ["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🧾 Cetak Nota"], ["text" => "🪪 Lihat KTP"]],
                    [["text" => "💵 Terima Panjar"]],
                    [["text" => "🔑 Buka Shift"], ["text" => "📝 Tutup Shift"]],
                    [["text" => "📥 Input Pemasukan"], ["text" => "📤 Input Pengeluaran"]],
                    [["text" => "📈 Laporan Kas"], ["text" => "📋 Riwayat Shift"]],
                    [["text" => "🚨 Patroli & Laporan"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout Admin"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];

            $financeKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "📈 Laporan Kas"]],
                    [["text" => "📥 Input Pemasukan"], ["text" => "📤 Input Pengeluaran"]],
                    [["text" => "🧾 Cetak Nota"]],
                    [["text" => "💵 Terima Panjar"]],
                    [["text" => "🔑 Buka Shift"], ["text" => "📝 Tutup Shift"]],
                    [["text" => "📋 Riwayat Shift"]],
                    [["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];
            // Owner melihat susunan menu selengkap admin (read-only). Setiap
            // tombol perubahan tetap ditolak di gerbang callback/command.
            $ownerKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🛒 Jual Kamar"]],
                    [["text" => "📅 Reservasi"]],
                    [["text" => "⏳ Perpanjang Sewa"], ["text" => "🛠️ Tambah Layanan"]],
                    [["text" => "🔄 Pindah Kamar"], ["text" => "🧹 Housekeeping"]],
                    [["text" => "🚪 Check-out Kamar"], ["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🧾 Cetak Nota"], ["text" => "🪪 Lihat KTP"]],
                    [["text" => "💵 Terima Panjar"]],
                    [["text" => "🔑 Buka Shift"], ["text" => "📝 Tutup Shift"]],
                    [["text" => "📥 Input Pemasukan"], ["text" => "📤 Input Pengeluaran"]],
                    [["text" => "📈 Laporan Kas"], ["text" => "📋 Riwayat Shift"]],
                    [["text" => "🚨 Patroli & Laporan"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];
            $housekeepingKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🧹 Housekeeping"]],
                    [["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];
            $securityKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🚨 Patroli & Laporan"]],
                    [["text" => "🚪 Kamar Kosong"], ["text" => "🪪 Lihat KTP"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];
            $basicStaffKeyboard = [
                "keyboard" => [
                    [["text" => "📊 Cek Kamar"], ["text" => "🚪 Kamar Kosong"]],
                    [["text" => "🏠 Menu Utama"]],
                    [["text" => "🤖 Tanya AI"], ["text" => "🔒 Logout"]]
                ],
                "resize_keyboard" => true,
                "one_time_keyboard" => false
            ];
            $withEmployeeSelfServiceKeyboard = static function(array $keyboard): array {
                $rows = is_array($keyboard['keyboard'] ?? null) ? $keyboard['keyboard'] : [];
                $insertAt = max(0, count($rows) - 1);
                array_splice($rows, $insertAt, 0, [[["text" => "🗓️ Cuti Saya"]]]);
                $keyboard['keyboard'] = $rows;
                return $keyboard;
            };
            $repsKeyboard = $withEmployeeSelfServiceKeyboard($repsKeyboard);
            $adminKeyboard = $withEmployeeSelfServiceKeyboard($adminKeyboard);
            $financeKeyboard = $withEmployeeSelfServiceKeyboard($financeKeyboard);
            $housekeepingKeyboard = $withEmployeeSelfServiceKeyboard($housekeepingKeyboard);
            $securityKeyboard = $withEmployeeSelfServiceKeyboard($securityKeyboard);
            $basicStaffKeyboard = $withEmployeeSelfServiceKeyboard($basicStaffKeyboard);

            $telegramPlainText = static function($value): string {
                $value = trim((string)$value);
                return str_replace(['\\','`','*','_','[',']'], ['',"'",'', ' ', '(', ')'], $value);
            };
            $telegramRoleLabel = static function(string $role): string {
                return [
                    'admin'=>'Administrator','manager'=>'Manajer','receptionist'=>'Resepsionis','finance'=>'Keuangan',
                    'cleaning_service'=>'Housekeeping','keamanan'=>'Keamanan','koki'=>'Koki','tukang_kebun'=>'Tukang Kebun','lain_lain'=>'Staf'
                ][strtolower($role)] ?? 'Staf';
            };
            $telegramPaymentStatusLabel = static function(string $status): string {
                return ['paid'=>'LUNAS','unpaid'=>'BELUM LUNAS','partial'=>'SEBAGIAN','refunded'=>'DIKEMBALIKAN'][strtolower($status)] ?? strtoupper($status);
            };
            $telegramPaymentMethodLabel = static function(string $method): string {
                return ['cash'=>'TUNAI','transfer'=>'TRANSFER','qris'=>'QRIS','edc'=>'EDC','none'=>'TANPA METODE'][strtolower($method)] ?? strtoupper($method);
            };
            $telegramRoomStatusLabel = static function(string $status): string {
                return ['available'=>'TERSEDIA','booked'=>'TERISI','dirty'=>'HOUSEKEEPING / QC','maintenance'=>'SERVIS / BLOCKER'][strtolower($status)] ?? strtoupper($status);
            };
            $telegramLeaveStatusLabel = static function(string $status): string {
                return ['pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak','cancelled'=>'Dibatalkan'][strtolower($status)] ?? ucfirst(strtolower($status));
            };
            $guestServiceTelegramRoles=['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'];
            $buildTelegramPagerRow = static function(int $page, int $totalPages, string $callbackPrefix): array {
                $page=max(1,$page);$totalPages=max(1,$totalPages);$nav=[];
                if($page>1)$nav[]=['text'=>'⬅️','callback_data'=>$callbackPrefix.($page-1)];
                $nav[]=['text'=>"📄 {$page}/{$totalPages}",'callback_data'=>$callbackPrefix.$page];
                if($page<$totalPages)$nav[]=['text'=>'➡️','callback_data'=>$callbackPrefix.($page+1)];
                return $nav;
            };
            $buildTelegramActiveBookingPicker = static function(
                PDO $pdo,
                string $callbackPrefix,
                string $pageCallbackPrefix,
                string $title,
                string $prompt,
                string $emptyText,
                int $page=1,
                int $pageSize=30
            ) use ($buildTelegramPagerRow, $telegramPaymentStatusLabel): array {
                $pageSize=max(6,min(30,$pageSize));
                $count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
                if($count<=0){
                    return ['text'=>$emptyText,'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]]];
                }
                $totalPages=max(1,(int)ceil($count/$pageSize));$page=min(max(1,$page),$totalPages);$offset=($page-1)*$pageSize;
                $stmt=$pdo->prepare("SELECT roomNumber,guestName,paymentStatus FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber LIMIT ? OFFSET ?");
                $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();
                $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                $buttons=[];$row=[];
                foreach($rows as $booking){
                    $payStatus=trim((string)($booking['paymentStatus']??'unpaid'));
                    $payIcon=$payStatus==='paid'?'✅':($payStatus==='partial'?'⚠️':'❌');
                    $label=$payIcon.' 🚪 '.(string)$booking['roomNumber'].' · '.trim((string)$booking['guestName']);
                    if(function_exists('mb_substr'))$label=mb_substr($label,0,42);else$label=substr($label,0,42);
                    $row[]=['text'=>$label,'callback_data'=>$callbackPrefix.(string)$booking['roomNumber']];
                    if(count($row)>=2){$buttons[]=$row;$row=[];}
                }
                if($row)$buttons[]=$row;
                if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,$pageCallbackPrefix);
                $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];
                $rangeStart=$offset+1;$rangeEnd=min($offset+$pageSize,$count);
                return [
                    'text'=>$title."\n\n".$prompt."\n📄 Halaman *{$page}/{$totalPages}* · kamar aktif *{$rangeStart}-{$rangeEnd}* dari *{$count}*.",
                    'markup'=>['inline_keyboard'=>$buttons]
                ];
            };
            $buildTelegramReservedBookingPicker = static function(
                PDO $pdo,
                int $page=1,
                int $pageSize=20
            ) use ($buildTelegramPagerRow, $telegramPlainText, $telegramPaymentStatusLabel): array {
                $pageSize=max(6,min(24,$pageSize));
                $count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='reserved'")->fetchColumn();
                if($count<=0){
                    return ['text'=>'📅 *DAFTAR RESERVASI*\n\nBelum ada booking berstatus reserved. Kamar yang hanya memiliki reservasi masa depan tetap dapat dipakai pada interval lain yang tidak bertabrakan.','markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]]];
                }
                $totalPages=max(1,(int)ceil($count/$pageSize));$page=min(max(1,$page),$totalPages);$offset=($page-1)*$pageSize;
                $stmt=$pdo->prepare("SELECT id,roomNumber,guestName,checkIn,checkOut,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt,paymentStatus,bookingSource FROM bookings WHERE status='reserved' ORDER BY checkIn,scheduledCheckInAt,CAST(roomNumber AS UNSIGNED),roomNumber,id LIMIT ? OFFSET ?");
                $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();
                $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$buttons=[];$summary=[];
                foreach($rows as $booking){
                    $guest=$telegramPlainText($booking['guestName']??'Tamu');if(function_exists('mb_substr'))$guest=mb_substr($guest,0,48);else$guest=substr($guest,0,48);$room=(string)($booking['roomNumber']??'-');
                    $dateIn=(string)($booking['checkIn']??'');$dateOut=!empty($booking['isOpenEnded'])?'Terbuka':(string)($booking['checkOut']??'');
                    $summary[]='• Kamar *'.$room.'* · '.$guest.' · `'.$dateIn.'` → `'.$dateOut.'`';
                    $shortDate=$dateIn!==''?substr($dateIn,8,2).'/'.substr($dateIn,5,2):'--/--';
                    $label='📅 '.$room.' · '.$shortDate.' · '.$guest;if(function_exists('mb_substr'))$label=mb_substr($label,0,46);else$label=substr($label,0,46);
                    $buttons[]=[['text'=>$label,'callback_data'=>'reservation_detail:'.(string)$booking['id']]];
                }
                if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'reservation_list:p:');
                $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];
                $rangeStart=$offset+1;$rangeEnd=min($offset+$pageSize,$count);
                return ['text'=>"📅 *DAFTAR RESERVASI CANONICAL*\n\n".implode("\n",$summary)."\n\n📄 Halaman *{$page}/{$totalPages}* · reservasi *{$rangeStart}-{$rangeEnd}* dari *{$count}*.\n\nReservasi hanya menahan interval waktunya; status fisik kamar berubah menjadi Terisi hanya saat check-in.",'markup'=>['inline_keyboard'=>$buttons]];
            };

            $buildTelegramSellableRoomPicker = static function(
                PDO $pdo,
                string $itemCallbackPrefix,
                string $pageCallbackPrefix,
                string $title,
                string $prompt,
                string $emptyText,
                string $backCallback='guest_ops_menu',
                int $page=1,
                int $pageSize=30
            ) use ($buildTelegramPagerRow): array {
                $pageSize=max(6,min(30,$pageSize));
                $all=getOperationallySellableRooms($pdo);
                $count=count($all);
                if($count<=0){
                    return ['text'=>$emptyText,'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>$backCallback]]]]];
                }
                $totalPages=max(1,(int)ceil($count/$pageSize));$page=min(max(1,$page),$totalPages);$offset=($page-1)*$pageSize;
                $rows=array_slice($all,$offset,$pageSize);
                $buttons=[];$row=[];
                $roomListTaxRate=resolveConfiguredTaxRate($pdo,'Direct','room',0,date('Y-m-d'));
                foreach($rows as $room){
                    $quoted=tamasyaPublishedRoomGrossTotal((float)($room['price']??0),1,(float)$roomListTaxRate);
                    $label='🚪 '.(string)$room['number'].' · Rp '.number_format($quoted/1000,0,',','.').'k';
                    if(function_exists('mb_substr'))$label=mb_substr($label,0,42);else$label=substr($label,0,42);
                    $row[]=['text'=>$label,'callback_data'=>$itemCallbackPrefix.(string)$room['number']];
                    if(count($row)>=2){$buttons[]=$row;$row=[];}
                }
                if($row)$buttons[]=$row;
                if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,$pageCallbackPrefix);
                $backLabel=$backCallback==='guest_ops_menu'?'⬅️ Kamar & Tamu':'⬅️ Kembali';
                $buttons[]=[['text'=>$backLabel,'callback_data'=>$backCallback]];
                $rangeStart=$offset+1;$rangeEnd=min($offset+$pageSize,$count);
                return [
                    'text'=>$title."\n\n".$prompt."\n📄 Halaman *{$page}/{$totalPages}* · kamar tersedia *{$rangeStart}-{$rangeEnd}* dari *{$count}*.",
                    'markup'=>['inline_keyboard'=>$buttons]
                ];
            };

            $buildTelegramRoomPaymentStatusMenu = static function(
                PDO $pdo,
                int $page=1,
                string $filter='all',
                int $pageSize=30
            ) use ($buildTelegramPagerRow, $telegramPlainText, $telegramPaymentStatusLabel): array {
                try {
                    $pageSize=max(6,min(30,$pageSize));
                    $where=['1=1'];
                    $params=[];
                    if($filter==='unpaid'){
                        $where[]="(b.status='active' AND (b.paymentStatus='unpaid' OR b.paymentStatus='partial')) OR (b.status='reserved' AND (b.paymentStatus='unpaid' OR b.paymentStatus='partial'))";
                    } elseif ($filter==='active'){
                        $where[]="b.status='active'";
                    } elseif ($filter==='reserved'){
                        $where[]="b.status='reserved'";
                    }
                    $whereSql=implode(' AND ',$where);
                    $countSql="SELECT COUNT(DISTINCT r.number) FROM rooms r LEFT JOIN bookings b ON b.roomNumber=r.number AND (b.status='active' OR b.status='reserved') WHERE {$whereSql}";
                    $countStmt=$pdo->prepare($countSql);
                    $countStmt->execute($params);
                    $count=(int)$countStmt->fetchColumn();
                    if($count<=0){
                        $msg="💰 *STATUS PEMBAYARAN PER KAMAR*\n\nTidak ada kamar yang cocok dengan filter.";
                        return ['text'=>$msg,'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]]];
                    }
                    $totalPages=max(1,(int)ceil($count/$pageSize));
                    $page=min(max(1,$page),$totalPages);
                    $offset=($page-1)*$pageSize;
                    $sql="SELECT r.number,r.status,r.type,r.price,
                        b.id,b.guestName,b.status AS bookingStatus,b.paymentStatus,b.balanceDue,b.totalAmount,
                        COALESCE(b.paymentStatus,'unpaid') AS payStatus
                        FROM rooms r
                        LEFT JOIN bookings b ON b.roomNumber=r.number AND (b.status='active' OR b.status='reserved')
                        WHERE {$whereSql}
                        ORDER BY CASE r.status WHEN 'booked' THEN 1 WHEN 'maintenance' THEN 2 WHEN 'dirty' THEN 3 WHEN 'cleaning' THEN 4 ELSE 5 END,
                        CAST(r.number AS UNSIGNED),r.number
                        LIMIT ? OFFSET ?";
                    $stmt=$pdo->prepare($sql);
                    $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);
                    $stmt->bindValue(2,$offset,PDO::PARAM_INT);
                    foreach($params as $i=>$v)$stmt->bindValue(3+$i,$v);
                    $stmt->execute();
                    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $buttons=[];$row=[];$lines=[];
                    $lines[]="💰 *STATUS PEMBAYARAN PER KAMAR*";
                    $lines[]="";
                    foreach($rows as $room){
                        $roomNum=(string)($room['number']??'-');
                        $roomStatus=strtolower(trim((string)($room['status']??'available')));
                        $statusIcon=$roomStatus==='booked'?'🔴':($roomStatus==='available'?'🟢':($roomStatus==='maintenance'?'🛠️':($roomStatus==='dirty'?'🧹':($roomStatus==='cleaning'?'🧽':'⚪'))));
                        $bookingStatus=strtolower(trim((string)($room['bookingStatus']??'')));
                        $payStatus=trim((string)($room['payStatus']??'unpaid'));
                        $payIcon=$payStatus==='paid'?'✅':($payStatus==='partial'?'⚠️':'❌');
                        $guest=trim((string)($room['guestName']??''));
                        $balance=(float)($room['balanceDue']??0);
                        $total=(float)($room['totalAmount']??0);
                        $statusLabel='';
                        if($bookingStatus==='active')$statusLabel='Aktif';
                        elseif($bookingStatus==='reserved')$statusLabel='Reserved';
                        elseif($room['status']==='maintenance')$statusLabel='Maintenance';
                        elseif($room['status']==='dirty')$statusLabel='Dirty';
                        elseif($room['status']==='cleaning')$statusLabel='Cleaning';
                        else $statusLabel='Kosong';
                        $outstanding=$balance>0.5?number_format($balance,0,',','.'):'—';
                        $line="{$statusIcon} {$roomNum} {$payIcon} {$statusLabel} | {$guest} | {$outstanding}";
                        if(function_exists('mb_strlen') && mb_strlen($line)>60)$line=mb_substr($line,0,57).'...';
                        elseif(strlen($line)>60)$line=substr($line,0,57).'...';
                        $lines[]=$line;
                    }
                    $filterLabels=['all'=>'Semua','unpaid'=>'Belum Lunas','active'=>'Aktif','reserved'=>'Reserved'];
                    $filterLabel=$filterLabels[$filter]??'Semua';
                    $filterButtons=[
                        ['text'=>'📋 Semua','callback_data'=>'room_payment_status_filter:all'],
                        ['text'=>'❌ Belum Lunas','callback_data'=>'room_payment_status_filter:unpaid'],
                        ['text'=>'🔴 Aktif','callback_data'=>'room_payment_status_filter:active'],
                        ['text'=>'📅 Reserved','callback_data'=>'room_payment_status_filter:reserved'],
                    ];
                    $pagerButtons=$buildTelegramPagerRow($page,$totalPages,'room_payment_status_menu:p:');
                    $buttons=array_chunk($filterButtons,2);
                    if($pagerButtons)$buttons[]=$pagerButtons;
                    $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];
                    $rangeStart=$count>0?$offset+1:0;
                    $rangeEnd=min($offset+$pageSize,$count);
                    return [
                        'text'=>implode("\n",$lines)."\n\n📄 Halaman *{$page}/{$totalPages}* · kamar *{$rangeStart}-{$rangeEnd}* dari *{$count}* · Filter: *{$filterLabel}*.",
                        'markup'=>['inline_keyboard'=>$buttons]
                    ];
                } catch (Throwable $e) {
                    error_log('[room_payment_status_menu] Error in function: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
                    return [
                        'text'=>"[!] *GAGAL MEMUAT STATUS BAYAR*\n\nDetail: ".$e->getMessage()."\nFile: ".$e->getFile().':'.$e->getLine()."\nSilakan coba lagi atau hubungi admin.",
                        'markup'=>['inline_keyboard'=>[[['text'=>'[+] Coba Lagi','callback_data'=>'room_payment_status_menu'],['text'=>'[<] Kamar & Tamu','callback_data'=>'guest_ops_menu']]]]
                    ];
                }
            };
            $buildTelegramGuestServiceDetail = static function(PDO $pdo, array $staff, string $identifier) use ($telegramPlainText): array {
                $request=tamasyaGuestServiceRequestByIdentifier($pdo,$identifier,false);
                if(!$request)return ['text'=>'⚠️ Permintaan tamu tidak ditemukan.','markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']]]]];
                $id=(string)$request['id'];$status=strtolower(trim((string)($request['status']??'')));$role=strtolower((string)($staff['role']??''));$staffId=(string)($staff['id']??'');
                $assignee=trim((string)($request['assigned_to']??''));$assigneeName='';
                if($assignee!==''){$q=$pdo->prepare("SELECT name FROM staff WHERE id=? LIMIT 1");$q->execute([$assignee]);$assigneeName=trim((string)($q->fetchColumn()?:''));}
                $priority=strtoupper((string)($request['priority']??'normal'));$type=$telegramPlainText($request['request_type']??'Layanan tamu');$detail=$telegramPlainText($request['description']??'');
                $guest=$telegramPlainText($request['guest_name_snapshot']??($request['requester_name']??''));$room=trim((string)($request['room_number']??''));$location=$telegramPlainText($request['location_label']??'');$needed=trim((string)($request['needed_at']??''));
                $stateLabel=['open'=>'BARU','assigned'=>'DIAMBIL','in_progress'=>'DIKERJAKAN','fulfilled'=>'SELESAI','cancelled'=>'DIBATALKAN'][$status]??strtoupper($status?:'-');
                $context=[];if($guest!=='')$context[]=$guest;if($room!=='')$context[]='Kamar '.$room;elseif($location!=='')$context[]=$location;
                $lines=["🔔 *PERMINTAAN TAMU*","","Status: *{$stateLabel}*","Prioritas: *{$priority}*","Jenis: *{$type}*"];
                if($context)$lines[]='Konteks: *'.implode(' · ',$context).'*';if($needed!=='')$lines[]='Dibutuhkan: `'.$needed.'`';if($detail!=='')$lines[]='Detail: '.$detail;if($assignee!=='')$lines[]='PIC: *'.($assigneeName!==''?$telegramPlainText($assigneeName):$assignee).'*';
                $buttons=[];
                if($status==='open')$buttons[]=[['text'=>'📥 Ambil','callback_data'=>'gsa:'.$id]];
                elseif($status==='assigned'&&($assignee===$staffId||hasCapability($staff,'perform_housekeeping',['admin','manager'])))$buttons[]=[['text'=>'▶️ Mulai','callback_data'=>'gss:'.$id]];
                elseif($status==='in_progress'&&($assignee===''||$assignee===$staffId||hasCapability($staff,'perform_housekeeping',['admin','manager','receptionist'])))$buttons[]=[['text'=>'✅ Selesai','callback_data'=>'gsc:'.$id]];
                $buttons[]=[['text'=>'🔄 Muat Ulang','callback_data'=>'gsd:'.$id],['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']];
                return ['text'=>implode("\n",$lines),'markup'=>['inline_keyboard'=>$buttons]];
            };
            $buildTelegramGuestServiceMenu = static function(PDO $pdo, array $staff, int $page=1, int $pageSize=8) use ($buildTelegramPagerRow,$telegramPlainText): array {
                $pageSize=max(5,min(12,$pageSize));
                $count=(int)$pdo->query("SELECT COUNT(*) FROM guest_service_requests WHERE status IN ('open','assigned','in_progress')")->fetchColumn();
                if($count<=0)return ['text'=>"🔔 *PERMINTAAN TAMU*\n\nTidak ada antrean aktif.",'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Operasional','callback_data'=>'field_ops_menu']]]]];
                $totalPages=max(1,(int)ceil($count/$pageSize));$page=min(max(1,$page),$totalPages);$offset=($page-1)*$pageSize;
                $stmt=$pdo->prepare("SELECT id,request_type,priority,status,room_number,guest_name_snapshot,requester_name,needed_at FROM guest_service_requests WHERE status IN ('open','assigned','in_progress') ORDER BY FIELD(priority,'urgent','high','normal'),COALESCE(needed_at,'9999-12-31 23:59:59'),created_at,id LIMIT ? OFFSET ?");
                $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$buttons=[];$summary=[];
                foreach($rows as $row){$status=strtolower((string)$row['status']);$icon=$status==='open'?'🆕':($status==='assigned'?'📥':'▶️');$priority=strtolower((string)$row['priority']);if($priority==='urgent')$icon='🚨';elseif($priority==='high')$icon='⚠️';$room=trim((string)($row['room_number']??''));$guest=$telegramPlainText($row['guest_name_snapshot']??($row['requester_name']??''));$type=$telegramPlainText($row['request_type']??'Layanan tamu');$label=$icon.' '.($room!==''?'K'.$room.' · ':'').$type;if(function_exists('mb_substr'))$label=mb_substr($label,0,46);else$label=substr($label,0,46);$buttons[]=[['text'=>$label,'callback_data'=>'gsd:'.(string)$row['id']]];$summary[]='• '.$icon.' '.($room!==''?'Kamar *'.$room.'* · ':'').$type.($guest!==''?' · '.$guest:'');}
                if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'gsm:p:');$buttons[]=[['text'=>'🔄 Muat Ulang','callback_data'=>'gsm:p:'.$page],['text'=>'⬅️ Operasional','callback_data'=>'field_ops_menu']];
                return ['text'=>"🔔 *ANTREAN PERMINTAAN TAMU*\n\n".implode("\n",$summary)."\n\nAktif: *{$count}* · halaman *{$page}/{$totalPages}*.",'markup'=>['inline_keyboard'=>$buttons]];
            };

            $buildTelegramMainMenuMarkup = static function(?array $staff) use ($guestServiceTelegramRoles): array {
                $buttons=[];
                if(!$staff){
                    $buttons[]=[["text"=>"📊 Cek Kamar","callback_data"=>"room_status_menu"],["text"=>"🤖 Tanya AI","callback_data"=>"ai_prompt"]];
                    $buttons[]=[["text"=>"🔗 Hubungkan Akun","callback_data"=>"login_as_id:binding_required"]];
                    return ["inline_keyboard"=>$buttons];
                }
                $role=strtolower((string)($staff['role']??''));
                $buttons[]=[["text"=>"🛏️ Kamar & Tamu","callback_data"=>"guest_ops_menu"]];
                if(tamasyaGrowthModuleEnabled('group')&&hasCapability($staff,'sell_room_access',['admin','manager','receptionist'])&&tamasyaHasRoomsWorkspaceAccess($staff))$buttons[]=[['text'=>'🏨 Reservasi Beberapa Kamar','callback_data'=>'mr_start:reserve']];
                if(tamasyaCanOperateShift($staff)||hasCapability($staff,'receive_payment_access',[])){
                    $buttons[]=[["text"=>"💰 Kas & Shift","callback_data"=>"cash_shift_menu"]];
                }
                if(in_array($role,$guestServiceTelegramRoles,true))$buttons[]=[["text"=>"🔔 Permintaan Tamu","callback_data"=>"gsm:p:1"]];
                $buttons[]=[["text"=>"🧹 Operasional","callback_data"=>"field_ops_menu"],["text"=>"🗓️ Cuti Saya","callback_data"=>"leave_menu"]];
                $buttons[]=[["text"=>"🤖 Tanya AI","callback_data"=>"ai_prompt"],["text"=>"👤 Akun & Diagnosa","callback_data"=>"account_menu"]];
                return ["inline_keyboard"=>$buttons];
            };

            $buildTelegramLeaveMenu = static function(PDO $pdo, array $staff) use ($telegramLeaveStatusLabel): array {
                $rows = tamasyaEmployeeMyLeaveRequests($pdo,(string)$staff['id'],20);
                $counts=['pending'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0];
                foreach($rows as $row){$key=strtolower((string)($row['status']??''));if(array_key_exists($key,$counts))$counts[$key]++;}
                $text = "🗓️ *CUTI SAYA*\n\n" .
                    "Menunggu: *{$counts['pending']}* | Disetujui: *{$counts['approved']}*\n" .
                    "Ditolak: *{$counts['rejected']}* | Dibatalkan: *{$counts['cancelled']}*\n\n" .
                    "Pilih tindakan di bawah ini. Pengajuan hanya diproses dari akun staf aktif yang sudah terhubung.";
                $buttons=[[
                    ['text'=>'➕ Ajukan Cuti','callback_data'=>'leave_new'],
                    ['text'=>'📚 Riwayat Cuti','callback_data'=>'leave_history']
                ]];
                if(strtolower((string)($staff['role']??''))==='owner')$buttons=[[['text'=>'📚 Riwayat Cuti','callback_data'=>'leave_history']]];
                if(in_array(strtolower((string)($staff['role']??'')),['admin','manager'],true)){
                    $buttons[]=[['text'=>'✅ Persetujuan Cuti','callback_data'=>'leave_team']];
                }
                $buttons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                return ['text'=>$text,'markup'=>['inline_keyboard'=>$buttons]];
            };

            $buildTelegramShiftCloseMenu = static function(PDO $pdo, array $staff): array {
                if(!tamasyaCanOperateShift($staff)&&!hasCapability($staff,'receive_payment_access',[]))return [
                    'text'=>'🔒 Akun ini tidak memiliki izin menutup shift kas.',
                    'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]]
                ];
                $role=strtolower(trim((string)($staff['role']??'')));$staffId=(string)($staff['id']??'');
                if($role==='receptionist'){
                    $stmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE status='open' AND (staff_id=? OR companion_staff_id=?) ORDER BY opened_at DESC,id DESC LIMIT 30");
                    $stmt->execute([$staffId,$staffId]);
                }else{
                    $stmt=$pdo->query("SELECT * FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC LIMIT 30");
                }
                $rows=$stmt?$stmt->fetchAll(PDO::FETCH_ASSOC):[];$rows=$rows?:[];
                if(!$rows)return [
                    'text'=>'⚠️ Tidak ada shift terbuka yang dapat ditutup oleh akun ini.',
                    'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]]
                ];
                $buttons=[];
                foreach($rows as $shift){
                    $id=trim((string)($shift['id']??''));if($id==='')continue;
                    $label='📝 '.substr(getShiftDisplayName($shift),0,32).' · '.strtoupper((string)($shift['shift_time']??'all')).' · '.(string)($shift['shift_date']??'');
                    $buttons[]=[['text'=>substr($label,0,60),'callback_data'=>'tutup_shift_select:'.$id]];
                }
                $buttons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                return ['text'=>"📝 *PILIH SHIFT UNTUK DITUTUP*

Daftar diambil langsung dari sesi server yang masih berstatus OPEN. Pilih shift lalu lanjutkan rekonsiliasi kas.",'markup'=>['inline_keyboard'=>$buttons]];
            };

            $keyboardForTelegramRole = static function(?array $staff) use ($userKeyboard,$repsKeyboard,$adminKeyboard,$financeKeyboard,$housekeepingKeyboard,$securityKeyboard,$basicStaffKeyboard,$ownerKeyboard) {
                if (!$staff) return $userKeyboard;
                $role = strtolower((string)($staff['role'] ?? ''));
                if (tamasyaIsOwnerRole($staff)) return $ownerKeyboard;
                if (in_array($role,['admin','manager'],true)) return $adminKeyboard;
                if ($role === 'receptionist') return $repsKeyboard;
                if ($role === 'finance') $baseKeyboard = $financeKeyboard;
                elseif ($role === 'cleaning_service') $baseKeyboard = $housekeepingKeyboard;
                elseif ($role === 'keamanan') $baseKeyboard = $securityKeyboard;
                else $baseKeyboard = $basicStaffKeyboard;
                // Tombol penjualan/inap/panjar muncul mengikuti centangan per
                // akun agar "dicentang langsung jalan". Tanpa centangan,
                // keyboard sama dengan sebelumnya (fallback = peran lama).
                $flatTexts = [];
                foreach ((array)($baseKeyboard['keyboard'] ?? []) as $existingRow) {
                    foreach ((array)$existingRow as $btn) {
                        if (is_array($btn) && isset($btn['text'])) $flatTexts[] = (string)$btn['text'];
                    }
                }
                $extraRows = [];
                if (!in_array("🛒 Jual Kamar",$flatTexts,true) && hasCapability($staff,'sell_room_access',['admin','manager','receptionist'])) {
                    $extraRows[] = [["text" => "🛒 Jual Kamar"]];
                    $extraRows[] = [["text" => "📅 Reservasi"]];
                }
                if (!in_array("⏳ Perpanjang Sewa",$flatTexts,true) && hasCapability($staff,'manage_stay_access',['admin','manager','receptionist'])) {
                    $extraRows[] = [["text" => "⏳ Perpanjang Sewa"], ["text" => "🛠️ Tambah Layanan"]];
                    $extraRows[] = [["text" => "🔄 Pindah Kamar"]];
                    $extraRows[] = [["text" => "🚪 Check-out Kamar"]];
                }
                if (!in_array("💵 Terima Panjar",$flatTexts,true) && hasCapability($staff,'receive_payment_access',['admin','manager','receptionist','finance'])) {
                    $extraRows[] = [["text" => "💵 Terima Panjar"]];
                }
                if (!in_array("🧹 Housekeeping",$flatTexts,true) && hasCapability($staff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])) {
                    $extraRows[] = [["text" => "🧹 Housekeeping"]];
                }
                if ($extraRows === []) return $baseKeyboard;
                $rows = is_array($baseKeyboard['keyboard'] ?? null) ? $baseKeyboard['keyboard'] : [];
                $insertAt = count($rows);
                foreach ($rows as $i=>$row) {
                    foreach ((array)$row as $btn) {
                        if (is_array($btn) && ($btn['text'] ?? '') === "🏠 Menu Utama") { $insertAt = $i; break 2; }
                    }
                }
                array_splice($rows,$insertAt,0,$extraRows);
                $baseKeyboard['keyboard'] = $rows;
                return $baseKeyboard;
            };

            $chatId = null;
            $chatType = '';
            if (isset($update['callback_query']['message']['chat']['id'])) {
                $chatId = $update['callback_query']['message']['chat']['id'];
                $chatType = strtolower((string)($update['callback_query']['message']['chat']['type'] ?? ''));
            } elseif (isset($update['message']['chat']['id'])) {
                $chatId = $update['message']['chat']['id'];
                $chatType = strtolower((string)($update['message']['chat']['type'] ?? ''));
            } elseif (isset($update['my_chat_member']['chat']['id'])) {
                $chatId = $update['my_chat_member']['chat']['id'];
                $chatType = strtolower((string)($update['my_chat_member']['chat']['type'] ?? ''));
            } elseif (isset($update['chat_member']['chat']['id'])) {
                $chatId = $update['chat_member']['chat']['id'];
                $chatType = strtolower((string)($update['chat_member']['chat']['type'] ?? ''));
            }
            $isPrivateTelegramChat = !empty($GLOBALS['is_telegram_simulation']) || $chatType === 'private';

            $fromId = null;
            if (isset($update['callback_query']['from']['id'])) {
                $fromId = $update['callback_query']['from']['id'];
            } elseif (isset($update['message']['from']['id'])) {
                $fromId = $update['message']['from']['id'];
            } elseif (isset($update['my_chat_member']['from']['id'])) {
                $fromId = $update['my_chat_member']['from']['id'];
            } elseif (isset($update['chat_member']['from']['id'])) {
                $fromId = $update['chat_member']['from']['id'];
            }

            if (!$fromId) {
                $fromId = $chatId;
            }
            if (empty($GLOBALS['is_telegram_simulation']) && !enforceRateLimit($pdo,'telegram:'.hash('sha256',(string)$fromId),60,60,120)) break;

            if ($isCallback) {
                $callbackData = $update['callback_query']['data'];
                $callbackQueryId = $update['callback_query']['id'];
                $messageId = $update['callback_query']['message']['message_id'];
                // callback operation ID dihitung setelah token callback panjang
                // diselesaikan ke payload aslinya agar retry/idempotency tetap stabil.
                $telegramCallbackOperationId = '';
                $telegramCallbackStartedAt = microtime(true);
                $GLOBALS['tamasya_telegram_latency_callback_data'] = substr((string)$callbackData,0,120);
                $postCallbackBroadcasts = [];
                if (empty($GLOBALS['tamasya_telegram_latency_logger_registered'])) {
                    $GLOBALS['tamasya_telegram_latency_logger_registered']=true;
                    register_shutdown_function(static function(): void {
                        $callbackMs=(int)($GLOBALS['tamasya_telegram_latency_callback_ms']??0);
                        $lockMs=(int)($GLOBALS['tamasya_telegram_latency_lock_ms']??0);
                        $editMs=(int)($GLOBALS['tamasya_telegram_latency_edit_ms']??0);
                        if(max($callbackMs,$lockMs,$editMs)>=1500){
                            error_log('[Telegram Slow Callback] '.json_encode([
                                'callback'=>(string)($GLOBALS['tamasya_telegram_latency_callback_data']??''),
                                'callbackMs'=>$callbackMs,'lockMs'=>$lockMs,'editMs'=>$editMs,
                                'nodeRole'=>function_exists('tamasyaNodeRole')?tamasyaNodeRole():null
                            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                        }
                    });
                }
                
                // Callback operasional hanya boleh dijalankan di chat privat. Tombol
                // yang diteruskan ke grup tidak boleh membaca atau mengubah database hotel.
                if (!$isPrivateTelegramChat) {
                    // Routed through telegramApiCall so simulation short-circuits,
                    // TELEGRAM_API_BASE_URL is honoured, and a transport failure is
                    // observable instead of a discarded sendHttpPost result.
                    telegramApiCall($token,'answerCallbackQuery', [
                        "callback_query_id"=>$callbackQueryId,
                        "text"=>"Buka bot melalui chat privat",
                        "show_alert"=>true
                    ]);
                    echo json_encode(["success"=>true,"status"=>"private_chat_required"]);
                    break;
                }

                // Gunakan Telegram User ID, bukan chat ID grup, untuk autentikasi staf.
                // Di simulation mode, gunakan staff yang sudah di-resolve di telegram-callback
                // untuk menghindari query ganda yang bisa gagal.
                if (!empty($GLOBALS['tamasya_sim_callback_staff'])) {
                    $loggedInStaff = $GLOBALS['tamasya_sim_callback_staff'];
                } else {
                    $loggedInStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
                }
                try {
                    $callbackData = tamasyaTelegramResolveCallbackData($pdo, $loggedInStaff ?: null, (string)$callbackData);
                } catch (Throwable $callbackTokenError) {
                    telegramApiCall($token,'answerCallbackQuery', [
                        'callback_query_id'=>$callbackQueryId,
                        'text'=>'Tombol kedaluwarsa',
                        'show_alert'=>true
                    ]);
                    telegramApiCallRequired($token,'editMessageText', [
                        'chat_id'=>$chatId,
                        'message_id'=>$messageId,
                        'text'=>"⚠️ *TOMBOL SUDAH TIDAK BERLAKU*\n\nUlangi langkah dari menu bot agar state transaksi tetap konsisten.",
                        'parse_mode'=>'Markdown'
                    ], true);
                    echo json_encode(['success'=>true,'status'=>'callback_token_expired']);
                    break;
                }
                $telegramCallbackOperationId = 'tgcb_' . substr(hash('sha256', implode('|', [(string)$fromId,(string)$chatId,(string)$messageId,(string)$callbackData])), 0, 64);

                // Role revalidation chain: role callback juga dibaca ulang dari binding
                // aktif server, bukan dari snapshot awal request.
                if (empty($GLOBALS['tamasya_sim_callback_staff'])) {
                    $revalidatedCallbackStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
                    if (is_array($revalidatedCallbackStaff)) {
                        $loggedInStaff = $revalidatedCallbackStaff;
                    }
                }

                // Otorisasi callback dilakukan sebelum handler mana pun menyentuh database.
                // Tombol lama yang masih tersimpan di chat tidak boleh menjadi bypass role.
                $callbackRole = $loggedInStaff ? strtolower((string)($loggedInStaff['role'] ?? '')) : '';
                $isPublicCallback = in_array($callbackData, ['main_menu','room_status_menu','ai_prompt'], true)
                    || str_starts_with($callbackData, 'room_status_menu:p:')
                    || str_starts_with($callbackData, 'login_as_id:');
                // Fail-closed: staf login tidak otomatis boleh menekan semua callback.
                // Setiap callback operasional wajib cocok dengan map role/capability di bawah.
                $allowedCallback = $isPublicCallback;
                if ($loggedInStaff) {
                    $generalStaffCallbacks = ['guest_ops_menu','field_ops_menu','account_menu','account_profile','telegram_diagnostics'];
                    $supportCallbacks = ['support_reply_cancel'];
                    $supportPrefixes = ['support_reply:'];
                    $financeCallbacks = ['pemasukan_menu','pengeluaran_menu','laporan_kas_refresh','consistency_guard'];
                    $financePrefixes = ['pemasukan_menu:p:','p_room:','p_room_confirm_direct:','p_room_cat:','p_room_custom:','p_room_sub:','p_room_custom_sub:','p_exp_cat:','p_exp_sub:','p_exp_custom:','p_exp_confirm_direct:','p_exp_custom_sub:'];
                    $sellCallbacks = ['sell_room_list','reservation_list'];
                    $sellPrefixes = ['sell_room_list:p:','reservation_list:p:','reservation_detail:','r_sell:','r_sell_confirm:','r_sell_nego_prompt:','r_sell_source_prompt:','r_sell_walkin_ktp:','r_sell_custom:','r_sell_source_set:','r_sell_source_back:','r_sell_split_select:','r_sell_open_ended_select:','r_sell_split_bank:','r_sell_dp_confirm:'];
                    $stayCallbacks = ['booking_extend_menu','booking_service_menu','booking_transfer_menu','booking_checkout_menu'];
                    $stayPrefixes = ['booking_extend_menu:p:','booking_service_menu:p:','booking_transfer_menu:p:','booking_checkout_menu:p:','r_checkout:','r_checkout_nego:','r_checkout_nego_save:','r_checkout_key:','r_checkout_confirm:','r_checkout_defer:','r_checkout_pay:','r_checkout_split:','r_checkout_split_bank:','r_charge_method:','r_charge_account:','r_charge_save:','r_extend:','r_extend_nego:','r_extend_choice:','r_extend_confirm:','r_layanan:','r_layanan_type:','r_layanan_confirm:','r_transfer:','r_transfer_target:','r_transfer_confirm:','r_transfer_key:'];
                    $roomReadCallbacks = ['room_list','room_payment_status_menu','cancel_booking_process','simulate_ktp_upload'];
                    $roomReadPrefixes = ['room_list:p:','room_payment_status_menu:p:','room_payment_status_filter:','room_select:','room_set:','key_status:','key_issue_review:','key_issue_confirm:'];
                    $housekeepingCallbacks = ['housekeeping_menu'];
                    $housekeepingPrefixes = ['housekeeping_menu:p:','hk_room:','hk_set:'];
                    $vacancyCallbacks = ['vacancy_menu'];
                    $vacancyPrefixes = ['vacancy_menu:p:','vacancy_report_room:','vacancy_key:','vacancy_condition:'];
                    $patrolCallbacks = ['patrol_reports_menu','incident_report_init','cancel_patrol_process'];
                    $patrolPrefixes = ['patrol_action:'];
                    $guestServiceCallbacks = ['guest_service_menu'];
                    $guestServicePrefixes = ['gsm:p:','gsd:','gsa:','gss:','gsc:'];
                    $shiftCallbacks = ['cash_shift_menu','buka_shift_menu','tutup_shift_menu','cancel_buka_shift','cancel_tutup_shift'];
                    $bookingPaymentCallbacks = ['panjar_menu'];
                    $shiftPrefixes = ['buka_shift_time:','buka_shift_companion:','tutup_shift_time:','tutup_shift_companion:','tutup_shift_select:','tutup_shift_done:'];
                    $bookingPaymentPrefixes = ['panjar_menu:p:','dp_room:','dp_method:','dp_account:'];
                    $receiptCallbacks = ['receipt_menu'];
                    $receiptPrefixes = ['print_receipt:'];
                    $identityCallbacks = ['guest_identity_menu'];
                    $identityPrefixes = ['guest_identity_view:'];
                    $matchesPrefix = static function(string $value, array $prefixes): bool {
                        foreach ($prefixes as $prefix) if (str_starts_with($value, $prefix)) return true;
                        return false;
                    };
                    $leaveSelfCallbacks = ['leave_menu','leave_new','leave_history','leave_confirm'];
                    $leaveSelfPrefixes = ['leave_type:','leave_cancel_prompt:','leave_cancel_confirm:'];
                    $leaveManagerCallbacks = ['leave_team'];
                    $leaveManagerPrefixes = ['leave_approve:','leave_approve_confirm:','leave_reject:'];
                    if (in_array($callbackData,$generalStaffCallbacks,true)) {
                        $allowedCallback = true;
                    } elseif (in_array($callbackData,$supportCallbacks,true) || $matchesPrefix($callbackData,$supportPrefixes)) {
                        $allowedCallback = in_array($callbackRole,['admin','manager','receptionist'],true);
                    } elseif (in_array($callbackData,$leaveSelfCallbacks,true) || $matchesPrefix($callbackData,$leaveSelfPrefixes)) {
                        $allowedCallback = $callbackRole!=='owner' || in_array($callbackData,['leave_menu','leave_history'],true);
                    } elseif (in_array($callbackData,$leaveManagerCallbacks,true) || $matchesPrefix($callbackData,$leaveManagerPrefixes)) {
                        $allowedCallback = in_array($callbackRole,['admin','manager'],true);
                    } elseif (in_array($callbackData,$financeCallbacks,true) || $matchesPrefix($callbackData,$financePrefixes)) {
                        // financePrefixes payload validation: payload after a finance prefix
                        // must be non-empty and must not contain whitespace/control chars.
                        $financePrefixPayloadValid = true;
                        foreach ($financePrefixes as $financePrefix) {
                            if (str_starts_with($callbackData, $financePrefix)) {
                                $financePayload = substr($callbackData, strlen($financePrefix));
                                if (preg_match('/\s/u', $financePayload)) {
                                    $financePrefixPayloadValid = false;
                                }
                                break;
                            }
                        }
                        $allowedCallback = $financePrefixPayloadValid && in_array($callbackRole,['admin','manager','finance'],true);
                    } elseif ($callbackData==='mr_list'||str_starts_with($callbackData,'mr_list:')||str_starts_with($callbackData,'mr_detail:')) {
                        $allowedCallback=in_array($callbackRole,['admin','manager','receptionist','finance','owner'],true)||hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']);
                    } elseif (str_starts_with($callbackData,'mr_')) {
                        // Buat grup = penjualan: role baseline ATAU centangan sell_room_access.
                        $allowedCallback=hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']);
                    } elseif ($callbackData==='riwayat_shift_menu'||str_starts_with($callbackData,'riwayat_shift_menu:p:')||str_starts_with($callbackData,'riwayat_shift_detail:')) {
                        $allowedCallback = in_array($callbackRole,function_exists('tamasyaShiftHistoryReaderRoles')?tamasyaShiftHistoryReaderRoles():['admin','owner','manager','finance'],true);
                    } elseif ($callbackRole==='owner' && function_exists('tamasyaTelegramOwnerCallbackReadable') && tamasyaTelegramOwnerCallbackReadable($callbackData)) {
                        // Owner read-only: hanya layar baca yang eksplisit terdaftar.
                        // Callback mutasi/apapun yang baru otomatis tetap ditolak.
                        $allowedCallback = true;
                    } elseif (in_array($callbackData,$sellCallbacks,true) || $matchesPrefix($callbackData,$sellPrefixes)) {
                        // Jual/reservasi: role baseline ATAU centangan sell_room_access.
                        // Fallback = perilaku lama; centang false eksplisit mencabut.
                        $allowedCallback = hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']);
                    } elseif (in_array($callbackData,$stayCallbacks,true) || $matchesPrefix($callbackData,$stayPrefixes)) {
                        // Perpanjang/layanan/pindah/checkout: role baseline ATAU centangan manage_stay_access.
                        $allowedCallback = hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']);
                    } elseif (in_array($callbackData,$roomReadCallbacks,true) || $matchesPrefix($callbackData,$roomReadPrefixes)) {
                        // Baca kamar: pengawas baseline ATAU centangan kelola kamar. Owner read-only tetap boleh.
                        $allowedCallback = $callbackRole==='owner'||hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist','finance']);
                    } elseif (in_array($callbackData,$vacancyCallbacks,true) || $matchesPrefix($callbackData,$vacancyPrefixes)) {
                        $allowedCallback = in_array($callbackRole,['admin','manager','receptionist','finance','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'],true);
                    } elseif (in_array($callbackData,$housekeepingCallbacks,true) || $matchesPrefix($callbackData,$housekeepingPrefixes)) {
                        $allowedCallback = hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']);
                    } elseif (in_array($callbackData,$patrolCallbacks,true) || $matchesPrefix($callbackData,$patrolPrefixes)) {
                        $allowedCallback = in_array($callbackRole,['admin','manager','receptionist','keamanan'],true);
                    } elseif (in_array($callbackData,$guestServiceCallbacks,true) || $matchesPrefix($callbackData,$guestServicePrefixes)) {
                        $allowedCallback = in_array($callbackRole,$guestServiceTelegramRoles,true);
                    } elseif (in_array($callbackData,$shiftCallbacks,true) || $matchesPrefix($callbackData,$shiftPrefixes)) {
                        // Kas & shift: operator shift baseline ATAU centangan terima pembayaran.
                        $allowedCallback = tamasyaCanOperateShift($loggedInStaff)||hasCapability($loggedInStaff,'receive_payment_access',[]);
                    } elseif (in_array($callbackData,$bookingPaymentCallbacks,true) || $matchesPrefix($callbackData,$bookingPaymentPrefixes)) {
                        // Terima panjar: role baseline ATAU centangan receive_payment_access.
                        $allowedCallback = hasCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance']);
                    } elseif (in_array($callbackData,$receiptCallbacks,true) || $matchesPrefix($callbackData,$receiptPrefixes)) {
                        // Cetak nota murni baca (SELECT + format thermal), aman untuk Owner read-only.
                        $allowedCallback = in_array($callbackRole,['admin','manager','receptionist','finance','owner'],true);
                    } elseif (str_starts_with($callbackData,'email_receipt:') || str_starts_with($callbackData,'email_receipt_send:')) {
                        // Kirim nota via email = aksi keluar (mengirim email atas nama hotel):
                        // penjualan ATAU rawat-inap. Owner read-only & Finance tidak berhak.
                        $allowedCallback = hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist'])
                            || hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']);
                    } elseif (in_array($callbackData,$identityCallbacks,true) || $matchesPrefix($callbackData,$identityPrefixes)) {
                        $allowedCallback = hasCapability($loggedInStaff,'view_guest_identity',['admin','manager','receptionist','keamanan']);
                    }
                }
                if (!$allowedCallback) {
                    $deniedText = $loggedInStaff
                        ? "Akses tombol ini tidak sesuai peran akun Anda."
                        : "Telegram User ID belum terhubung ke akun staf. Hubungi Admin.";
                    if ($loggedInStaff && $callbackRole==='owner') {
                        $deniedText = "Akun Owner bersifat read-only di Telegram: boleh melihat semua menu, tidak boleh mengubah data. Minta Admin/Manager untuk tindakan ini.";
                    }
                    telegramApiCall($token,'answerCallbackQuery', [
                        "callback_query_id"=>$callbackQueryId,
                        "text"=>"Akses ditolak",
                        "show_alert"=>true
                    ]);
                    telegramApiCallRequired($token,'editMessageText', [
                        "chat_id"=>$chatId,
                        "message_id"=>$messageId,
                        "text"=>"🔒 *AKSES DITOLAK*\n\n".$deniedText,
                        "parse_mode"=>"Markdown"
                    ],true);
                    echo json_encode(["success"=>true,"status"=>"callback_denied"]);
                    break;
                }
                
                $replyText = "";
                $replyMarkup = null;
                $alertText = "";

                // EARLY answerCallbackQuery: MUST be called BEFORE any handler logic
                // to prevent "loading forever" if handler throws exception
                // Use telegramApiCall directly (works in simulation mode) to avoid exception from Required variant
                if (empty($telegramCallbackEarlyAcked)) {
                    telegramApiCall($token,'answerCallbackQuery', [
                        "callback_query_id" => $callbackQueryId,
                        "text" => $alertText ?: "Memproses..."
                    ]);
                }

                $authStaff = null;
                $isLoginAction = false;
                
                if (strpos($callbackData, "login_as_id:") === 0) {
                    // Pemilihan akun + password lewat chat dinonaktifkan secara default.
                    $replyText = "🔒 *BINDING AMAN DIPERLUKAN*\n\nBuat kode binding sekali pakai dari aplikasi, lalu kirim `/bind KODE`. Password akun tidak pernah dipakai melalui chat.";
                    $alertText = "Gunakan kode binding";
                }

                // Login akun lewat tombol/password sudah dihapus permanen. Satu-satunya
                // jalur binding adalah kode sekali pakai `/bind KODE` yang diklaim
                // transaksional dan mengikat Telegram User ID ke satu staff aktif.
                $isLoginAction = false;
                if(str_starts_with($callbackData,'mr_')) {
                    try{$groupReply=tamasyaMultiRoomTelegramCallback($pdo,$loggedInStaff,$callbackData,$telegramCallbackOperationId);$replyText=$groupReply['text'];$replyMarkup=$groupReply['markup'];$alertText='Reservasi grup';}
                    catch(Throwable $e){$replyText=clientExceptionMessage('Grup belum diproses',$e);$alertText='Periksa kembali';}
                } elseif (str_starts_with($callbackData,'support_reply:')) {
                    $publicCode=trim((string)substr($callbackData,strlen('support_reply:')));
                    if(!preg_match('/^SUP-[A-Za-z0-9-]{8,64}$/',$publicCode)){
                        $replyText="⚠️ *PERCAKAPAN TIDAK VALID*\n\nTombol balas tidak lagi memiliki ID percakapan yang valid.";
                        $alertText='Percakapan tidak valid';
                    }else{
                        $supportCtx=['publicCode'=>$publicCode,'expiresAt'=>time()+900,'startedAt'=>time()];
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_support_reply',telegram_context=? WHERE id=?")
                            ->execute([json_encode($supportCtx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $loggedInStaff['telegram_state']='waiting_for_support_reply';
                        $loggedInStaff['telegram_context']=json_encode($supportCtx);
                        $replyText="↩️ *BALAS CHAT WEBSITE*\n\nPercakapan: `{$publicCode}`\n\nKetik jawaban Anda sebagai pesan biasa. Balasan berikutnya akan masuk langsung ke thread website ini. Mode balas berlaku *15 menit* dan otomatis selesai setelah satu pesan terkirim.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'✖️ Batalkan Balas','callback_data'=>'support_reply_cancel']]]];
                        $alertText='Mode balas aktif';
                    }
                } elseif ($callbackData === 'support_reply_cancel') {
                    if($loggedInStaff){
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    }
                    $replyText="✅ *MODE BALAS DIBATALKAN*\n\nTidak ada pesan website yang akan dikirim sampai Anda memilih tombol Balas lagi.";
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    $alertText='Dibatalkan';
                } elseif ($callbackData === 'ai_prompt') {
                    if($loggedInStaff){
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    }
                    $replyText = "🤖 *TANYA AI TAMASYA*\n\nKetik pertanyaan Anda sebagai pesan biasa, tanpa `/command`. Asisten akan menjawab sesuai hak akses akun Telegram yang terhubung.\n\nContoh: `berapa kamar tersedia?` atau `ringkas kondisi hotel hari ini`.";
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    $alertText='Tanya AI';
                } elseif ($callbackData === 'room_status_menu' || str_starts_with($callbackData,'room_status_menu:p:')) {
                    $page=1;
                    if(str_starts_with($callbackData,'room_status_menu:p:'))$page=max(1,(int)substr($callbackData,strlen('room_status_menu:p:')));
                    $stmtRooms=$pdo->query("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED),number");
                    $rooms=$stmtRooms->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));
                    $available=[];$booked=[];$dirty=[];$maintenance=[];
                    foreach($rooms as $room){
                        $number=(string)($room['number']??'');$status=$roomStatusMap[$number]??'maintenance';
                        if($status==='booked')$booked[]=$number;elseif($status==='available')$available[]=$number;elseif($status==='dirty')$dirty[]=$number;else$maintenance[]=$number;
                    }
                    if(!$loggedInStaff){
                        $replyText="📊 *KETERSEDIAAN KAMAR*

🟢 Tersedia: *".count($available)." kamar*
🔴 Terisi: *".count($booked)." kamar*
🧹 Housekeeping/QC: *".count($dirty)." kamar*
🛠️ Servis/Blocker: *".count($maintenance)." kamar*

Nomor kamar operasional hanya ditampilkan setelah akun staf terhubung.";
                        $buttons=[[['text'=>'🔄 Muat Ulang','callback_data'=>'room_status_menu']],[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]];
                    }else{
                        $pageSize=30;$count=count($rooms);$totalPages=max(1,(int)ceil(max(1,$count)/$pageSize));$page=min(max(1,$page),$totalPages);$offset=($page-1)*$pageSize;
                        $pageRows=array_slice($rooms,$offset,$pageSize);$detail=[];
                        foreach($pageRows as $room){
                            $number=(string)($room['number']??'');$status=$roomStatusMap[$number]??'maintenance';
                            $icon=$status==='booked'?'🔴':($status==='available'?'🟢':($status==='dirty'?'🧹':'🛠️'));
                            $detail[]=$icon.' '.$number;
                        }
                        $chunks=array_chunk($detail,3);$detailLines=[];foreach($chunks as $chunk)$detailLines[]=implode(' · ',$chunk);
                        $rangeStart=$count?($offset+1):0;$rangeEnd=min($offset+$pageSize,$count);
                        $replyText="📊 *KETERSEDIAAN KAMAR TERKINI*

🟢 Tersedia: *".count($available)."* · 🔴 Terisi: *".count($booked)."* · 🧹 HK/QC: *".count($dirty)."* · 🛠️ Servis: *".count($maintenance)."*

".
                            ($detailLines?implode("
",$detailLines):'Belum ada kamar.')."

📄 Halaman *{$page}/{$totalPages}* · kamar *{$rangeStart}-{$rangeEnd}* dari *{$count}*.";
                        $buttons=[[['text'=>'🔄 Muat Ulang','callback_data'=>'room_status_menu:p:'.$page]]];
                        if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'room_status_menu:p:');
                        if(in_array($callbackRole,['admin','manager','receptionist','owner'],true))$buttons[]=[['text'=>'🔎 Diagnosa Status Kamar','callback_data'=>'room_list']];
                        $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];
                    }
                    $replyMarkup=['inline_keyboard'=>$buttons];$alertText='Status Kamar';
                } elseif ($callbackData === 'guest_ops_menu') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $buttons=[[[ 'text'=>'📊 Cek Kamar','callback_data'=>'room_status_menu' ]]];
                    // Tombol jual vs inap dipisah mengikuti centangan:
                    // sell_room_access → Reservasi/Jual/Grup, manage_stay_access →
                    // Perpanjang/Layanan/Pindah/Checkout. Tanpa centangan,
                    // fallback = peran lama persis.
                    $canSellOps = in_array($callbackRole,['admin','manager','receptionist'],true) || hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']);
                    $canStayOps = in_array($callbackRole,['admin','manager','receptionist'],true) || hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']);
                    if($canSellOps){
                        $buttons[]=[['text'=>'📅 Reservasi','callback_data'=>'reservation_list'],['text'=>'🛒 Jual Kamar','callback_data'=>'sell_room_list']];
                        if(tamasyaGrowthModuleEnabled('group'))$buttons[]=[['text'=>'Reservasi Beberapa Kamar','callback_data'=>'mr_start:reserve'],['text'=>'Check-in Beberapa Kamar','callback_data'=>'mr_start:check_in_now']];
                    }
                    if($canSellOps||$canStayOps){
                        $buttons[]=[['text'=>'🔎 Diagnosa Kamar','callback_data'=>'room_list']];
                    }
                    if($canStayOps){
                        $buttons[]=[['text'=>'⏳ Perpanjang','callback_data'=>'booking_extend_menu'],['text'=>'🛠️ Tambah Layanan','callback_data'=>'booking_service_menu']];
                        $buttons[]=[['text'=>'🔄 Pindah Kamar','callback_data'=>'booking_transfer_menu'],['text'=>'🚪 Check-out','callback_data'=>'booking_checkout_menu']];
                    }
                    if($canSellOps||$canStayOps){
                    $buttons[]=[['text'=>'💰 Status Bayar Kamar','callback_data'=>'room_payment_status_menu']];
                    }
                    if($callbackRole==='owner'){
                        // Cermin susunan admin agar terpantau semua; eksekusi
                        // perubahan tetap ditolak di gerbang callback.
                        $buttons[]=[['text'=>'📅 Reservasi','callback_data'=>'reservation_list'],['text'=>'🛒 Jual Kamar','callback_data'=>'sell_room_list']];
                        $buttons[]=[['text'=>'🔎 Diagnosa Kamar','callback_data'=>'room_list']];
                        $buttons[]=[['text'=>'⏳ Perpanjang','callback_data'=>'booking_extend_menu'],['text'=>'🛠️ Tambah Layanan','callback_data'=>'booking_service_menu']];
                        $buttons[]=[['text'=>'🔄 Pindah Kamar','callback_data'=>'booking_transfer_menu'],['text'=>'🚪 Check-out','callback_data'=>'booking_checkout_menu']];
                        $buttons[]=[['text'=>'💰 Status Bayar Kamar','callback_data'=>'room_payment_status_menu']];
                        $buttons[]=[['text'=>'🧾 Cetak Nota','callback_data'=>'receipt_menu']];
                    }
                    if(in_array($callbackRole,['admin','manager','receptionist','finance'],true))$buttons[]=[['text'=>'🧾 Cetak Nota','callback_data'=>'receipt_menu']];
                    if(hasCapability($loggedInStaff,'view_guest_identity',['admin','manager','receptionist','keamanan']))$buttons[]=[['text'=>'🪪 Lihat KTP','callback_data'=>'guest_identity_menu']];
                    if(tamasyaGrowthModuleEnabled('group')&&(in_array($callbackRole,['admin','manager','receptionist','finance','owner'],true)||hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist'])))$buttons[]=[['text'=>'Daftar Grup','callback_data'=>'mr_list']];
                    $buttons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                    $replyText="🛏️ *KAMAR & TAMU*\n\nUntuk satu pemesan beberapa kamar, pilih Reservasi Beberapa Kamar (atau ketik /reservasi_grup). Pilih tanggal dahulu; kamar terisi saat ini boleh dipesan untuk periode lain yang tidak bertabrakan. Tombol perubahan mengikuti izin akun.";
                    $replyMarkup=['inline_keyboard'=>$buttons];$alertText='Kamar & Tamu';
                } elseif ($callbackData === 'booking_extend_menu' || str_starts_with($callbackData,'booking_extend_menu:p:')) {
                    $page=str_starts_with($callbackData,'booking_extend_menu:p:')?max(1,(int)substr($callbackData,strlen('booking_extend_menu:p:'))):1;
                    $menu=$buildTelegramActiveBookingPicker($pdo,'r_extend:','booking_extend_menu:p:',"⏳ *PERPANJANG MASA SEWA KAMAR*",'Pilih kamar aktif yang ingin diperpanjang.','⚠️ Tidak ada sewa aktif yang dapat diperpanjang.',$page);
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Perpanjang Sewa';
                } elseif ($callbackData === 'booking_service_menu' || str_starts_with($callbackData,'booking_service_menu:p:')) {
                    $page=str_starts_with($callbackData,'booking_service_menu:p:')?max(1,(int)substr($callbackData,strlen('booking_service_menu:p:'))):1;
                    $menu=$buildTelegramActiveBookingPicker($pdo,'r_layanan:','booking_service_menu:p:',"🛠️ *TAMBAH LAYANAN KAMAR*",'Pilih kamar aktif yang akan menerima layanan tambahan.','⚠️ Tidak ada sewa aktif yang dapat menerima layanan tambahan.',$page);
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Tambah Layanan';
                } elseif ($callbackData === 'booking_transfer_menu' || str_starts_with($callbackData,'booking_transfer_menu:p:')) {
                    $page=str_starts_with($callbackData,'booking_transfer_menu:p:')?max(1,(int)substr($callbackData,strlen('booking_transfer_menu:p:'))):1;
                    $menu=$buildTelegramActiveBookingPicker($pdo,'r_transfer:','booking_transfer_menu:p:',"🔄 *PINDAH KAMAR*",'Pilih kamar aktif tamu yang akan dipindahkan.','⚠️ Tidak ada sewa aktif yang dapat dipindahkan.',$page);
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Pindah Kamar';
                } elseif ($callbackData === 'booking_checkout_menu' || str_starts_with($callbackData,'booking_checkout_menu:p:')) {
                    $page=str_starts_with($callbackData,'booking_checkout_menu:p:')?max(1,(int)substr($callbackData,strlen('booking_checkout_menu:p:'))):1;
                    $menu=$buildTelegramActiveBookingPicker($pdo,'r_checkout:','booking_checkout_menu:p:',"🚪 *CHECK-OUT KAMAR*",'Pilih kamar aktif yang akan diproses check-out.','⚠️ Tidak ada sewa aktif yang dapat diproses check-out.',$page);
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Check-out';
                } elseif ($callbackData === 'room_payment_status_menu' || str_starts_with($callbackData,'room_payment_status_menu:p:') || str_starts_with($callbackData,'room_payment_status_filter:')) {
                    $page=1;
                    $filter='all';
                    if(str_starts_with($callbackData,'room_payment_status_menu:p:')){
                        $page=max(1,(int)substr($callbackData,strlen('room_payment_status_menu:p:')));
                    } elseif (str_starts_with($callbackData,'room_payment_status_filter:')) {
                        $filter=substr($callbackData,strlen('room_payment_status_filter:'));
                    }
                    try {
                        $menu=$buildTelegramRoomPaymentStatusMenu($pdo,$page,$filter);
                        $replyText=$menu['text'];
                        $replyMarkup=$menu['markup'];
                        $alertText='Status Bayar Kamar';
                    } catch (Throwable $e) {
                        error_log('[room_payment_status_menu] Error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine().' Stack: '.$e->getTraceAsString());
                        $replyText="[!] *GAGAL MEMUAT STATUS BAYAR*\n\nDetail: ".$e->getMessage()."\nFile: ".$e->getFile().':'.$e->getLine()."\nSilakan coba lagi atau hubungi admin.";
                        $replyMarkup=['inline_keyboard'=>[
                            [
                                ['text'=>'[+] Coba Lagi','callback_data'=>'room_payment_status_menu'],
                                ['text'=>'[<] Kamar & Tamu','callback_data'=>'guest_ops_menu']
                            ]
                        ]];
                        $alertText='Gagal memuat';
                    }
                } elseif ($callbackData === 'receipt_menu') {
                    $rows=$pdo->query("SELECT id,roomNumber,guestName,status FROM bookings ORDER BY createdAt DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC)?:[];
                    if(!$rows){$replyText='⚠️ Belum ada data sewa yang dapat dicetak.';$buttons=[];}
                    else{
                        $replyText="🧾 *CETAK NOTA / INVOICE*\n\nPilih transaksi sewa terbaru:";$buttons=[];
                        foreach($rows as $booking){$label='🚪 '.$booking['roomNumber'].' · '.trim((string)$booking['guestName']);if(function_exists('mb_substr'))$label=mb_substr($label,0,42);else$label=substr($label,0,42);$buttons[]=[['text'=>$label,'callback_data'=>'print_receipt:'.$booking['id']]];}
                    }
                    $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Cetak Nota';
                } elseif ($callbackData === 'guest_identity_menu') {
                    $ktpRate=consumeRateLimit($pdo,'telegram-ktp:'.($loggedInStaff['id']??$fromId),20,60,300);
                    if(empty($ktpRate['allowed'])){$replyText="⚠️ Terlalu banyak permintaan KTP. Coba lagi dalam *".(int)$ktpRate['retryAfter']." detik*.";$buttons=[];}
                    else{
                        $rows=$pdo->query("SELECT id,roomNumber,guestName,status FROM bookings WHERE ktpPhoto IS NOT NULL AND ktpPhoto<>'' ORDER BY createdAt DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC)?:[];
                        if(!$rows){$replyText='🪪 Belum ada foto KTP tersimpan di database.';$buttons=[];}
                        else{
                            $replyText="🪪 *KTP TAMU*\n\nPilih booking/kamar. Setiap akses tetap dicatat pada audit server:";$buttons=[];
                            foreach($rows as $booking){$label='🚪 '.$booking['roomNumber'].' · '.trim((string)$booking['guestName']);if(function_exists('mb_substr'))$label=mb_substr($label,0,42);else$label=substr($label,0,42);$buttons[]=[['text'=>$label,'callback_data'=>'guest_identity_view:'.$booking['id']]];}
                        }
                    }
                    $buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];$replyMarkup=['inline_keyboard'=>$buttons];$alertText='KTP Tamu';
                } elseif (str_starts_with($callbackData,'guest_identity_view:')) {
                    $bookingId=substr($callbackData,strlen('guest_identity_view:'));
                    if(strlen($bookingId)>190){$replyText='⚠️ ID booking tidak valid.';}
                    else{
                        $stmt=$pdo->prepare("SELECT id,guestName,roomNumber,status,ktpPhoto FROM bookings WHERE id=? LIMIT 1");$stmt->execute([$bookingId]);$bookingKtp=$stmt->fetch(PDO::FETCH_ASSOC);
                        if(!$bookingKtp||trim((string)($bookingKtp['ktpPhoto']??''))===''){$replyText='⚠️ KTP booking tersebut tidak ditemukan.';}
                        else{
                            $caption="🪪 *KTP TAMU*\nKamar: *".$bookingKtp['roomNumber']."*\nBooking: `".$bookingKtp['id']."`\nStatus: *".$bookingKtp['status']."*";
                            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengotorisasi akses KTP tamu melalui Telegram','booking',$bookingKtp['id'],null,['roomNumber'=>$bookingKtp['roomNumber'],'bookingStatus'=>$bookingKtp['status'],'delivery'=>'telegram_pending'],'telegram');
                            $sentIdentity=telegramSendBookingIdentityPhoto($pdo,$token,$chatId,(string)$bookingKtp['ktpPhoto'],$caption);
                            writeEnterpriseAudit($pdo,$loggedInStaff,'Hasil pengiriman KTP tamu melalui Telegram','booking',$bookingKtp['id'],null,['roomNumber'=>$bookingKtp['roomNumber'],'sent'=>!empty($sentIdentity['ok'])],'telegram');
                            logActivity($pdo,'VIEW_GUEST_IDENTITY_TELEGRAM','Melihat KTP booking '.$bookingKtp['id'].' kamar '.$bookingKtp['roomNumber'],$loggedInStaff['id']??null,$loggedInStaff['name']??null);
                            $replyText=!empty($sentIdentity['ok'])?'✅ KTP Kamar *'.$bookingKtp['roomNumber'].'* berhasil dikirim dan akses dicatat.':'⚠️ Referensi KTP tersedia, tetapi foto gagal dikirim: '.($sentIdentity['error']??'kesalahan server').'.';
                        }
                    }
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Daftar KTP','callback_data'=>'guest_identity_menu'],['text'=>'🏠 Menu Utama','callback_data'=>'main_menu']]]];$alertText='KTP Tamu';
                } elseif ($callbackData === 'cash_shift_menu') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $buttons=[];
                    if(in_array($callbackRole,['admin','manager','receptionist','finance'],true)||hasCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance']))$buttons[]=[['text'=>'💵 Terima Panjar','callback_data'=>'panjar_menu']];
                    if(in_array($callbackRole,['admin','manager','finance'],true))$buttons[]=[['text'=>'📥 Input Pemasukan','callback_data'=>'pemasukan_menu']];
                    if(in_array($callbackRole,['admin','manager','finance'],true))$buttons[]=[['text'=>'📤 Input Pengeluaran','callback_data'=>'pengeluaran_menu'],['text'=>'📈 Laporan Kas','callback_data'=>'laporan_kas_refresh']];
                    if(tamasyaCanOperateShift($loggedInStaff)||hasCapability($loggedInStaff,'receive_payment_access',[]))$buttons[]=[['text'=>'🔑 Buka Shift','callback_data'=>'buka_shift_menu'],['text'=>'📝 Tutup Shift','callback_data'=>'tutup_shift_menu']];
                    if(in_array($callbackRole,['admin','owner','manager','finance'],true))$buttons[]=[['text'=>'📋 Riwayat Shift','callback_data'=>'riwayat_shift_menu']];
                    if($callbackRole==='owner'){
                        // Cermin tombol kas agar terpantau semua; eksekusi
                        // perubahan tetap ditolak di gerbang callback.
                        $buttons[]=[['text'=>'💵 Terima Panjar','callback_data'=>'panjar_menu']];
                        $buttons[]=[['text'=>'📥 Input Pemasukan','callback_data'=>'pemasukan_menu']];
                        $buttons[]=[['text'=>'📤 Input Pengeluaran','callback_data'=>'pengeluaran_menu'],['text'=>'📈 Laporan Kas','callback_data'=>'laporan_kas_refresh']];
                        $buttons[]=[['text'=>'🔑 Buka Shift','callback_data'=>'buka_shift_menu'],['text'=>'📝 Tutup Shift','callback_data'=>'tutup_shift_menu']];
                    }
                    $buttons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                    $replyText="💰 *KAS & SHIFT*\n\nPilih fungsi kas/shift yang tersedia untuk peran Anda.";$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Kas & Shift';
                } elseif ($callbackData === 'guest_service_menu' || str_starts_with($callbackData,'gsm:p:')) {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $page=str_starts_with($callbackData,'gsm:p:')?max(1,(int)substr($callbackData,strlen('gsm:p:'))):1;
                    $view=$buildTelegramGuestServiceMenu($pdo,$loggedInStaff,$page);$replyText=$view['text'];$replyMarkup=$view['markup'];$alertText='Permintaan Tamu';
                } elseif (str_starts_with($callbackData,'gsd:')) {
                    $id=trim(substr($callbackData,4));$view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText=$view['text'];$replyMarkup=$view['markup'];$alertText='Detail permintaan';
                } elseif (str_starts_with($callbackData,'gsa:')) {
                    $id=trim(substr($callbackData,4));
                    try{
                        $pdo->beginTransaction();claimTelegramMutation($pdo,$telegramCallbackOperationId,$loggedInStaff['id'],'guest_service_request',$id,['action'=>'assigned','id'=>$id],'callback');
                        $current=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);if(!$current)throw new RuntimeException('Permintaan tamu tidak ditemukan.');$state=strtolower((string)($current['status']??''));$assignee=trim((string)($current['assigned_to']??''));
                        if($state!=='open'){if($assignee!==''&&$assignee!==(string)$loggedInStaff['id'])throw new RuntimeException('Permintaan sudah diambil staf lain.');throw new TelegramDuplicateOperationException('Permintaan ini sudah diproses.');}
                        $result=tamasyaProgressGuestServiceRequest($pdo,$loggedInStaff,$id,'assigned',(string)$loggedInStaff['id'],'telegram-guest-service');completeTelegramMutation($pdo,$telegramCallbackOperationId,['id'=>$id,'status'=>'assigned']);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                        $view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText="✅ *PERMINTAAN DIAMBIL*\n\n".$view['text'];$replyMarkup=$view['markup'];$alertText='Permintaan diambil';
                    }catch(TelegramDuplicateOperationException $duplicate){if($pdo->inTransaction())$pdo->rollBack();$view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText="ℹ️ Permintaan sudah diproses.\n\n".$view['text'];$replyMarkup=$view['markup'];$alertText='Sudah diproses';}
                    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();failTelegramMutation($pdo,$telegramCallbackOperationId,$e);$replyText='⚠️ '.clientExceptionMessage('Permintaan tidak dapat diambil',$e);$replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Muat Ulang','callback_data'=>'gsd:'.$id]],[['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']]]];$alertText='Gagal mengambil';}
                } elseif (str_starts_with($callbackData,'gss:')) {
                    $id=trim(substr($callbackData,4));
                    try{
                        $pdo->beginTransaction();claimTelegramMutation($pdo,$telegramCallbackOperationId,$loggedInStaff['id'],'guest_service_request',$id,['action'=>'in_progress','id'=>$id],'callback');
                        $current=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);if(!$current)throw new RuntimeException('Permintaan tamu tidak ditemukan.');$state=strtolower((string)($current['status']??''));$assignee=trim((string)($current['assigned_to']??''));$role=strtolower((string)$loggedInStaff['role']);
                        if(in_array($state,['fulfilled','cancelled'],true))throw new RuntimeException('Permintaan sudah selesai/terminal.');
                        if($assignee!==''&&$assignee!==(string)$loggedInStaff['id']&&!hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager']))throw new RuntimeException('Permintaan ini sudah ditugaskan ke staf lain.');
                        $result=tamasyaProgressGuestServiceRequest($pdo,$loggedInStaff,$id,'in_progress',(string)$loggedInStaff['id'],'telegram-guest-service');completeTelegramMutation($pdo,$telegramCallbackOperationId,['id'=>$id,'status'=>'in_progress']);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                        $view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText="▶️ *PERMINTAAN MULAI DIKERJAKAN*\n\n".$view['text'];$replyMarkup=$view['markup'];$alertText='Mulai dikerjakan';
                    }catch(TelegramDuplicateOperationException $duplicate){if($pdo->inTransaction())$pdo->rollBack();$view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText="ℹ️ Tindakan ini sudah diproses.\n\n".$view['text'];$replyMarkup=$view['markup'];$alertText='Sudah diproses';}
                    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();failTelegramMutation($pdo,$telegramCallbackOperationId,$e);$replyText='⚠️ '.clientExceptionMessage('Permintaan belum dapat dimulai',$e);$replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Muat Ulang','callback_data'=>'gsd:'.$id]],[['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']]]];$alertText='Gagal memulai';}
                } elseif (str_starts_with($callbackData,'gsc:')) {
                    $id=trim(substr($callbackData,4));$current=tamasyaGuestServiceRequestByIdentifier($pdo,$id,false);$role=strtolower((string)$loggedInStaff['role']);$assignee=trim((string)($current['assigned_to']??''));
                    if(!$current){$replyText='⚠️ Permintaan tamu tidak ditemukan.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']]]];$alertText='Tidak ditemukan';}
                    elseif(in_array(strtolower((string)$current['status']),['fulfilled','cancelled'],true)){$view=$buildTelegramGuestServiceDetail($pdo,$loggedInStaff,$id);$replyText='ℹ️ Permintaan ini sudah selesai/terminal.\n\n'.$view['text'];$replyMarkup=$view['markup'];$alertText='Sudah selesai';}
                    elseif($assignee!==''&&$assignee!==(string)$loggedInStaff['id']&&!hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist'])){$replyText='🔒 Permintaan ini ditugaskan ke staf lain dan tidak dapat diselesaikan oleh akun ini.';$replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Detail','callback_data'=>'gsd:'.$id]],[['text'=>'⬅️ Antrean','callback_data'=>'gsm:p:1']]]];$alertText='Bukan PIC';}
                    else{$ctx=json_encode(['requestId'=>$id],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$pdo->prepare("UPDATE staff SET telegram_state='waiting_for_guest_service_completion_note',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);$replyText="✅ *SELESAIKAN PERMINTAAN TAMU*\n\nKetik catatan hasil layanan minimal 3 karakter.\nContoh: `2 handuk sudah diantar dan diterima tamu`.\n\nKetik `/batal` bila tidak jadi.";$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kembali ke Detail','callback_data'=>'gsd:'.$id]]]];$alertText='Ketik catatan selesai';}
                } elseif ($callbackData === 'field_ops_menu') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $buttons=[];
                    if(in_array($callbackRole,$guestServiceTelegramRoles,true))$buttons[]=[['text'=>'🔔 Permintaan Tamu','callback_data'=>'gsm:p:1']];
                    if(hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']))$buttons[]=[['text'=>'🧹 Housekeeping','callback_data'=>'housekeeping_menu']];
                    if(in_array($callbackRole,['admin','manager','receptionist','keamanan'],true))$buttons[]=[['text'=>'🚨 Patroli & Laporan','callback_data'=>'patrol_reports_menu']];
                    $buttons[]=[['text'=>'🚪 Laporkan Kamar Kosong','callback_data'=>'vacancy_menu']];
                    $buttons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                    $replyText="🧹 *OPERASIONAL LAPANGAN*\n\nPilih fungsi operasional yang sesuai tugas Anda.";$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Operasional';
                } elseif ($callbackData === 'vacancy_menu' || str_starts_with($callbackData,'vacancy_menu:p:')) {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $page=str_starts_with($callbackData,'vacancy_menu:p:')?max(1,(int)substr($callbackData,strlen('vacancy_menu:p:'))):1;
                    $pageSize=30;$count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
                    if($count<=0){$replyText='ℹ️ Tidak ada kamar dengan booking aktif.';$buttons=[];}
                    else{
                        $totalPages=max(1,(int)ceil($count/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;
                        $stmt=$pdo->prepare("SELECT roomNumber FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber LIMIT ? OFFSET ?");
                        $stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
                        $buttons=[];$row=[];foreach($rows as $number){$row[]=['text'=>'🚪 '.$number,'callback_data'=>'vacancy_report_room:'.$number];if(count($row)>=3){$buttons[]=$row;$row=[];}}if($row)$buttons[]=$row;
                        if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'vacancy_menu:p:');
                        $rangeStart=$offset+1;$rangeEnd=min($offset+$pageSize,$count);
                        $replyText="🚪 *LAPORKAN KAMAR KOSONG*\n\nPilih kamar yang benar-benar sudah kosong. Laporan ini tidak menjual kembali kamar secara otomatis.\n📄 Halaman *{$page}/{$totalPages}* · booking aktif *{$rangeStart}-{$rangeEnd}* dari *{$count}*.";
                    }
                    $buttons[]=[['text'=>'⬅️ Operasional','callback_data'=>'field_ops_menu']];$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Kamar Kosong';
                } elseif ($callbackData === 'account_menu') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $replyText="👤 *AKUN & DIAGNOSA*\n\nLihat identitas staf aktif atau jalankan pemeriksaan Telegram/Consistency Guard tanpa mengetik command.";
                    $accountButtons=[[['text'=>'👤 Profil Saya','callback_data'=>'account_profile'],['text'=>'🩺 Diagnosa Telegram','callback_data'=>'telegram_diagnostics']]];
                    if(in_array($callbackRole,['admin','manager','finance'],true))$accountButtons[]=[['text'=>'🛡️ Consistency Guard','callback_data'=>'consistency_guard']];
                    $accountButtons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                    $replyMarkup=['inline_keyboard'=>$accountButtons];$alertText='Akun & Diagnosa';
                } elseif ($callbackData === 'account_profile') {
                    $replyText="👤 *PROFIL STAF AKTIF*\n\n• Nama: *{$loggedInStaff['name']}*\n• Nama pengguna: `{$loggedInStaff['username']}`\n• Peran: *".$telegramRoleLabel((string)$loggedInStaff['role'])."*\n• Status: *Terhubung dan Aktif*\n• ID Telegram: `{$fromId}`";
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Akun & Diagnosa','callback_data'=>'account_menu']]]];$alertText='Profil Saya';
                } elseif ($callbackData === 'telegram_diagnostics') {
                    $timezone=date_default_timezone_get();$patchLevel=defined('TAMASYA_PATCH_LEVEL')?TAMASYA_PATCH_LEVEL:'UNKNOWN';
                    $dbClock=['db_timezone'=>'UNKNOWN','db_now'=>'UNKNOWN'];
                    try{$dbClockRow=$pdo->query("SELECT @@session.time_zone AS db_timezone,NOW() AS db_now")->fetch(PDO::FETCH_ASSOC)?:[];$dbClock=array_merge($dbClock,$dbClockRow);}catch(Throwable $e){$dbClock['db_timezone']='GAGAL';}
                    $expectedWebhook=buildPublicWebhookUrl();$remote=telegramApiCall($token,'getWebhookInfo');$remoteUrl=!empty($remote['ok'])?trim((string)($remote['data']['result']['url']??'')):'';$pendingUpdates=!empty($remote['ok'])?(int)($remote['data']['result']['pending_update_count']??0):-1;$webhookMatch=$remoteUrl!==''&&hash_equals($expectedWebhook,$remoteUrl);
                    $openShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id']);$shiftStatus=$openShift?('TERBUKA · '.strtoupper((string)$openShift['shift_time']).' · '.(string)$openShift['shift_date']):'TIDAK ADA SHIFT TERBUKA';
                    $replyText="🩺 *DIAGNOSA TELEGRAM*\n\n✅ Akun: *TERHUBUNG*\n👤 Staf: *{$loggedInStaff['name']}*\n🔐 Peran: *".$telegramRoleLabel((string)$callbackRole)."*\n🧾 Shift: *{$shiftStatus}*\n🌐 Webhook: *".($webhookMatch?'SINKRON':($remoteUrl===''?'BELUM AKTIF':'SALAH TUJUAN'))."*\n📬 Update tertunda: *".($pendingUpdates>=0?$pendingUpdates:'GAGAL DIBACA')."*\n🏷️ Versi: *{$patchLevel}*\n🕒 App TZ: *{$timezone}*\n🗄️ DB TZ: *".str_replace(['*','_','`'],'',(string)$dbClock['db_timezone'])."*\n📅 DB Now: *".str_replace(['*','_','`'],'',(string)$dbClock['db_now'])."*";
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Periksa Lagi','callback_data'=>'telegram_diagnostics']],[['text'=>'⬅️ Akun & Diagnosa','callback_data'=>'account_menu']]]];$alertText='Diagnosa Telegram';
                } elseif ($callbackData === 'consistency_guard') {
                    if(!$loggedInStaff || !in_array($callbackRole,['admin','manager','finance'],true)){
                        $replyText="🔒 *AKSES DITOLAK*\n\nConsistency Guard hanya dapat dibaca Admin, Manajer, atau Keuangan. Tidak ada data yang diubah.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Akun & Diagnosa','callback_data'=>'account_menu']]]];$alertText='Akses ditolak';
                    }else{
                        try{
                            $guard=tamasyaConsistencyGuardTelegramSummary($pdo,3);
                            $replyText=(string)$guard['text'];
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Periksa Lagi','callback_data'=>'consistency_guard']],[['text'=>'⬅️ Akun & Diagnosa','callback_data'=>'account_menu']]]];
                            $alertText='Consistency Guard '.(string)($guard['snapshot']['overall']??'UNKNOWN');
                        }catch(Throwable $e){
                            $replyText=clientExceptionMessage('❌ Consistency Guard gagal membaca status',$e)."\n\nTidak ada data bisnis yang diubah.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Coba Lagi','callback_data'=>'consistency_guard']],[['text'=>'⬅️ Akun & Diagnosa','callback_data'=>'account_menu']]]];$alertText='Guard gagal';
                        }
                    }
                } elseif ($callbackData === 'leave_menu') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $menu=$buildTelegramLeaveMenu($pdo,$loggedInStaff);
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Cuti Saya';
                } elseif ($callbackData === 'leave_new') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $replyText="➕ *AJUKAN CUTI*\n\nPilih jenis cuti:";
                    $replyMarkup=['inline_keyboard'=>[
                        [['text'=>'🌴 Cuti Tahunan','callback_data'=>'leave_type:annual'],['text'=>'🤒 Sakit','callback_data'=>'leave_type:sick']],
                        [['text'=>'📝 Izin','callback_data'=>'leave_type:permission'],['text'=>'👨‍👩‍👧 Cuti Keluarga','callback_data'=>'leave_type:family']],
                        [['text'=>'💸 Cuti Tanpa Gaji','callback_data'=>'leave_type:unpaid'],['text'=>'📌 Lainnya','callback_data'=>'leave_type:other']],
                        [['text'=>'⬅️ Kembali','callback_data'=>'leave_menu']]
                    ]];
                    $alertText='Pilih jenis cuti';
                } elseif (str_starts_with($callbackData,'leave_type:')) {
                    $leaveType=substr($callbackData,strlen('leave_type:'));
                    if(!array_key_exists($leaveType,tamasyaEmployeeLeaveTypeLabels())){
                        $replyText='⚠️ Jenis cuti tidak valid. Tidak ada data yang diubah.';$alertText='Jenis cuti tidak valid';
                    }else{
                        $ctx=json_encode(['leaveType'=>$leaveType,'requestNonce'=>bin2hex(random_bytes(12))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_leave_start',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                        $replyText="📅 *TANGGAL MULAI CUTI*\n\nJenis: *".tamasyaEmployeeLeaveTypeLabel($leaveType)."*\n\nKetik tanggal mulai dengan format `YYYY-MM-DD`.\nContoh: `2026-08-15`\n\nKetik `/batal` untuk membatalkan.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Ganti Jenis Cuti','callback_data'=>'leave_new']]]];
                        $alertText='Masukkan tanggal mulai';
                    }
                } elseif ($callbackData === 'leave_history') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $rows=tamasyaEmployeeMyLeaveRequests($pdo,(string)$loggedInStaff['id'],10);
                    if(!$rows){
                        $replyText="📚 *RIWAYAT CUTI*\n\nBelum ada pengajuan cuti.";
                        $buttons=[];
                    }else{
                        $lines=["📚 *RIWAYAT CUTI*",""];
                        $buttons=[];$pendingButtons=0;
                        foreach($rows as $row){
                            $lines[]='• '.$row['startDate'].' s.d. '.$row['endDate'].' — '.tamasyaEmployeeLeaveTypeLabel((string)$row['leaveType']).' — '.$telegramLeaveStatusLabel((string)$row['status']);
                            if((string)$row['status']==='pending' && $pendingButtons<5){
                                $buttons[]=[["text"=>'❌ Batalkan '.$row['startDate'],"callback_data"=>'leave_cancel_prompt:'.$row['id']]];$pendingButtons++;
                            }
                        }
                        $replyText=implode("\n",$lines);
                    }
                    $buttons[]=[['text'=>'➕ Ajukan Cuti','callback_data'=>'leave_new'],['text'=>'⬅️ Kembali','callback_data'=>'leave_menu']];
                    $replyMarkup=['inline_keyboard'=>$buttons];$alertText='Riwayat Cuti';
                } elseif (str_starts_with($callbackData,'leave_cancel_prompt:')) {
                    $requestId=substr($callbackData,strlen('leave_cancel_prompt:'));
                    $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE id=? AND staff_id=? AND status='pending' LIMIT 1");$stmt->execute([$requestId,$loggedInStaff['id']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                    if(!$row){$replyText='⚠️ Pengajuan menunggu tidak ditemukan atau statusnya sudah berubah.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Riwayat Cuti','callback_data'=>'leave_history']]]];$alertText='Tidak dapat dibatalkan';}
                    else{
                        $replyText="⚠️ *KONFIRMASI PEMBATALAN CUTI*\n\nPeriode: {$row['start_date']} s.d. {$row['end_date']}\nJenis: ".tamasyaEmployeeLeaveTypeLabel((string)$row['leave_type'])."\n\nPembatalan tidak akan menghapus jejak audit.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Ya, Batalkan','callback_data'=>'leave_cancel_confirm:'.$requestId]],[['text'=>'⬅️ Jangan Batalkan','callback_data'=>'leave_history']]]];$alertText='Konfirmasi pembatalan';
                    }
                } elseif (str_starts_with($callbackData,'leave_cancel_confirm:')) {
                    $requestId=substr($callbackData,strlen('leave_cancel_confirm:'));
                    try{
                        tamasyaEmployeeCancelLeaveRequest($pdo,$loggedInStaff,$requestId,'Dibatalkan oleh staf melalui Telegram','telegram');
                        $replyText="✅ *PENGAJUAN CUTI DIBATALKAN*\n\nStatus tersimpan dan jejak audit dipertahankan.";$replyMarkup=['inline_keyboard'=>[[['text'=>'📚 Riwayat Cuti','callback_data'=>'leave_history'],['text'=>'⬅️ Menu Cuti','callback_data'=>'leave_menu']]]];$alertText='Cuti dibatalkan';
                    }catch(Throwable $leaveCancelError){$replyText='⚠️ '.clientExceptionMessage('Pengajuan cuti tidak dapat dibatalkan',$leaveCancelError);$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Riwayat Cuti','callback_data'=>'leave_history']]]];$alertText='Pembatalan gagal';}
                } elseif ($callbackData === 'leave_confirm') {
                    $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                    if(!is_array($ctx)||($loggedInStaff['telegram_state']??'')!=='waiting_for_leave_confirm'){
                        $replyText='⚠️ Data pengajuan cuti sudah tidak aktif. Mulai kembali dari menu Cuti Saya.';$replyMarkup=['inline_keyboard'=>[[['text'=>'🗓️ Cuti Saya','callback_data'=>'leave_menu']]]];$alertText='Sesi pengajuan berakhir';
                    }else{
                        try{
                            $result=tamasyaEmployeeCreateLeaveRequest($pdo,$loggedInStaff,$ctx,tamasyaEmployeeTelegramLeaveOperationId($loggedInStaff,$ctx,$telegramCallbackOperationId),'telegram');
                            try{$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);}
                            catch(Throwable $leaveStateCleanupError){error_log(clientExceptionMessage('[telegram] leave state cleanup failed',$leaveStateCleanupError));}
                            $replyText="✅ *PENGAJUAN CUTI TERKIRIM*\n\nPeriode: {$ctx['startDate']} s.d. {$ctx['endDate']}\nJenis: ".tamasyaEmployeeLeaveTypeLabel((string)$ctx['leaveType'])."\nStatus: *MENUNGGU PERSETUJUAN*".(!empty($result['duplicate'])?"\n\nPermintaan ini sudah pernah diproses; data tidak digandakan.":'');
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'📚 Riwayat Cuti','callback_data'=>'leave_history'],['text'=>'⬅️ Menu Cuti','callback_data'=>'leave_menu']]]];$alertText='Pengajuan terkirim';
                        }catch(Throwable $leaveCreateError){
                            $replyText='⚠️ *PENGAJUAN BELUM TERSIMPAN*\n\n'.clientExceptionMessage('Pengajuan cuti ditolak',$leaveCreateError).'\n\nData input tetap disimpan sementara; perbaiki melalui tombol di bawah atau mulai ulang.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔁 Coba Simpan Lagi','callback_data'=>'leave_confirm']],[['text'=>'⬅️ Mulai Ulang','callback_data'=>'leave_new']]]];$alertText='Belum tersimpan';
                        }
                    }
                } elseif ($callbackData === 'leave_team') {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $rows=tamasyaEmployeePendingTeamLeave($pdo,(string)$loggedInStaff['id'],12);
                    $lines=["✅ *PERSETUJUAN CUTI*",""]; $buttons=[];
                    if(!$rows){$lines[]='Tidak ada pengajuan cuti staf lain yang menunggu persetujuan.';}
                    foreach($rows as $row){
                        $safeName=$telegramPlainText($row['staffName']);
                        $lines[]='• '.$safeName.' | '.$row['startDate'].' s.d. '.$row['endDate'].' | '.tamasyaEmployeeLeaveTypeLabel((string)$row['leaveType']);
                        $buttons[]=[["text"=>'🔎 '.$safeName.' · '.$row['startDate'],"callback_data"=>'leave_approve:'.$row['id']]];
                    }
                    $buttons[]=[['text'=>'⬅️ Menu Cuti','callback_data'=>'leave_menu']];
                    $replyText=implode("\n",$lines);$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Persetujuan Cuti';
                } elseif (str_starts_with($callbackData,'leave_approve:')) {
                    $requestId=substr($callbackData,strlen('leave_approve:'));
                    $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE id=? AND status='pending' AND staff_id<>? LIMIT 1");$stmt->execute([$requestId,$loggedInStaff['id']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                    if(!$row){$replyText='⚠️ Pengajuan sudah berubah, tidak ditemukan, atau merupakan pengajuan Anda sendiri.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Persetujuan Cuti','callback_data'=>'leave_team']]]];$alertText='Tidak dapat diproses';}
                    else{
                        $safeName=$telegramPlainText($row['staff_name']);$safeReason=$telegramPlainText($row['reason']);
                        $replyText="🔎 *TINJAU PENGAJUAN CUTI*\n\nStaf: {$safeName}\nJenis: ".tamasyaEmployeeLeaveTypeLabel((string)$row['leave_type'])."\nPeriode: {$row['start_date']} s.d. {$row['end_date']} ({$row['days_requested']} hari)\nAlasan: {$safeReason}\n\nSetujui hanya setelah Anda benar-benar memeriksa jadwal operasional.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Konfirmasi Setujui','callback_data'=>'leave_approve_confirm:'.$requestId]],[['text'=>'❌ Tolak dengan Alasan','callback_data'=>'leave_reject:'.$requestId]],[['text'=>'⬅️ Kembali','callback_data'=>'leave_team']]]];$alertText='Tinjau pengajuan';
                    }
                } elseif (str_starts_with($callbackData,'leave_approve_confirm:')) {
                    $requestId=substr($callbackData,strlen('leave_approve_confirm:'));
                    try{
                        tamasyaEmployeeDecideLeaveRequest($pdo,$loggedInStaff,$requestId,'approved','Disetujui melalui Telegram','telegram');
                        $replyText='✅ *CUTI DISETUJUI*\n\nKeputusan telah tersimpan, proyeksi cuti kompatibilitas diperbarui, notifikasi staf diantrikan, dan jejak audit dicatat.';$replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Persetujuan Lain','callback_data'=>'leave_team'],['text'=>'⬅️ Menu Cuti','callback_data'=>'leave_menu']]]];$alertText='Cuti disetujui';
                    }catch(Throwable $leaveApproveError){$replyText='⚠️ '.clientExceptionMessage('Keputusan cuti tidak dapat disimpan',$leaveApproveError);$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Persetujuan Cuti','callback_data'=>'leave_team']]]];$alertText='Keputusan gagal';}
                } elseif (str_starts_with($callbackData,'leave_reject:')) {
                    $requestId=substr($callbackData,strlen('leave_reject:'));
                    $stmt=$pdo->prepare("SELECT id FROM staff_leave_requests WHERE id=? AND status='pending' AND staff_id<>? LIMIT 1");$stmt->execute([$requestId,$loggedInStaff['id']]);
                    if(!$stmt->fetchColumn()){$replyText='⚠️ Pengajuan sudah berubah, tidak ditemukan, atau merupakan pengajuan Anda sendiri.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Persetujuan Cuti','callback_data'=>'leave_team']]]];$alertText='Tidak dapat ditolak';}
                    else{
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_leave_reject_reason',telegram_context=? WHERE id=?")->execute([json_encode(['requestId'=>$requestId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $replyText="❌ *ALASAN PENOLAKAN*\n\nKetik alasan penolakan minimal 5 karakter. Alasan akan tersimpan pada audit keputusan dan diberitahukan kepada staf.\n\nKetik `/batal` untuk membatalkan proses.";$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Batalkan Penolakan','callback_data'=>'leave_team']]]];$alertText='Masukkan alasan penolakan';
                    }
                } elseif ($callbackData === "room_list" || str_starts_with($callbackData,"room_list:p:")) {
                    $page=1;
                    if(str_starts_with($callbackData,"room_list:p:"))$page=max(1,(int)substr($callbackData,strlen("room_list:p:")));
                    $pageSize=30;
                    $countRooms=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
                    $totalPages=max(1,(int)ceil($countRooms/$pageSize));
                    $page=min($page,$totalPages);$offset=($page-1)*$pageSize;
                    $stmtRooms=$pdo->prepare("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC, number ASC LIMIT ? OFFSET ?");
                    $stmtRooms->bindValue(1,$pageSize,PDO::PARAM_INT);$stmtRooms->bindValue(2,$offset,PDO::PARAM_INT);$stmtRooms->execute();
                    $rooms=$stmtRooms->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));
                    $inlineKeyboardButtons=[];$currentRow=[];
                    foreach($rooms as $room){
                        $number=(string)$room['number'];$canonical=$roomStatusMap[$number]??'maintenance';
                        $statusIcon=$canonical==='booked'?'🔴':($canonical==='dirty'?'🧹':($canonical==='maintenance'?'🛠️':'🟢'));
                        $currentRow[]=["text"=>$statusIcon." ".$number,"callback_data"=>"room_select:".$number];
                        if(count($currentRow)>=3){$inlineKeyboardButtons[]=$currentRow;$currentRow=[];}
                    }
                    if($currentRow)$inlineKeyboardButtons[]=$currentRow;
                    if($totalPages>1){$nav=[];if($page>1)$nav[]=["text"=>"⬅️","callback_data"=>"room_list:p:".($page-1)];$nav[]=["text"=>"📄 {$page}/{$totalPages}","callback_data"=>"room_list:p:{$page}"];if($page<$totalPages)$nav[]=["text"=>"➡️","callback_data"=>"room_list:p:".($page+1)];$inlineKeyboardButtons[]=$nav;}
                    $inlineKeyboardButtons[]=[["text"=>"⬅️ Kamar & Tamu","callback_data"=>"guest_ops_menu"]];
                    $replyText="🔎 *DIAGNOSA OPERASIONAL KAMAR*\n\nPilih nomor kamar untuk melihat status canonical dan blocker. Status kamar tidak dapat diubah manual. Ditampilkan maksimal *{$pageSize} kamar per halaman*.";
                    $replyMarkup=["inline_keyboard"=>$inlineKeyboardButtons];$alertText="Kamar {$page}/{$totalPages}";
                } elseif (strpos($callbackData, "room_select:") === 0) {
                    $roomNumber = trim((string)(explode(":", $callbackData, 2)[1] ?? ''));
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$roomNumber]);
                    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
                    
                    if ($room) {
                        // Tombol Telegram harus mengikuti state operasional yang sama dengan
                        // updateRoomStatusSafely(). Status booked hanya boleh berasal dari
                        // lifecycle booking aktif; jangan pernah menawarkannya sebagai aksi manual.
                        $roomBlockers=getRoomOperationalBlockersFastRead($pdo,$roomNumber);
                        $canonicalStatus=tamasyaDeriveRoomOperationalStatus($roomBlockers);
                        $statusIcon=$canonicalStatus==='booked'?'🔴':($canonicalStatus==='dirty'?'🧹':($canonicalStatus==='maintenance'?'🛠️':'🟢'));
                        $activeBookingId='';
                        foreach($roomBlockers as $roomBlocker){
                            if((string)($roomBlocker['type']??'')==='active_booking'){
                                $activeBookingId=trim((string)($roomBlocker['id']??''));
                                break;
                            }
                        }
                        $actionRows=[];
                        $operationalNote='';
                        if($activeBookingId!==''){
                            $operationalNote="\n\n🔒 Status kamar dikendalikan booking aktif *{$activeBookingId}*. Gunakan workflow checkout/pindah kamar; status tidak boleh diubah manual.";
                        }else{
                            if($canonicalStatus==='available' && strtolower((string)($room['status']??''))!=='available'){
                                $operationalNote="

ℹ️ Status tersimpan berbeda dari lifecycle domain dan akan direkonsiliasi otomatis pada mutasi berikutnya.";
                            }
                            if($roomBlockers){
                                $operationalNote="\n\n⚠️ ".roomOperationalBlockerMessage($roomNumber,$roomBlockers);
                            }elseif(!$actionRows){
                                $operationalNote="\n\nℹ️ Tidak ada perubahan status manual yang diperlukan.";
                            }
                        }
                        if($activeBookingId!=='' && hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist'])){
                            // Read-only parity: show authoritative key state. Never fabricate issuance from Telegram.
                            $actionRows[]=[["text"=>"🔑 Status Kunci / PIN","callback_data"=>"key_status:".$activeBookingId]];
                        }
                        $actionRows[]=[["text"=>"⬅️ Kembali","callback_data"=>"room_list"]];
                        $replyText = "🔎 *DIAGNOSA KAMAR {$roomNumber}*\n\nStatus operasional: " . $statusIcon . " *" . $telegramRoomStatusLabel($canonicalStatus) . "*".$operationalNote;
                        $replyMarkup = ["inline_keyboard"=>$actionRows];
                        $alertText = "Kamar " . $roomNumber;
                    } else {
                        $replyText = "❌ Kamar tidak ditemukan!";
                    }
                } elseif (str_starts_with($callbackData, 'key_status:')) {
                    // Staff can inspect the canonical booking/access record without generating a PIN,
                    // bypassing payment/shift controls, or mutating the booking state.
                    if(!hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist'])){
                        $replyText='🔒 Anda tidak memiliki akses kontrol kunci kamar.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kamar','callback_data'=>'room_list']]]];
                        $alertText='Akses ditolak';
                    }else{
                        $keyBookingId=trim(substr($callbackData,strlen('key_status:')));
                        $stmtKey=$pdo->prepare("SELECT id,roomNumber,keyControlStatus,accessMode,status FROM bookings WHERE id=? AND status='active' LIMIT 1");
                        $stmtKey->execute([$keyBookingId]);
                        $keyBooking=$stmtKey->fetch(PDO::FETCH_ASSOC)?:null;
                        if(!$keyBooking){
                            $replyText='⚠️ Booking sudah tidak aktif atau telah berubah. Refresh daftar kamar sebelum menyerahkan kunci.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Refresh Kamar','callback_data'=>'room_list']]]];
                            $alertText='Status booking berubah';
                        }else{
                            $keyRoom=(string)$keyBooking['roomNumber'];
                            $stmtAccess=$pdo->prepare("SELECT physical_key_status,current_booking_id FROM room_access_control WHERE room_number=? LIMIT 1");
                            $stmtAccess->execute([$keyRoom]);
                            $keyAccess=$stmtAccess->fetch(PDO::FETCH_ASSOC)?:[];
                            $keyStatus=(string)($keyBooking['keyControlStatus']??'not_issued');
                            $keyMode=(string)($keyBooking['accessMode']??'physical');
                            $keyStatusText=match($keyStatus){
                                'issued'=>'✅ Sudah diserahkan','pending_smart_issue'=>'⏳ Menunggu konfirmasi smart-lock',
                                'returned'=>'🔄 Sudah dikembalikan',default=>'⛔ Belum diserahkan'
                            };
                            $keyCustody=array_merge($keyBooking,$keyAccess);
                            $keyConflict=false;$keyIssueBlocker='';
                            try { tamasyaAssertRoomAccessIssueState($keyCustody,$keyBookingId); }
                            catch(RuntimeException $keyGuardError){$keyConflict=true;$keyIssueBlocker=$keyGuardError->getMessage();}
                            $replyText="🔑 *STATUS KUNCI KAMAR*\n\nKamar: *".$telegramPlainText($keyRoom)."*\nStatus: *".$keyStatusText."*\nMode: *".$telegramPlainText($keyMode)."*"
                                .($keyConflict?"\n\n⚠️ ".$telegramPlainText($keyIssueBlocker):"")
                                ."\n\nUntuk kunci fisik gunakan tombol Serahkan Kunci Fisik di bawah; validasi pembayaran, shift, dan smart-lock tidak dilewati. Penerbitan PIN smart-lock/hybrid tetap melalui Pusat Operasional Web dengan verifikasi bridge.";
                            $keyButtons=[[['text'=>'🔄 Periksa Lagi','callback_data'=>'key_status:'.$keyBookingId]]];
                            if($keyMode==='physical' && !$keyConflict){
                                $keyButtons[]=[['text'=>'🔑 Serahkan Kunci Fisik','callback_data'=>'key_issue_review:'.$keyBookingId]];
                            }
                            $keyButtons[]=[['text'=>'⬅️ Kamar','callback_data'=>'room_select:'.$keyRoom]];
                            $replyMarkup=['inline_keyboard'=>$keyButtons];
                            $alertText='Status kunci';
                        }
                    }
                } elseif (str_starts_with($callbackData,'key_issue_review:')) {
                    // Preview is read-only. A second, explicit confirmation is required.
                    if(!hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist'])){
                        $replyText='🔒 Anda tidak memiliki hak menyerahkan kunci.';
                        $alertText='Akses ditolak';
                    }else{
                        $bookingId=trim((string)substr($callbackData,strlen('key_issue_review:')));
                        $reviewStmt=$pdo->prepare("SELECT b.id,b.roomNumber,b.status,b.keyControlStatus,COALESCE(rac.access_mode,'physical') access_mode,rac.physical_key_status,rac.current_booking_id FROM bookings b LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber WHERE b.id=? AND b.status='active' LIMIT 1");
                        $reviewStmt->execute([$bookingId]);$keyReview=$reviewStmt->fetch(PDO::FETCH_ASSOC)?:null;
                        $keyReviewEligible=false;
                        if($keyReview && ($keyReview['access_mode']??'physical')==='physical'){
                            try {tamasyaAssertRoomAccessIssueState($keyReview,$bookingId);$keyReviewEligible=true;}
                            catch(RuntimeException $ignoredKeyGuard){$keyReviewEligible=false;}
                        }
                        if(!$keyReviewEligible){
                            $replyText='⚠️ Booking/kunci berubah atau mode bukan kunci fisik. Buka status terbaru.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Refresh Kamar','callback_data'=>'room_list']]]];
                        }else{
                            $replyText="🔑 *KONFIRMASI PENYERAHAN KUNCI FISIK*\n\nKamar: *".$telegramPlainText((string)$keyReview['roomNumber'])."*\n\nPastikan tamu hadir dan kunci telah benar-benar diberikan. Tombol konfirmasi memeriksa ulang shift, pembayaran, booking aktif, dan konflik kunci dalam satu transaksi. Tidak membuat transaksi kas atau PIN baru.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Ya, Kunci Sudah Diserahkan','callback_data'=>'key_issue_confirm:'.$bookingId]],[['text'=>'✖️ Batal','callback_data'=>'key_status:'.$bookingId]]]];
                        }
                        $alertText='Konfirmasi kunci';
                    }
                } elseif (str_starts_with($callbackData,'key_issue_confirm:')) {
                    if(!hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist'])){
                        $replyText='🔒 Anda tidak memiliki hak menyerahkan kunci.';
                        $alertText='Akses ditolak';
                    }else{
                        $bookingId=trim((string)substr($callbackData,strlen('key_issue_confirm:')));
                        try {
                            // Single canonical write path, identical to Web. Telegram never generates/displays PIN.
                            $issuance=tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId,'Penyerahan kunci fisik dikonfirmasi dari Telegram.','telegram',$telegramCallbackOperationId,true);
                            $keyRoom=(string)$issuance['booking']['roomNumber'];
                            $replyText="✅ *KUNCI FISIK TERCATAT DISERAHKAN*\n\nKamar: *".$telegramPlainText($keyRoom)."*\nPetugas: *".$telegramPlainText(currentStaffLabel($loggedInStaff))."*\n\nStatus tersimpan pada booking dan jurnal audit.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Status Kunci','callback_data'=>'key_status:'.$bookingId]]]];
                            $alertText='Kunci diserahkan';
                        } catch (TelegramDuplicateOperationException $duplicateKeyIssue) {
                            $replyText='ℹ️ Konfirmasi penyerahan ini sudah diproses sebelumnya. Periksa status kunci untuk hasil terbaru.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Periksa Status','callback_data'=>'key_status:'.$bookingId]]]];
                            $alertText='Sudah diproses';
                        } catch (Throwable $issueError) {
                            $replyText='⚠️ '.clientExceptionMessage('Kunci belum dicatat',$issueError);
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Periksa Status','callback_data'=>'key_status:'.$bookingId]]]];
                            $alertText='Penyerahan ditolak';
                        }
                    }
                } elseif (strpos($callbackData, "room_set:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $newStatus = $parts[2];
                    
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } else {
                        // Legacy callback tidak lagi boleh menulis rooms.status. Status kamar adalah
                        // projection dari lifecycle domain; operator harus membuka workflow pemiliknya.
                        $room = null;
                        $roomBlockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
                        $derived=tamasyaDeriveRoomOperationalStatus($roomBlockers);
                        $replyText = "ℹ️ Status kamar tidak dapat diubah manual. Status operasional saat ini: ".strtoupper($derived).". ".($roomBlockers?roomOperationalBlockerMessage($roomNumber,$roomBlockers):'Tidak ada blocker; refresh projection akan menjaga cache kamar sinkron.');
                        $alertText = 'Gunakan workflow operasional';
                        
                        // Legacy room_set callback is intentionally read-only.
                        // No notification/broadcast is emitted because no mutation occurred.
                    }
                } elseif ($callbackData === 'reservation_list' || str_starts_with($callbackData,'reservation_list:p:')) {
                    $page=str_starts_with($callbackData,'reservation_list:p:')?max(1,(int)substr($callbackData,strlen('reservation_list:p:'))):1;
                    $menu=$buildTelegramReservedBookingPicker($pdo,$page,20);$replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Daftar Reservasi';
                } elseif (str_starts_with($callbackData,'reservation_detail:')) {
                    $bookingId=substr($callbackData,strlen('reservation_detail:'));
                    $stmtReservation=$pdo->prepare("SELECT id,roomNumber,guestName,checkIn,checkOut,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt,paymentStatus,bookingSource,totalAmount,status FROM bookings WHERE id=? AND status='reserved' LIMIT 1");
                    $stmtReservation->execute([$bookingId]);$reservation=$stmtReservation->fetch(PDO::FETCH_ASSOC)?:null;
                    if(!$reservation){$replyText='⚠️ Reservasi sudah berubah status atau tidak ditemukan. Muat ulang daftar reservasi.';}
                    else{
                        $guest=$telegramPlainText($reservation['guestName']??'Tamu');$room=(string)$reservation['roomNumber'];$dateOut=!empty($reservation['isOpenEnded'])?'Terbuka':(string)$reservation['checkOut'];
                        $replyText="📅 *DETAIL RESERVASI*\n\nID: `".(string)$reservation['id']."`\n👤 Tamu: *{$guest}*\n🚪 Kamar: *{$room}*\n🗓 Periode: `".(string)$reservation['checkIn']."` → `{$dateOut}`\n💳 Pembayaran: *".$telegramPaymentStatusLabel((string)$reservation['paymentStatus'])."*\n📡 Sumber: *".$telegramPlainText($reservation['bookingSource']??'Direct')."*\n\nKamar tidak dianggap Terisi hanya karena reservasi ini. Interval sebelum/sesudah tetap dapat dipakai bila tidak overlap.";
                    }
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Daftar Reservasi','callback_data'=>'reservation_list']],[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]];$alertText='Detail Reservasi';
                } elseif ($callbackData === "sell_room_list" || str_starts_with($callbackData,"sell_room_list:p:")) {
                    $page=str_starts_with($callbackData,"sell_room_list:p:")?max(1,(int)substr($callbackData,strlen("sell_room_list:p:"))):1;
                    $menu=$buildTelegramSellableRoomPicker(
                        $pdo,'r_sell:','sell_room_list:p:',"🛒 *MENU PENJUALAN KAMAR*",
                        'Pilih kamar kosong yang ingin dijual/sewakan sekarang.',
                        '❌ *MAAF, SEMUA KAMAR PENUH!*

Tidak ada kamar yang benar-benar tersedia secara operasional untuk dijual saat ini.',
                        'guest_ops_menu',$page
                    );
                    $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Penjualan Kamar';
                } elseif (strpos($callbackData, "r_sell:") === 0) {
                    $roomNumber = explode(":", $callbackData)[1];
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$roomNumber]);
                    $room = $stmtRoom->fetch();
                    
                    if ($room) {
                        $roomBlockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
                        $roomOperationalStatus=tamasyaDeriveRoomOperationalStatus($roomBlockers);
                        if ($roomOperationalStatus !== 'available') {
                            $replyText = $roomBlockers
                                ? "⚠️ ".roomOperationalBlockerMessage($roomNumber,$roomBlockers)
                                : "⚠️ Kamar {$roomNumber} belum tersedia secara operasional.";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "⬅️ Pilih Kamar Lain", "callback_data" => "sell_room_list"]]
                                ]
                            ];
                        } else {
                            $publishedRate=resolveConfiguredTaxRate($pdo,'Direct','room',0,date('Y-m-d'));
                            $p1=tamasyaPublishedRoomGrossTotal((float)$room['price'],1,(float)$publishedRate);
                            
                            $replyText = "🛒 *PILIH PAKET PENJUALAN - KAMAR {$roomNumber}*\n" .
                                         "Tipe: *{$room['type']}* | Lantai: *{$room['floor']}*\n" .
                                         "Tarif: *Rp " . tamasyaTelegramFormatAmount($p1) . "/malam*\n\n" .
                                         "Pilih paket penjualan cepat atau gunakan data kustom:";
                            
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [
                                        ["text" => "⚡ 1 Malam (Lunas) - Walk-in", "callback_data" => "r_sell_walkin_ktp:" . $roomNumber . ":Walk-in:1:paid"],
                                    ],
                                    [
                                        ["text" => "✍️ Tarif Kamar (Nominal Kustom)...", "callback_data" => "r_sell_custom:" . $roomNumber],
                                    ],
                                    [
                                        ["text" => "⚡ 1 Malam (Belum Lunas) - Walk-in", "callback_data" => "r_sell_walkin_ktp:" . $roomNumber . ":Walk-in:1:unpaid"],
                                    ],
                                    [
                                        ["text" => "⬅️ Kembali ke Daftar", "callback_data" => "sell_room_list"]
                                    ]
                                ]
                            ];
                            $alertText = "Kamar " . $roomNumber;
                        }
                    } else {
                        $replyText = "❌ Kamar tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "r_sell_custom:") === 0) {
                    $roomNumber = explode(":", $callbackData)[1];
                    if ($loggedInStaff) {
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_guest_name', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$roomNumber, $loggedInStaff['id']]);
                        
                        $replyText = "✍️ *PENJUALAN KAMAR {$roomNumber} - NAMA KUSTOM*\n\n" .
                                     "Silakan **ketik Nama Tamu** langsung di chat ini (tanpa perintah / command):";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login terlebih dahulu untuk menjual kamar.";
                    }
                    $alertText = "Ketik Nama Tamu";
                } elseif (strpos($callbackData, "r_sell_open_ended_select:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } else {
                        $ktpPhotoPath = "";
                        if (!empty($loggedInStaff['telegram_context'])) {
                            $ctxParts = explode(":", $loggedInStaff['telegram_context']);
                            $ktpPhotoPath = $ctxParts[2] ?? "";
                        }
                        $newCtx = $roomNumber . ":" . $guestName . ":" . $ktpPhotoPath;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_sell_dp_amount', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                        
                        $replyText = "💰 *INPUT UANG PANJAR (DP) TAMU*\n\n" .
                                     "• Kamar: *{$roomNumber}*\n" .
                                     "• Tamu: *{$guestName}*\n\n" .
                                     "Catat Uang Panjar (DP) jika diterima; ketik 0 jika belum ada pembayaran.\n\n" .
                                     "Silakan **ketik langsung nominal uang panjar (DP) yang dibayar tamu** (contoh: `250000` atau ketik `0` jika tanpa DP):";;
                                     
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                        $alertText = "Input DP Tamu";
                    }
                } elseif (strpos($callbackData, "r_sell_dp_confirm:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    $dpAmount = (float)$parts[3];
                    $dpMethod = $parts[4];
                    $dpBankAccountId = ($parts[5] !== 'none' && $parts[5] !== '') ? $parts[5] : null;

                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } elseif (($loggedInStaff['telegram_state']??'')!=='waiting_for_sell_dp_method'
                        || (($saleDpParts=explode(':',(string)($loggedInStaff['telegram_context']??'')))[0]??'')!==$roomNumber
                        || ($saleDpParts[1]??'')!==$guestName || !isset($saleDpParts[3]) || abs((float)$saleDpParts[3]-$dpAmount)>0.01) {
                        $replyText='⚠️ Sesi DP sewa durasi terbuka tidak cocok atau sudah kedaluwarsa. Mulai kembali Jual Kamar.';$alertText='Sesi tidak valid';
                    } else {
                        $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                        $stmtRoom->execute([$roomNumber]);
                        $room = $stmtRoom->fetch();

                        if ($room) {
                            $roomBlockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
                            if (tamasyaDeriveRoomOperationalStatus($roomBlockers) !== 'available') {
                                $replyText = $roomBlockers ? "⚠️ ".roomOperationalBlockerMessage($roomNumber,$roomBlockers) : "⚠️ Gagal! Kamar {$roomNumber} belum tersedia secara operasional.";
                                $replyMarkup = [
                                    "inline_keyboard" => [
                                        [["text" => "⬅️ Pilih Kamar Lain", "callback_data" => "sell_room_list"]]
                                    ]
                                ];
                                $alertText = "Kamar sudah terisi!";
                            } else {
                                try {
                                    $checkIn = date("Y-m-d");
                                    $checkOut = $checkIn;
                                    $ktpPhotoPath = null;
                                    if ($loggedInStaff && !empty($loggedInStaff['telegram_context'])) {
                                        $ctxParts = explode(":", $loggedInStaff['telegram_context']);
                                        if (count($ctxParts) >= 3) $ktpPhotoPath = $ctxParts[2];
                                    }
                                    $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                        'guestName' => str_replace('__COLON__', ':', $guestName),
                                        'roomNumber' => $roomNumber,
                                        'roomType' => $room['type'],
                                        'checkIn' => $checkIn,
                                        'checkOut' => $checkOut,
                                        'totalAmount' => 0,
                                        'paymentStatus' => 'unpaid',
                                        'bookingSource' => 'Direct',
                                            'lifecycleIntent' => 'check_in_now',
                                        'ktpPhoto' => str_replace('__COLON__', ':', $ktpPhotoPath ?? ''),
                                        'isOpenEnded' => 1,
                                        'downPaymentAmount' => $dpAmount,
                                        'downPaymentMethod' => $dpAmount > 0 ? $dpMethod : null,
                                        'downPaymentBankAccountId' => $dpBankAccountId,
                                        'downPaymentDate' => $checkIn,
                                        'paymentDate' => $checkIn
                                    ], 'telegram', $telegramCallbackOperationId . ':booking');
                                    $bookingId = (string)$bookingResult['bookingId'];

                                    // Clear staff telegram state/context
                                    $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                    $stmtClear->execute([$loggedInStaff['id']]);

                                    $replyText = "🎉 *PROSES SEWA DURASI TERBUKA BERHASIL*! 🎉\n\n" .
                                                 "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                                 "👤 Tamu: *{$guestName}*\n" .
                                                 "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                 "📅 Durasi: *Durasi Terbuka* (Tanpa tanggal checkout pasti)\n" .
                                                 "💵 Uang Panjar (DP): *Rp " . tamasyaTelegramFormatAmount($dpAmount) . "*\n" .
                                                 "💳 Metode DP: *" . $telegramPaymentMethodLabel((string)$dpMethod) . "*\n" .
                                                 "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                 "Data otomatis tersinkron ke dasbor hotel!";

                                    $replyMarkup = [
                                        "inline_keyboard" => [
                                            [["text" => "🛒 Jual Kamar Lainnya", "callback_data" => "sell_room_list"]],
                                            [["text" => "📊 Cek Status Kamar Live", "callback_data" => "room_list"]]
                                        ]
                                    ];
                                    $alertText = "Sewa Terbuka Kamar " . $roomNumber . " Berhasil!";

                                } catch (Exception $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $replyText = clientExceptionMessage("❌ Gagal memproses Walk-in Open Ended", $e);
                                    $alertText = "Error database!";
                                }
                            }
                        } else {
                            $replyText = "❌ Kamar tidak ditemukan!";
                        }
                    }
                } elseif ($callbackData === "cancel_booking_process") {
                    if ($loggedInStaff) {
                        $stmtClearState = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        $stmtClearState->execute([$loggedInStaff['id']]);
                    }
                    $replyText = "❌ *Proses sewa kamar dibatalkan.*";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "🛒 Jual Kamar", "callback_data" => "sell_room_list"]]
                        ]
                    ];
                    $alertText = "Dibatalkan";
                } elseif (strpos($callbackData, "r_sell_source_prompt:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    if ($loggedInStaff) {
                        // Ambil sumber saat ini jika ada
                        $bookingSource = 'Direct';
                        if (!empty($loggedInStaff['telegram_context'])) {
                            $ctxParts = explode(":", $loggedInStaff['telegram_context']);
                            if (count($ctxParts) >= 5 && !empty($ctxParts[4]) && !is_numeric($ctxParts[4])) {
                                $bookingSource = $ctxParts[4];
                            }
                        }
                        
                        $replyText = "🌐 *PILIH SUMBER PEMESANAN / BOOKING SOURCE*\n\n" .
                                     "Sumber saat ini: *{$bookingSource}*\n\n" .
                                     "Silakan pilih asal booking/pemesanan untuk Kamar *{$roomNumber}*:";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [
                                    ["text" => "Direct (Walk-in)", "callback_data" => "r_sell_source_set:" . $roomNumber . ":Direct"],
                                    ["text" => "Traveloka", "callback_data" => "r_sell_source_set:" . $roomNumber . ":Traveloka"]
                                ],
                                [
                                    ["text" => "Booking.com", "callback_data" => "r_sell_source_set:" . $roomNumber . ":Booking.com"],
                                    ["text" => "Tiket.com", "callback_data" => "r_sell_source_set:" . $roomNumber . ":Tiket.com"]
                                ],
                                [
                                    ["text" => "Agoda", "callback_data" => "r_sell_source_set:" . $roomNumber . ":Agoda"],
                                    ["text" => "Lainnya (Ketik Manual)...", "callback_data" => "r_sell_source_set:" . $roomNumber . ":custom"]
                                ],
                                [
                                    ["text" => "⬅️ Kembali ke Konfirmasi", "callback_data" => "r_sell_source_back:" . $roomNumber]
                                ]
                            ]
                        ];
                        $alertText = "Pilih Sumber Booking";
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                    }
                } elseif (strpos($callbackData, "r_sell_source_set:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $source = $parts[2];
                    
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                    } else {
                        $currentCtx = $loggedInStaff['telegram_context'] ?? '';
                        $ctxParts = explode(":", $currentCtx);
                        
                        if ($source === 'custom') {
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_booking_source_text' WHERE id = ?");
                            $stmtSetState->execute([$loggedInStaff['id']]);
                            
                            $replyText = "✍️ *SUMBER BOOKING KUSTOM*\n\n" .
                                         "Silakan **ketik nama sumber booking** secara manual (contoh: Pegipegi, Airbnb, dll.):";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                                ]
                            ];
                            $alertText = "Ketik Sumber Booking";
                        } else {
                            $roomNumberCtx = $ctxParts[0] ?? '';
                            $guestNameCtx = $ctxParts[1] ?? '';
                            $ktpPhotoPathCtx = $ctxParts[2] ?? '';
                            $nightsCtx = isset($ctxParts[3]) ? (int)$ctxParts[3] : 1;
                            
                            $customPrice = null;
                            $bankId = null;
                            $remainingParts = array_slice($ctxParts, 4);
                            $i = 0;
                            while ($i < count($remainingParts)) {
                                if ($remainingParts[$i] === 'splitbank') {
                                    $bankId = $remainingParts[$i+1] ?? null;
                                    $i += 2;
                                } elseif (is_numeric($remainingParts[$i])) {
                                    $customPrice = (float)$remainingParts[$i];
                                    $i++;
                                } else {
                                    $i++;
                                }
                            }
                            
                            $newCtxParts = [$roomNumberCtx, $guestNameCtx, $ktpPhotoPathCtx, $nightsCtx];
                            if ($customPrice !== null) {
                                $newCtxParts[] = $customPrice;
                            }
                            if ($bankId !== null) {
                                $newCtxParts[] = "splitbank";
                                $newCtxParts[] = $bankId;
                            }
                            $newCtxParts[] = $source;
                            $newCtx = implode(":", $newCtxParts);
                            
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_context = ? WHERE id = ?");
                            $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                            
                            // Ambil data staff terupdate dan render konfirmasi
                            $stmtStaff = $pdo->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
                            $stmtStaff->execute([$loggedInStaff['id']]);
                            $updatedStaff = $stmtStaff->fetch();
                            
                            $conf = renderBookingConfirmation($pdo, $updatedStaff);
                            $replyText = $conf['text'];
                            $replyMarkup = $conf['markup'];
                            $alertText = "Sumber Diubah ke " . $source;
                        }
                    }
                } elseif (strpos($callbackData, "r_sell_source_back:") === 0) {
                    if ($loggedInStaff) {
                        $conf = renderBookingConfirmation($pdo, $loggedInStaff);
                        $replyText = $conf['text'];
                        $replyMarkup = $conf['markup'];
                        $alertText = "Konfirmasi Booking";
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                    }
                } elseif (strpos($callbackData, "r_sell_nego_prompt:") === 0) {
                    if ($loggedInStaff) {
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_nego_price' WHERE id = ?");
                        $stmtSetState->execute([$loggedInStaff['id']]);
                        
                        $replyText = "✍️ *INPUT HARGA NEGO / TAWAR*\n\n" .
                                     "Silakan **ketik langsung nominal total harga nego yang dibayar tamu** untuk seluruh malam menginap. Pajak mengikuti aturan aktif di database:\n" .
                                     "*(Ketik angka saja tanpa titik/Rp, contoh: `150000`)*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                        $alertText = "Ketik Harga Nego";
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                    }
                } elseif (strpos($callbackData, "r_sell_walkin_ktp:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    $nights = (int)$parts[3];
                    $paymentStatus = $parts[4];
                    
                    if ($loggedInStaff) {
                        $newCtx = $roomNumber . ":" . $guestName . ":" . $nights . ":" . $paymentStatus;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_ktp', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                        
                        $replyText = "⚡ *WALK-IN PENJUALAN CEPAT - KAMAR {$roomNumber}*\n" .
                                     "👤 Tamu: *{$guestName}*\n" .
                                     "📅 Durasi: *{$nights} Malam* | Status: *" . $telegramPaymentStatusLabel((string)$paymentStatus) . "*\n\n" .
                                     "📸 *HARAP UPLOAD FOTO KTP TAMU*\n" .
                                     "Silakan kirimkan foto KTP langsung ke chat ini (sebagai Gambar/Foto)." .
                                     (!empty($GLOBALS['is_telegram_simulation']) ? "\n\n*Mode simulator aktif:* tombol simulasi hanya untuk pengujian internal." : "");
                        $ktpKeyboard = [];
                        if (!empty($GLOBALS['is_telegram_simulation'])) {
                            $ktpKeyboard[] = [["text" => "📸 Simulasi Upload KTP", "callback_data" => "simulate_ktp_upload"]];
                        }
                        $ktpKeyboard[] = [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]];
                        $replyMarkup = ["inline_keyboard" => $ktpKeyboard];
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login terlebih dahulu.";
                    }
                    $alertText = "KTP Diperlukan";
                } elseif ($callbackData === "simulate_ktp_upload") {
                    if (empty($GLOBALS['is_telegram_simulation'])) {
                        $replyText = "⛔ Simulasi upload KTP dinonaktifkan pada mode produksi. Kirim foto KTP asli sebagai JPEG/PNG/WebP.";
                        $alertText = "Simulasi dinonaktifkan";
                    } elseif (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } else {
                        $currentState = $loggedInStaff['telegram_state'] ?? '';
                        $currentCtx = $loggedInStaff['telegram_context'] ?? '';
                        
                        if ($currentState === 'waiting_for_ktp') {
                            $parts = explode(":", $currentCtx);
                            $ktpPhotoPath = '/uploads/ktp_simulated_placeholder.jpg';
                            
                            if (count($parts) >= 4 && $parts[1] === 'Walk-in') {
                                $roomNumber = $parts[0];
                                $guestName = $parts[1];
                                $nights = (int)$parts[2];
                                $paymentStatus = $parts[3];

                                if ($paymentStatus === 'paid') {
                                    $newCtx = $roomNumber . ":" . $guestName . ":" . str_replace(':', '__COLON__', $ktpPhotoPath) . ":" . $nights;
                                    $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_method', telegram_context = ? WHERE id = ?");
                                    $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                                    try {
                                        $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 ORDER BY name ASC");
                                        $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                    } catch (Throwable $e) {
                                        $bankAccounts = [];
                                    }

                                    $inlineKeyboard = [
                                        [
                                            ["text" => "💵 Tunai", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:cash:none"]
                                        ]
                                    ];

                                    foreach ($bankAccounts as $bank) {
                                        $icon = "🏦";
                                        if ($bank['type'] === 'edc_qris') {
                                            $icon = "📱";
                                        } elseif ($bank['type'] === 'edc_card') {
                                            $icon = "💳";
                                        }

                                        $inlineKeyboard[] = [
                                            ["text" => $icon . " " . $bank['name'], "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:" . ($bank['type'] === 'edc_qris' ? 'qris' : 'transfer') . ":" . $bank['id']]
                                        ];
                                    }

                                    $inlineKeyboard[] = [
                                        ["text" => "🌓 Split (Tunai & Transfer/QRIS)", "callback_data" => "r_sell_split_select:" . $roomNumber . ":" . $guestName . ":" . $nights]
                                    ];

                                    $inlineKeyboard[] = [
                                        ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                                    ];

                                    $replyText = "💳 *PILIH METODE PEMBAYARAN*\n\n" .
                                                 "🚪 Kamar: *{$roomNumber}*\n" .
                                                 "👤 Tamu: *{$guestName}*\n" .
                                                 "📅 Durasi: *{$nights} Malam*\n\n" .
                                                 "Silakan pilih metode pembayaran untuk transaksi Walk-in ini:";
                                    $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                                    $alertText = "Pilih Metode Pembayaran";
                                } else {
                                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                                    $stmtRoom->execute([$roomNumber]);
                                    $room = $stmtRoom->fetch();

                                    if ($room && tamasyaDeriveRoomOperationalStatus(getRoomOperationalBlockers($pdo,$roomNumber,false)) === 'available') {
                                        try {
                                            $checkIn = date("Y-m-d");
                                            $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                            $vatRate = resolveConfiguredTaxRate($pdo, 'Direct', 'room', 0, $checkIn);
                                            $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                                            $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                            $baseAmount = $inclusive['baseAmount'];
                                            $vatAmount = $inclusive['taxAmount'];
                                            $bookingSource = 'Direct';
                                            $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                                'guestName' => str_replace('__COLON__', ':', $guestName),
                                                'roomNumber' => $roomNumber,
                                                'roomType' => $room['type'],
                                                'checkIn' => $checkIn,
                                                'checkOut' => $checkOut,
                                                'totalAmount' => $totalAmount,
                                                'paymentStatus' => 'unpaid',
                                                'bookingSource' => $bookingSource,
                                                'lifecycleIntent' => 'check_in_now',
                                                'ktpPhoto' => str_replace('__COLON__', ':', $ktpPhotoPath ?? '')
                                            ], 'telegram', $telegramCallbackOperationId . ':booking');
                                            $bookingId = (string)$bookingResult['bookingId'];
                                            $vatRate = (float)$bookingResult['vatRate'];
                                            $vatAmount = (float)$bookingResult['vatAmount'];
                                            $totalAmount = (float)$bookingResult['totalAmount'];
                                            $baseAmount = max(0, $totalAmount - $vatAmount);

                                            $replyText = "🎉 *PROSES SEWA KAMAR WALK-IN BERHASIL (Simulasi KTP)*! 🎉\n\n" .
                                                         "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                                         "👤 Tamu: *{$guestName}*\n" .
                                                         "📸 KTP: *Terunggah (Simulasi)*\n" .
                                                         "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                         "📅 Tanggal Keluar: *{$checkOut}* (*{$nights} Malam*)\n" .
                                                         "💵 Tarif Kamar: *Rp " . tamasyaTelegramFormatAmount($baseAmount) . "*\n" .
                                                         "🧾 Pajak PBJT (" . $vatRate . "%): *Rp " . tamasyaTelegramFormatAmount($vatAmount) . "*\n" .
                                                         "💰 Total: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                                         "💳 Status: *UNPAID*\n" .
                                                         "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                         "Data otomatis tersinkron ke dasbor hotel!";

                                            $replyMarkup = [
                                                "inline_keyboard" => [
                                                    [["text" => "🛒 Jual Kamar Lainnya", "callback_data" => "sell_room_list"]],
                                                    [["text" => "📊 Cek Status Kamar Live", "callback_data" => "room_list"]]
                                                ]
                                            ];
                                        } catch (Exception $e) {
                                            if ($pdo->inTransaction()) $pdo->rollBack();
                                            $replyText = clientExceptionMessage("❌ Gagal memproses Walk-in", $e);
                                        }
                                    } else {
                                        $replyText = "⚠️ Gagal! Kamar {$roomNumber} sudah tidak tersedia.";
                                    }
                                }

                                $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                            } else {
                                $roomNumber = $parts[0];
                                $guestName = $parts[1];

                                $newCtx = $roomNumber . ":" . $guestName . ":" . str_replace(':', '__COLON__', $ktpPhotoPath);
                                $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_nights', telegram_context = ? WHERE id = ?");
                                $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                                $replyText = "👤 Nama Tamu: *{$guestName}*\n" .
                                             "🚪 Kamar: *{$roomNumber}*\n" .
                                             "📸 KTP: *Terunggah (Simulasi)*\n\n" .
                                             "Berapa malam tamu akan menginap?\n" .
                                             "*(Silakan ketik angka saja, contoh: `1` atau `2`)*";
                                $replyMarkup = [
                                    "inline_keyboard" => [
                                        [["text" => "🕒 Durasi Terbuka (Durasi Terbuka / Tanpa Checkout)", "callback_data" => "r_sell_open_ended_select:" . $roomNumber . ":" . $guestName]],
                                        [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                                    ]
                                ];
                            }
                        } else {
                            $replyText = "⚠️ State Anda tidak berada dalam posisi mengunggah KTP.";
                        }
                    }
                    $alertText = "Simulasi KTP Sukses";
                } elseif (strpos($callbackData, "r_sell_split_select:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    $nights = (int)$parts[3];
                    
                    try {
                        $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 ORDER BY name ASC");
                        $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (Throwable $e) {
                        $bankAccounts = [];
                    }
                    
                    $inlineKeyboard = [];
                    foreach ($bankAccounts as $bank) {
                        $icon = "🏦";
                        if ($bank['type'] === 'edc_qris') {
                            $icon = "📱";
                        } elseif ($bank['type'] === 'edc_card') {
                            $icon = "💳";
                        }
                        
                        $inlineKeyboard[] = [
                            ["text" => $icon . " " . $bank['name'], "callback_data" => "r_sell_split_bank:" . $roomNumber . ":" . $guestName . ":" . $nights . ":" . $bank['id']]
                        ];
                    }
                    
                    $inlineKeyboard[] = [
                        ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                    ];
                    
                    $replyText = "🌓 *SPLIT PAYMENT: PILIH AKUN TRANSFER/QRIS*\n\n" .
                                 "• Kamar: *{$roomNumber}*\n" .
                                 "• Tamu: *{$guestName}*\n" .
                                 "• Durasi: *{$nights} Malam*\n\n" .
                                 "Silakan pilih akun penampung untuk porsi *Transfer/QRIS*:";
                                 
                    $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                    $alertText = "Pilih Bank Split";
                } elseif (strpos($callbackData, "r_sell_split_bank:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    $nights = (int)$parts[3];
                    $bankId = $parts[4];
                    
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } else {
                        // Set state and context
                        $currentCtx = $loggedInStaff['telegram_context'] ?? '';
                        $newCtx = $currentCtx . ":splitbank:" . $bankId;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_split_cash', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                        
                        $replyText = "🌓 *PEMBAYARAN SPLIT*\n\n" .
                                     "• Kamar: *{$roomNumber}*\n" .
                                     "• Tamu: *{$guestName}*\n" .
                                     "• Durasi: *{$nights} Malam*\n\n" .
                                     "Masukkan nominal *TUNAI / CASH* yang dibayar tamu (contoh: `100000`):";
                                     
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                        $alertText = "Ketik Tunai";
                    }
                } elseif (strpos($callbackData, "r_sell_confirm:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $guestName = $parts[2];
                    $nights = (int)$parts[3];
                    $paymentStatus = $parts[4];
                    $paymentMethod = $parts[5] ?? null;
                    $bankAccountId = $parts[6] ?? null;
                    
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Akses ditolak!";
                    } else {
                        // If payment status is paid, but payment method has not been selected yet, ask for it!
                        if ($paymentStatus === 'paid' && !$paymentMethod) {
                            // Fetch active bank accounts
                            try {
                                $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 ORDER BY name ASC");
                                $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            } catch (Throwable $e) {
                                $bankAccounts = [];
                            }
                            
                            $inlineKeyboard = [
                                [
                                    ["text" => "💵 Tunai", "callback_data" => $callbackData . ":cash:none"]
                                ]
                            ];
                            
                            foreach ($bankAccounts as $bank) {
                                $icon = "🏦";
                                if ($bank['type'] === 'edc_qris') {
                                    $icon = "📱";
                                } elseif ($bank['type'] === 'edc_card') {
                                    $icon = "💳";
                                }
                                
                                $inlineKeyboard[] = [
                                    ["text" => $icon . " " . $bank['name'], "callback_data" => $callbackData . ":" . ($bank['type'] === 'edc_qris' ? 'qris' : 'transfer') . ":" . $bank['id']]
                                ];
                            }

                            $pendingBookingSource = 'Direct';
                            if (!empty($loggedInStaff['telegram_context'])) {
                                $pendingCtxParts = explode(":", (string)$loggedInStaff['telegram_context']);
                                $pendingRemaining = array_slice($pendingCtxParts, 4);
                                $pendingIndex = 0;
                                while ($pendingIndex < count($pendingRemaining)) {
                                    if ($pendingRemaining[$pendingIndex] === 'splitbank') $pendingIndex += 2;
                                    elseif (is_numeric($pendingRemaining[$pendingIndex])) $pendingIndex++;
                                    elseif (!empty($pendingRemaining[$pendingIndex])) { $pendingBookingSource = (string)$pendingRemaining[$pendingIndex]; $pendingIndex++; }
                                    else $pendingIndex++;
                                }
                            }
                            if ($guestName === 'Walk-in') $pendingBookingSource = 'Direct';
                            if (tamasyaIsExplicitOtaSourceLabel($pendingBookingSource)) {
                                $inlineKeyboard[] = [
                                    ["text" => "🌐 OTA (Online Travel Agent) / Piutang", "callback_data" => $callbackData . ":ota:ota_receivable"]
                                ];
                            }

                            $inlineKeyboard[] = [
                                ["text" => "🌓 Split (Tunai & Transfer/QRIS)", "callback_data" => "r_sell_split_select:" . $roomNumber . ":" . $guestName . ":" . $nights]
                            ];
                            
                            $inlineKeyboard[] = [
                                ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                            ];
                            
                            $replyText = "💳 *PILIH METODE PEMBAYARAN*\n\n" .
                                         "🚪 Kamar: *{$roomNumber}*\n" .
                                         "👤 Tamu: *{$guestName}*\n" .
                                         "📅 Durasi: *{$nights} Malam*\n\n" .
                                         "Silakan pilih metode pembayaran untuk transaksi lunas ini:";
                                         
                            $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                            $alertText = "Pilih Metode Pembayaran";
                        } else {
                            $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                            $stmtRoom->execute([$roomNumber]);
                            $room = $stmtRoom->fetch();
                            
                            if ($room) {
                                $roomBlockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
                                if (tamasyaDeriveRoomOperationalStatus($roomBlockers) !== 'available') {
                                    $replyText = $roomBlockers ? "⚠️ ".roomOperationalBlockerMessage($roomNumber,$roomBlockers) : "⚠️ Gagal! Kamar {$roomNumber} belum tersedia secara operasional.";
                                    $replyMarkup = [
                                        "inline_keyboard" => [
                                            [["text" => "⬅️ Pilih Kamar Lain", "callback_data" => "sell_room_list"]]
                                        ]
                                    ];
                                    $alertText = "Kamar sudah terisi!";
                                } else {
                                    try {
                                        $checkIn = date("Y-m-d");
                                        $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                        $ktpPhotoPath = null;
                                        $bookingSource = 'Direct';
                                        $customPrice = null;
                                        if ($loggedInStaff && !empty($loggedInStaff['telegram_context'])) {
                                            $ctxParts = explode(":", $loggedInStaff['telegram_context']);
                                            if (count($ctxParts) >= 3) $ktpPhotoPath = $ctxParts[2];
                                            $remainingParts = array_slice($ctxParts, 4);
                                            $i = 0;
                                            while ($i < count($remainingParts)) {
                                                if ($remainingParts[$i] === 'splitbank') $i += 2;
                                                elseif (is_numeric($remainingParts[$i])) { $customPrice = (float)$remainingParts[$i]; $i++; }
                                                elseif (!empty($remainingParts[$i])) { $bookingSource = $remainingParts[$i]; $i++; }
                                                else $i++;
                                            }
                                        }
                                        if ($guestName === 'Walk-in') $bookingSource = 'Direct';
                                        $finalBankId = ($bankAccountId !== null && $bankAccountId !== 'none' && $bankAccountId !== '') ? $bankAccountId : null;
                                        $finalPaymentMethod = $paymentMethod ?: null;
                                        $vatRate = resolveConfiguredTaxRate($pdo, $bookingSource, 'room', 0, $checkIn);
                                        if ($customPrice !== null && $customPrice > 0) {
                                            $totalAmount = $customPrice;
                                            $taxBreakdown = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                            $baseAmount = (float)$taxBreakdown['baseAmount'];
                                            $vatAmount = (float)$taxBreakdown['taxAmount'];
                                        } else {
                                            $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                                            $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                            $baseAmount = $inclusive['baseAmount'];
                                            $vatAmount = $inclusive['taxAmount'];
                                        }
                                        $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                            'guestName' => str_replace('__COLON__', ':', $guestName),
                                            'roomNumber' => $roomNumber,
                                            'roomType' => $room['type'],
                                            'checkIn' => $checkIn,
                                            'checkOut' => $checkOut,
                                            'totalAmount' => $totalAmount,
                                            'paymentStatus' => $paymentStatus,
                                            'paymentMethod' => $finalPaymentMethod,
                                            'bankAccountId' => $finalBankId,
                                            'paymentDate' => $checkIn,
                                            'bookingSource' => $bookingSource,
                                                'lifecycleIntent' => 'check_in_now',
                                            'ktpPhoto' => str_replace('__COLON__', ':', $ktpPhotoPath ?? '')
                                        ], 'telegram', $telegramCallbackOperationId . ':booking');
                                        $bookingId = (string)$bookingResult['bookingId'];
                                        $vatRate = (float)$bookingResult['vatRate'];
                                        $vatAmount = (float)$bookingResult['vatAmount'];
                                        $totalAmount = (float)$bookingResult['totalAmount'];
                                        $baseAmount = max(0, $totalAmount - $vatAmount);
                                        
                                        // Clear staff telegram state/context
                                        $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                        $stmtClear->execute([$loggedInStaff['id']]);
                                        
                                        $replyText = "🎉 *PROSES SEWA KAMAR BERHASIL*! 🎉\n\n" .
                                                     "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                                     "👤 Tamu: *{$guestName}*\n" .
                                                     "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                     "📅 Tanggal Keluar: *{$checkOut}* (*{$nights} Malam*)\n" .
                                                     "💵 Tarif Kamar: *Rp " . tamasyaTelegramFormatAmount($baseAmount) . "*\n" .
                                                     ($vatRate > 0
                                                        ? "🧾 Pajak PBJT ({$vatRate}%): *Rp " . tamasyaTelegramFormatAmount($vatAmount) . "*\n"
                                                        : "🧾 Pajak PBJT: *Rp 0* (sesuai tax_rules aktif)\n") .
                                                     "💰 Total: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                                     "💳 Status: *" . $telegramPaymentStatusLabel((string)$paymentStatus) . ($paymentMethod ? " (" . $telegramPaymentMethodLabel((string)$paymentMethod) . ")" : "") . "*\n" .
                                                     "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                     "Data otomatis tersinkron ke dasbor hotel!";
                                        
                                        $replyMarkup = [
                                            "inline_keyboard" => [
                                                [["text" => "🛒 Jual Kamar Lainnya", "callback_data" => "sell_room_list"]],
                                                [["text" => "📊 Cek Status Kamar Live", "callback_data" => "room_list"]]
                                            ]
                                        ];
                                        $alertText = "Penjualan Kamar " . $roomNumber . " Berhasil!";
                                        
                                    } catch (Exception $e) {
                                        if ($pdo->inTransaction()) $pdo->rollBack();
                                        $replyText = clientExceptionMessage("❌ Gagal memproses penjualan kamar", $e);
                                        $alertText = "Error database!";
                                    }
                                }
                            } else {
                                $replyText = "❌ Kamar tidak ditemukan!";
                            }
                        }
                    }
                } elseif (strpos($callbackData, "vacancy_report_room:") === 0) {
                    $roomNumber=trim((string)(explode(':',$callbackData,2)[1]??''));
                    $stmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmt->execute([$roomNumber]);
                    if(!$stmt->fetchColumn()){
                        $replyText="⚠️ Kamar {$roomNumber} tidak memiliki booking aktif. Laporan kosong tidak dibuat.";$alertText='Booking tidak aktif';
                    }else{
                        $replyText="🚪 *LAPORKAN KAMAR {$roomNumber} KOSONG*\n\nBagaimana kondisi kunci saat kamar ditemukan?";
                        $replyMarkup=['inline_keyboard'=>[
                            [['text'=>'🔑 Kunci ditemukan/dikembalikan','callback_data'=>'vacancy_key:'.$roomNumber.':returned']],
                            [['text'=>'⚠️ Kunci belum ditemukan','callback_data'=>'vacancy_key:'.$roomNumber.':missing']],
                            [['text'=>'❓ Belum diperiksa','callback_data'=>'vacancy_key:'.$roomNumber.':unknown']],
                            [['text'=>'❌ Batalkan','callback_data'=>'main_menu']]
                        ]];$alertText='Pilih kondisi kunci';
                    }
                } elseif (strpos($callbackData, "vacancy_key:") === 0) {
                    $parts=explode(':',$callbackData,3);$roomNumber=trim((string)($parts[1]??''));$key=trim((string)($parts[2]??'unknown'));
                    if(!in_array($key,['returned','missing','unknown'],true))$key='unknown';
                    $replyText="🔎 *KONDISI KAMAR {$roomNumber}*\n\nPilih temuan yang paling sesuai:";
                    $replyMarkup=['inline_keyboard'=>[
                        [['text'=>'✅ Kosong dan aman','callback_data'=>"vacancy_condition:{$roomNumber}:{$key}:clear"]],
                        [['text'=>'📦 Ada barang tertinggal','callback_data'=>"vacancy_condition:{$roomNumber}:{$key}:belongings"]],
                        [['text'=>'🛠 Ada kerusakan','callback_data'=>"vacancy_condition:{$roomNumber}:{$key}:damage"]],
                        [['text'=>'📦 + 🛠 Barang dan kerusakan','callback_data'=>"vacancy_condition:{$roomNumber}:{$key}:both"]]
                    ]];$alertText='Pilih kondisi kamar';
                } elseif (strpos($callbackData, "vacancy_condition:") === 0) {
                    $parts=explode(':',$callbackData,4);$roomNumber=trim((string)($parts[1]??''));$key=trim((string)($parts[2]??'unknown'));$condition=trim((string)($parts[3]??'clear'));
                    $belongings=in_array($condition,['belongings','both'],true)?'found':'none';
                    $damage=in_array($condition,['damage','both'],true)?'found':'none';
                    if($belongings==='found'){
                        $ctx=json_encode(['roomNumber'=>$roomNumber,'keyObservation'=>$key,'condition'=>$condition,'damageStatus'=>$damage,'operationId'=>$telegramCallbackOperationId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_vacancy_belongings_detail',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                        $replyText="📦 *DETAIL BARANG TERTINGGAL WAJIB*\n\n🚪 Kamar: *{$roomNumber}*\nKetik barang yang ditemukan secara bebas.\n\nContoh: `charger hitam`, `dompet cokelat`, atau `obat dalam pouch`.\n\nLaporan belum dibuat sampai detail barang valid.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'vacancy_menu']]]];
                        $alertText='Ketik detail barang';
                    }elseif($damage==='found'){
                        $ctx=json_encode(['roomNumber'=>$roomNumber,'keyObservation'=>$key,'condition'=>$condition,'belongingsStatus'=>'none','belongingsDetail'=>'','operationId'=>$telegramCallbackOperationId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_vacancy_damage_detail',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                        $replyText="🛠 *DETAIL KERUSAKAN WAJIB*\n\n🚪 Kamar: *{$roomNumber}*\nKetik apa yang rusak atau gejalanya secara bebas.\n\nContoh: `AC tidak dingin`, `engsel lemari oblak`, atau `sensor pintu kadang tidak merespon`.\n\nLaporan belum dibuat dan belum ada blocker kerusakan sampai detail valid dikirim.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'vacancy_menu']]]];
                        $alertText='Ketik detail kerusakan';
                    }else try{
                        $result=createOrRefreshRoomVacancyReport($pdo,$loggedInStaff,[
                            'roomNumber'=>$roomNumber,'operationId'=>$telegramCallbackOperationId,
                            'keyObservation'=>$key,'belongingsStatus'=>'none','belongingsDetail'=>'',
                            'damageStatus'=>'none','damageDetail'=>'','notes'=>'Kamar ditemukan kosong dan tidak ada temuan awal.','observedAt'=>date('c')
                        ],'telegram');
                        $report=$result['report'];
                        $isStale=!empty($result['stale']);$isDuplicate=!empty($result['duplicate']);
                        if(!$isStale&&!$isDuplicate)$postCallbackBroadcasts[]=["🚪 *KAMAR DILAPORKAN KOSONG*\n\nKamar: *{$roomNumber}*\nPelapor: *".currentStaffLabel($loggedInStaff)."*\nStatus: *MENUNGGU VERIFIKASI CHECKOUT*",false];
                        $replyText=$isStale?"ℹ️ *LAPORAN TERLAMBAT TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\nBooking yang diamati sudah tidak aktif; laporan tetap disimpan sebagai bukti historis.":"✅ *LAPORAN KAMAR KOSONG TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n📌 Status: *MENUNGGU VERIFIKASI*\n\nKamar belum dijual kembali dan belum dianggap bersih.";
                        $buttons=[];
                        if(!$isStale&&hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']))$buttons[]=[['text'=>'✅ Verifikasi & Lanjut Checkout','callback_data'=>'r_checkout:'.$roomNumber]];
                        $buttons[]=[['text'=>'📊 Cek Status Kamar','callback_data'=>'room_list']];
                        $replyMarkup=['inline_keyboard'=>$buttons];$alertText=$isStale?'Laporan historis tersimpan':($isDuplicate?'Laporan sudah ada':'Laporan tersimpan');
                    }catch(Throwable $vacancyError){$replyText='❌ '.clientExceptionMessage('Laporan kamar kosong gagal',$vacancyError);$alertText='Laporan ditolak';}
                } elseif (strpos($callbackData, "r_checkout:") === 0) {
                    $roomNumber=trim((string)(explode(':',$callbackData,2)[1]??''));
                    $stmtActive=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmtActive->execute([$roomNumber]);$booking=$stmtActive->fetch(PDO::FETCH_ASSOC);
                    if(!$booking){$replyText="❌ Sewa aktif untuk Kamar {$roomNumber} tidak ditemukan!";$alertText='Booking tidak ditemukan';}
                    else{
                        $accessContext=checkoutAccessSnapshot($pdo,$booking,false);
                        $reportStmt=$pdo->prepare("SELECT id FROM room_vacancy_reports WHERE booking_id=? AND status IN ('pending','verified') ORDER BY reported_at DESC LIMIT 1");$reportStmt->execute([(string)$booking['id']]);$vacancyReportId=$reportStmt->fetchColumn()?:null;
                        if(!empty($accessContext['physicalOutstanding'])){
                            $replyText="🔑 *STATUS KUNCI KAMAR {$roomNumber}*\n\nSebelum checkout final, pilih kondisi kunci fisik. Jika tamu pergi tanpa melapor dan kunci belum ditemukan, pilih opsi kedua lalu tulis alasannya.";
                            $replyMarkup=['inline_keyboard'=>[
                                [['text'=>'✅ Kunci dikembalikan/ditemukan','callback_data'=>'r_checkout_key:'.$roomNumber.':returned']],
                                [['text'=>'⚠️ Kunci belum kembali/hilang','callback_data'=>'r_checkout_key:'.$roomNumber.':missing']],
                                [['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]
                            ]];$alertText='Verifikasi kunci';
                        }else{
                            try{$step=prepareTelegramCheckoutFinancialStep($pdo,$loggedInStaff,$booking,'not_required','',$vacancyReportId);$replyText=$step['text'];$replyMarkup=$step['markup'];$alertText=$step['alert'];}
                            catch(Throwable $checkoutStepError){$replyText='⚠️ '.clientExceptionMessage('Checkout belum dapat dilanjutkan',$checkoutStepError);$alertText='Checkout ditolak';}
                        }
                    }
                } elseif (str_starts_with($callbackData,'r_checkout_nego:')) {
                    try{
                        $parts=explode(':',$callbackData);$ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_payment'||($ctx['roomNumber']??'')!==($parts[1]??'')||!hash_equals((string)($ctx['negotiationNonce']??''),$parts[2]??'')||($ctx['expiresAt']??0)<time())throw new RuntimeException('Pilihan nego kedaluwarsa. Buka ulang checkout.');
                        $q=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND status='active' LIMIT 1");$q->execute([$ctx['bookingId']]);$booking=$q->fetch(PDO::FETCH_ASSOC);
                        if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                        $quote=tamasyaBookingNegotiationQuote($pdo,$booking);
                        if($quote['roomBalance']<=0)throw new RuntimeException('Biaya kamar/perpanjangan sudah lunas; tidak ada biaya kamar yang bisa dinego.');
                        unset($quote['components'],$quote['paid']);
                        $draft=['flow'=>'checkout_negotiation','nonce'=>$parts[2],'expiresAt'=>time()+900,'quote'=>$quote,'checkout'=>$ctx];
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_nego_amount',telegram_context=? WHERE id=?")->execute([json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                         $replyText="✍️ *HARGA FINAL SEBELUM CHECKOUT*\n\nTotal tagihan lama: Rp ".tamasyaTelegramFormatAmount($quote['totalAmount'])."\nSudah dibayar (tetap): Rp ".tamasyaTelegramFormatAmount($quote['amountPaid'])."\nSisa kamar/perpanjangan: Rp ".tamasyaTelegramFormatAmount($quote['roomBalance'])."\nSisa layanan (tetap): Rp ".tamasyaTelegramFormatAmount($quote['extraBalance'])."\n\nKetik *uang yang harus dibayar tamu sekarang* untuk kamar/perpanjangan termasuk PBJT; total booking baru = angka ini + pembayaran lama yang tetap. Batas Rp ".tamasyaTelegramFormatAmount(max(0.0,round((float)$quote['minimumTotal']-(float)$quote['amountPaid'],2)))." sampai Rp ".tamasyaTelegramFormatAmount(max(0.0,round((float)$quote['totalAmount']-(float)$quote['amountPaid'],2))).". Pembayaran lama dan layanan tidak dipotong.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                    }catch(Throwable $e){$replyText=clientExceptionMessage('Harga nego ditolak',$e);}
                } elseif (str_starts_with($callbackData,'r_checkout_nego_save:')) {
                    try{
                        $nonce=substr($callbackData,strlen('r_checkout_nego_save:'));$ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(($loggedInStaff['telegram_state']??'')!=='waiting_for_checkout_nego_confirmation'||!is_array($ctx)||($ctx['flow']??'')!=='checkout_negotiation'||!hash_equals((string)($ctx['nonce']??''),$nonce)||($ctx['expiresAt']??0)<time())throw new RuntimeException('Konfirmasi nego kedaluwarsa. Buka ulang checkout.');
                        $payload=['finalTotal'=>$ctx['finalTotal'],'reason'=>$ctx['reason'],'quoteToken'=>$ctx['quote']['quoteToken']];
                        $result=tamasyaApplyBookingNegotiatedPrice($pdo,$loggedInStaff,$ctx['quote']['bookingId'],$payload,'telegram',telegramScopedOperationId('nego_'.$loggedInStaff['id'].'_'.$nonce,'checkout-price',$payload));
                        $c=$ctx['checkout'];$step=prepareTelegramCheckoutFinancialStep($pdo,$loggedInStaff,$result['booking'],$c['keyDisposition'],$c['keyReason'],isset($c['vacancyReportId'])?(string)$c['vacancyReportId']:null);
                        $replyText="✅ Harga nego disimpan dan diaudit. Belum ada penerimaan uang baru.\n\n".$step['text'];$replyMarkup=$step['markup'];
                    }catch(TelegramDuplicateOperationException $e){$replyText='ℹ️ Harga nego ini sudah disimpan. Buka checkout untuk melanjutkan pembayaran.';}
                    catch(Throwable $e){$replyText=clientExceptionMessage('Harga nego ditolak',$e);}
                } elseif (strpos($callbackData, "r_checkout_key:") === 0) {
                    $parts=explode(':',$callbackData,3);$roomNumber=trim((string)($parts[1]??''));$disposition=trim((string)($parts[2]??''));
                    $stmtActive=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmtActive->execute([$roomNumber]);$booking=$stmtActive->fetch(PDO::FETCH_ASSOC);
                    if(!$booking){$replyText='⚠️ Booking aktif tidak ditemukan.';$alertText='Booking berubah';}
                    else{
                        $reportStmt=$pdo->prepare("SELECT id FROM room_vacancy_reports WHERE booking_id=? AND status IN ('pending','verified') ORDER BY reported_at DESC LIMIT 1");$reportStmt->execute([(string)$booking['id']]);$vacancyReportId=$reportStmt->fetchColumn()?:null;
                        if($disposition==='missing'){
                            $ctx=json_encode(['flow'=>'checkout_missing_key','bookingId'=>(string)$booking['id'],'roomNumber'=>$roomNumber,'vacancyReportId'=>$vacancyReportId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_missing_key_reason',telegram_context=? WHERE id=?")->execute([$ctx,(string)$loggedInStaff['id']]);
                            $replyText="⚠️ *KUNCI BELUM KEMBALI / HILANG*\n\nKetik alasan singkat minimal 5 karakter, contoh: `Tamu pergi tanpa melapor dan kunci tidak ditemukan di kamar`.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];$alertText='Ketik alasan kunci';
                        }elseif($disposition==='returned'){
                            try{$step=prepareTelegramCheckoutFinancialStep($pdo,$loggedInStaff,$booking,'returned','Kunci dikembalikan atau ditemukan saat pemeriksaan kamar.',$vacancyReportId);$replyText=$step['text'];$replyMarkup=$step['markup'];$alertText=$step['alert'];}
                            catch(Throwable $checkoutKeyError){$replyText='⚠️ '.clientExceptionMessage('Verifikasi kunci ditolak',$checkoutKeyError);$alertText='Checkout ditolak';}
                        }else{$replyText='⚠️ Status kunci tidak valid.';$alertText='Data tidak valid';}
                    }
                } elseif (strpos($callbackData, "r_checkout_confirm:") === 0) {
                    $roomNumber = trim((string)(explode(":", $callbackData)[1] ?? ''));
                    try {
                        $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        $ctx=is_array($ctx)&&in_array(($ctx['flow']??''),['checkout_ready','checkout_payment'],true)&&(string)($ctx['roomNumber']??'')===$roomNumber?$ctx:[];
                        if(!$ctx)throw new RuntimeException('Konfirmasi checkout kedaluwarsa. Buka ulang checkout.');
                        $result = finalizeTelegramCheckout($pdo,$loggedInStaff,$roomNumber,$telegramCallbackOperationId,null,null,
                            (string)($ctx['keyDisposition']??'unknown'),(string)($ctx['keyReason']??''),isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null,'settle_now','',null,$ctx);
                        $booking = $result['booking'];
                        $tgMsg = getCheckOutTelegramMessage($booking,$booking['roomType'] ?? 'Standard',$loggedInStaff['name']);
                        $postCallbackBroadcasts[] = [$tgMsg,true];
                        $replyText = "✅ *CHECK-OUT BERHASIL DAN LEDGER SEIMBANG*\n\n" .
                                     "👤 Tamu: *" . tamasyaTelegramPlainText($booking['guestName'] ?? '-', 120) . "*\n" .
                                     "🚪 Kamar: *{$roomNumber}*\n" .
                                     "📅 Tanggal Keluar: *" . ($booking['checkOut'] ?? date('Y-m-d')) . "*\n" .
                                     "💳 Status: *PAID*\n\nKamar masuk status pemeliharaan dan laporan kas telah diperbarui dari transaksi server.";
                        $replyMarkup = ['inline_keyboard'=>[
                            [['text'=>'🧾 Cetak Nota / Invoice','callback_data'=>'print_receipt:'.($booking['id'] ?? '')]],
                            [['text'=>'🧹 Mulai Pembersihan','callback_data'=>'hk_set:'.$roomNumber.':cleaning']],
                            [['text'=>'📊 Cek Status Kamar','callback_data'=>'room_list']]
                        ]];
                        $alertText = !empty($result['duplicate']) ? 'Sudah diproses' : 'Checkout berhasil';
                    } catch (Throwable $checkoutError) {
                        $stmtActive = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");
                        $stmtActive->execute([$roomNumber]);
                        $activeBooking = $stmtActive->fetch(PDO::FETCH_ASSOC);
                        if ($activeBooking && empty($activeBooking['isOpenEnded'])) {
                            $ledger = bookingLedgerTotals($pdo,(string)$activeBooking['id']);
                            $remaining = max(0.0,round((float)$activeBooking['totalAmount']-$ledger['net'],2));
                            if ($remaining > 0) {
                                $replyMarkup = prepareTelegramCheckoutPaymentChoice(
                                    $pdo,$loggedInStaff,$activeBooking,$remaining,
                                    (string)($ctx['keyDisposition']??'unknown'),
                                    (string)($ctx['keyReason']??''),
                                    isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null
                                );
                            }
                        }
                        $replyText = "⚠️ " . clientExceptionMessage('Checkout belum dapat diselesaikan',$checkoutError);
                        $alertText = "Checkout ditolak";
                    }
                } elseif (strpos($callbackData, "r_checkout_defer:") === 0) {
                    $roomNumber=trim((string)(explode(':',$callbackData,2)[1]??''));
                    try{
                        $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_payment'||(string)($ctx['roomNumber']??'')!==$roomNumber||empty($ctx['vacancyReportId'])){
                            throw new RuntimeException('Pilihan checkout operasional sudah kedaluwarsa atau tidak memiliki laporan kamar kosong.');
                        }
                        $result=finalizeTelegramCheckout($pdo,$loggedInStaff,$roomNumber,$telegramCallbackOperationId,null,null,
                            (string)($ctx['keyDisposition']??'unknown'),(string)($ctx['keyReason']??''),(string)$ctx['vacancyReportId'],'defer',
                            'Tamu ditemukan sudah meninggalkan kamar; sisa tagihan dicatat sebagai piutang dan ditindaklanjuti shift aktif.');
                        $booking=$result['booking'];$balance=(float)($result['financialClosure']['balance']??$booking['financialClosureBalance']??0);
                        $replyText="✅ *CHECKOUT OPERASIONAL BERHASIL*\n\n🚪 Kamar: *{$roomNumber}*\n👤 Tamu: *".tamasyaTelegramPlainText((string)($booking['guestName']??'-'),120)."*\n📒 Piutang tersisa: *Rp ".tamasyaTelegramFormatAmount($balance)."*\n🛡 Deposito: *DITAHAN MENUNGGU INSPEKSI*\n\nKamar sudah dilepas dari okupansi dan masuk housekeeping. Tidak ada uang yang bergerak pada tindakan ini; pelunasan/piutang dan deposito diselesaikan melalui shift aktif.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'🧹 Mulai Pembersihan','callback_data'=>'hk_set:'.$roomNumber.':cleaning']],[['text'=>'📊 Cek Status Kamar','callback_data'=>'room_list']]]];
                        $alertText=!empty($result['duplicate'])?'Sudah diproses':'Checkout operasional selesai';
                    }catch(Throwable $deferError){$replyText='❌ '.clientExceptionMessage('Checkout operasional gagal',$deferError);$alertText='Checkout ditolak';}
                } elseif (strpos($callbackData, "r_checkout_split:") === 0) {
                    $roomNumber=trim((string)(explode(':',$callbackData,2)[1]??''));
                    try{
                        $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_payment'||(string)($ctx['roomNumber']??'')!==$roomNumber){
                            throw new RuntimeException('Pilihan split sudah kedaluwarsa. Buka kembali menu checkout.');
                        }
                        $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND roomNumber=? AND status='active' LIMIT 1");
                        $stmt->execute([(string)($ctx['bookingId']??''),$roomNumber]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
                        if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                        $ledger=bookingLedgerTotals($pdo,(string)$booking['id']);
                        $remaining=max(0.0,round((float)$booking['totalAmount']-(float)$ledger['net'],2));
                        if($remaining<=0.01)throw new RuntimeException('Booking sudah lunas; split tidak diperlukan.');
                        $ctx['remaining']=$remaining;
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_split_cash',telegram_context=? WHERE id=?")
                            ->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $replyText="🌓 *SPLIT CHECKOUT*

Sisa tagihan: *Rp ".tamasyaTelegramFormatAmount($remaining)."*

Ketik bagian *Tunai* saja. Contoh: `40000`. Sisa otomatis menjadi Transfer/QRIS.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];$alertText='Masukkan bagian tunai';
                    }catch(Throwable $splitStartError){$replyText='⚠️ '.clientExceptionMessage('Split checkout belum dapat dimulai',$splitStartError);$alertText='Split ditolak';}
                } elseif (strpos($callbackData, "r_checkout_split_bank:") === 0) {
                    $parts=explode(':',$callbackData,3);$roomNumber=trim((string)($parts[1]??''));$key=trim((string)($parts[2]??''));
                    try{
                        $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_split_bank'||(string)($ctx['roomNumber']??'')!==$roomNumber){
                            throw new RuntimeException('Pilihan akun split sudah kedaluwarsa. Buka kembali menu checkout.');
                        }
                        $mapped=$ctx['bankMap'][$key]??null;
                        if(!is_array($mapped)||empty($mapped['id']))throw new InvalidArgumentException('Akun split tidak valid.');
                        $bankId=(string)$mapped['id'];
                        $bankMethod=tamasyaInferPaymentMethodFromAccount($pdo,$bankId,false,['transfer','qris']);
                        $splitCash=round((float)($ctx['splitCashAmount']??0),2);$splitTransfer=round((float)($ctx['splitTransferAmount']??0),2);
                        $result=finalizeTelegramCheckout(
                            $pdo,$loggedInStaff,$roomNumber,$telegramCallbackOperationId,'split',null,
                            (string)($ctx['keyDisposition']??'unknown'),(string)($ctx['keyReason']??''),isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null,
                            'settle_now','',[
                                'isSplitPayment'=>1,'splitCashAmount'=>$splitCash,'splitTransferAmount'=>$splitTransfer,'splitTransferBankAccountId'=>$bankId
                            ],$ctx
                        );
                        $booking=$result['booking'];$tgMsg=getCheckOutTelegramMessage($booking,$booking['roomType']??'Standard',$loggedInStaff['name']);$postCallbackBroadcasts[]=[$tgMsg,true];
                        $replyText="✅ *SPLIT DAN CHECK-OUT BERHASIL*

🚪 Kamar: *{$roomNumber}*
💵 Tunai: *Rp ".tamasyaTelegramFormatAmount($splitCash)."*
".
                                   "💳 ".strtoupper($bankMethod).": *Rp ".tamasyaTelegramFormatAmount($splitTransfer)."*
📒 Ledger: *SEIMBANG / PAID*";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'🧾 Cetak Nota / Invoice','callback_data'=>'print_receipt:'.($booking['id']??'')]],[['text'=>'🧹 Mulai Pembersihan','callback_data'=>'hk_set:'.$roomNumber.':cleaning']]]];
                        $alertText=!empty($result['duplicate'])?'Sudah diproses':'Split checkout berhasil';
                    }catch(Throwable $splitFinalError){$replyText='❌ '.clientExceptionMessage('Split checkout gagal',$splitFinalError);$alertText='Split ditolak';}
                } elseif (strpos($callbackData, "r_checkout_pay:") === 0) {
                    $parts = explode(':',$callbackData,5);
                    $roomNumber = trim((string)($parts[1] ?? ''));
                    $mode = strtolower(trim((string)($parts[2] ?? '')));
                    $key = trim((string)($parts[3] ?? '-'));
                    $method = null;
                    $bankId = null;
                    try {
                        $ctx = json_decode((string)($loggedInStaff['telegram_context'] ?? ''),true);
                        if (!is_array($ctx) || ($ctx['flow'] ?? '') !== 'checkout_payment' || (string)($ctx['roomNumber'] ?? '') !== $roomNumber) {
                            throw new RuntimeException('Pilihan pembayaran sudah kedaluwarsa. Buka kembali menu checkout.');
                        }
                        if ($mode === 'cash') {
                            $method = 'cash';
                        } elseif ($mode === 'ota') {
                            $method = 'ota';
                            $bankId = 'ota_receivable';
                        } elseif ($mode === 'bank') {
                            $mapped = $ctx['bankMap'][$key] ?? null;
                            if (!is_array($mapped) || empty($mapped['id'])) throw new InvalidArgumentException('Akun pembayaran tidak valid.');
                            $method = in_array(($mapped['method'] ?? ''),['transfer','qris'],true) ? $mapped['method'] : 'transfer';
                            $bankId = (string)$mapped['id'];
                        } else {
                            throw new InvalidArgumentException('Metode pembayaran tidak valid.');
                        }
                        $result = finalizeTelegramCheckout($pdo,$loggedInStaff,$roomNumber,$telegramCallbackOperationId,$method,$bankId,(string)($ctx['keyDisposition']??'unknown'),(string)($ctx['keyReason']??''),isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null,'settle_now','',null,$ctx);
                        $booking = $result['booking'];
                        $tgMsg = getCheckOutTelegramMessage($booking,$booking['roomType'] ?? 'Standard',$loggedInStaff['name']);
                        $postCallbackBroadcasts[] = [$tgMsg,true];
                        $replyText = "✅ *PELUNASAN DAN CHECK-OUT BERHASIL*\n\n" .
                                     "🚪 Kamar: *{$roomNumber}*\n" .
                                     "💵 Pelunasan tercatat: *Rp " . tamasyaTelegramFormatAmount((float)($result['remainingPaid'] ?? 0)) . "*\n" .
                                     "💳 Metode: *" . strtoupper((string)$method) . "*\n" .
                                     "📒 Status ledger: *SEIMBANG / PAID*\n\nKamar dialihkan ke pemeliharaan.";
                        $replyMarkup = ['inline_keyboard'=>[
                            [['text'=>'🧾 Cetak Nota / Invoice','callback_data'=>'print_receipt:'.($booking['id'] ?? '')]],
                            [['text'=>'🧹 Mulai Pembersihan','callback_data'=>'hk_set:'.$roomNumber.':cleaning']]
                        ]];
                        $alertText = !empty($result['duplicate']) ? 'Sudah diproses' : 'Lunas dan checkout';
                    } catch (Throwable $checkoutPaymentError) {
                        $replyText = "❌ " . clientExceptionMessage('Pelunasan checkout gagal',$checkoutPaymentError);
                        $alertText = "Pembayaran ditolak";
                    }
                } elseif (strpos($callbackData, "print_receipt:") === 0) {
                    $bookingId = explode(":", $callbackData)[1];
                    $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                    $stmtBooking->execute([$bookingId]);
                    $booking = $stmtBooking->fetch();

                    if (!$booking) {
                        $replyText = "⚠️ Data sewa tidak ditemukan!";
                        $alertText = "Error!";
                    } else {
                        $replyText = generateBotThermalReceipt($booking, $loggedInStaff ? $loggedInStaff['name'] : 'System');
                        $alertText = "Nota berhasil dicetak!";
                        if ($loggedInStaff && (hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']) || hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']))) {
                            $replyMarkup = ['inline_keyboard'=>[[['text'=>'✉️ Kirim nota ke email','callback_data'=>'email_receipt:'.$bookingId]]]];
                        }
                    }
                } elseif (str_starts_with($callbackData, "email_receipt:")) {
                    $bookingId = explode(":", $callbackData)[1] ?? '';
                    $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                    $stmtBooking->execute([$bookingId]);
                    $booking = $stmtBooking->fetch();
                    if (!$booking) {
                        $replyText = "⚠️ Data sewa tidak ditemukan!";
                        $alertText = "Error!";
                    } else {
                        $storedEmail = trim((string)($booking['guestEmail'] ?? ''));
                        $keyboard = [];
                        if ($storedEmail !== '' && filter_var($storedEmail, FILTER_VALIDATE_EMAIL)) {
                            $keyboard[] = [['text'=>'✉️ Kirim ke '.$storedEmail,'callback_data'=>'email_receipt_send:'.$bookingId]];
                        }
                        $keyboard[] = [['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']];
                        $replyText = "✉️ *KIRIM NOTA KAMAR ".tamasyaTelegramPlainText((string)$booking['roomNumber'],20)." KE EMAIL*\n\n" .
                            "Tamu: *".tamasyaTelegramPlainText((string)$booking['guestName'],120)."*\n" .
                            ($storedEmail !== '' ? "Email tersimpan: `".$storedEmail."`\n\nTekan tombol kirim, atau ketik alamat email lain." : "Ketik alamat email tujuan.");
                        $replyMarkup = ['inline_keyboard'=>$keyboard];
                        if ($loggedInStaff) {
                            $ctx = ['flow'=>'receipt-email','expiresAt'=>time()+900,'mode'=>'guest','bookingId'=>$bookingId];
                            $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_receipt_email', telegram_context = ? WHERE id = ?")->execute([tamasyaJsonEncode($ctx), $loggedInStaff['id']]);
                        }
                        $alertText = "Siap kirim nota";
                    }
                } elseif (str_starts_with($callbackData, "email_receipt_send:")) {
                    try {
                        $bookingId = explode(":", $callbackData)[1] ?? '';
                        $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                        $stmtBooking->execute([$bookingId]);
                        $booking = $stmtBooking->fetch();
                        if (!$booking) throw new InvalidArgumentException('Data sewa tidak ditemukan.');
                        $target = trim((string)($booking['guestEmail'] ?? ''));
                        if ($target === '' || !filter_var($target, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email tamu belum tersimpan. Ketik alamat email tujuan terlebih dahulu.');
                        $result = tamasyaSendBookingReceiptEmail($pdo, $loggedInStaff, $booking, $target, 'telegram', $telegramCallbackOperationId);
                        $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?")->execute([$loggedInStaff['id']]);
                        $replyText = "✅ *NOTA TERKIRIM*\n\nNota ".$result['invoiceNumber']." sudah dikirim ke ".$result['masked']." (via ".$result['method'].").\n".($result['deliveryNote']??'')."\nJika tamu belum menerima, minta cek folder spam atau periksa konfigurasi email hotel.";
                        $replyMarkup = ['inline_keyboard'=>[[['text'=>'🧾 Cetak Nota Thermal','callback_data'=>'print_receipt:'.$bookingId]]]];
                        $alertText = "Nota terkirim";
                    } catch (Throwable $emailError) {
                        $replyText = "❌ " . clientExceptionMessage('Nota belum terkirim', $emailError) . "\n\nKetik alamat email lain, atau tekan Batalkan.";
                        $alertText = "Gagal mengirim";
                    }
                } elseif ($callbackData === "pemasukan_menu" || str_starts_with($callbackData,"pemasukan_menu:p:")) {
                    if(!$loggedInStaff || !in_array($loggedInStaff['role'],['admin','manager','finance'],true)){$replyText="🔒 Akses Ditolak! Menu ini hanya untuk Admin/Manajer/Finance.";$alertText="Akses ditolak!";}
                    else{
                        $page=str_starts_with($callbackData,"pemasukan_menu:p:")?max(1,(int)substr($callbackData,strlen("pemasukan_menu:p:"))):1;$pageSize=30;
                        $count=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();$totalPages=max(1,(int)ceil(max(1,$count)/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;
                        $stmt=$pdo->prepare("SELECT number FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC,number ASC LIMIT ? OFFSET ?");$stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();$rooms=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                        $buttons=[];$row=[];foreach($rooms as $room){$row[]=['text'=>'🚪 Kamar '.$room['number'],'callback_data'=>'p_room:'.$room['number']];if(count($row)>=3){$buttons[]=$row;$row=[];}}if($row)$buttons[]=$row;
                        if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'pemasukan_menu:p:');$buttons[]=[['text'=>'⬅️ Kas & Shift','callback_data'=>'cash_shift_menu']];
                        $rangeStart=$count?($offset+1):0;$rangeEnd=min($offset+$pageSize,$count);
                        $replyText="📥 *INPUT PEMASUKAN CEPAT*

Pilih kamar yang menerima transaksi keuangan.
📄 Halaman *{$page}/{$totalPages}* · kamar *{$rangeStart}-{$rangeEnd}* dari *{$count}*.";$replyMarkup=['inline_keyboard'=>$buttons];$alertText='Pilih Kamar';
                    }
                } elseif (str_starts_with($callbackData,'r_charge_method:') || str_starts_with($callbackData,'r_charge_account:') || str_starts_with($callbackData,'r_charge_save:')) {
                    try{
                        $selection=tamasyaTelegramChargeHandle($pdo,$loggedInStaff,$callbackData);
                        $replyText=$selection['text'];$replyMarkup=$selection['markup'];$alertText='Pembayaran biaya tambahan';
                        if(isset($selection['broadcast']))$postCallbackBroadcasts[]=$selection['broadcast'];
                    }catch(TelegramDuplicateOperationException $e){$replyText='ℹ️ Biaya dan pembayaran ini sudah diproses; tidak dibuat ganda.';$alertText='Sudah diproses';}
                    catch(Throwable $e){$replyText=clientExceptionMessage('❌ Pembayaran biaya tambahan ditolak',$e);$alertText='Belum tersimpan';}
                } elseif (strpos($callbackData, "r_extend:") === 0) {
                    try {
                        $roomNumber = explode(":", $callbackData)[1];
                        $stmtActive = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                        $stmtActive->execute([$roomNumber]);
                        $booking = $stmtActive->fetch();

                        if (!$booking) {
                            $replyText = "⚠️ Kamar nomor {$roomNumber} tidak sedang disewa (aktif)!";
                            $alertText = "Error";
                        } else {
                            if ($loggedInStaff) {
                                $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_extend_nights', telegram_context = ? WHERE id = ?");
                                $stmtSetState->execute([$roomNumber, $loggedInStaff['id']]);
                            }
                            $replyText = "⏳ *PERPANJANG SEWA KAMAR {$roomNumber}*\n" .
                                         "👤 Tamu: *" . tamasyaTelegramPlainText($booking['guestName'],120) . "*\n\n" .
                                         "Berapa malam masa sewa kamar ini ingin diperpanjang?\n" .
                                         "*(Ketik angka saja, contoh: `1` atau `2`)*";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                                ]
                            ];
                            $alertText = "Perpanjang " . $roomNumber;
                        }
                    } catch (Throwable $e) {
                        // Return detailed error in simulation mode for debugging
                        $errMsg = 'Perpanjangan ditolak: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                        $replyText = !empty($GLOBALS['is_telegram_simulation']) ? $errMsg : clientExceptionMessage('Perpanjangan ditolak', $e);
                        $alertText = 'Error';
                    }
                } elseif (strpos($callbackData, 'r_extend_nego:') === 0) {
                    try{
                        $ctx=tamasyaTelegramExtensionContext($loggedInStaff,substr($callbackData,strlen('r_extend_nego:')));
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_extend_nego_amount' WHERE id=?")->execute([$loggedInStaff['id']]);
                        $replyText='✍️ Ketik total harga nego untuk '.$ctx['nights'].' malam tambahan, termasuk PBJT. Contoh: `200000`. Ini hanya biaya perpanjangan, bukan seluruh booking.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                    }catch(Throwable $e){$replyText=clientExceptionMessage('Perpanjangan ditolak',$e);}
                } elseif (strpos($callbackData, 'r_extend_choice:') === 0) {
                    try{
                        $parts=explode(':',$callbackData);$ctx=tamasyaTelegramExtensionContext($loggedInStaff,$parts[1]??'');
                        $result=tamasyaTelegramExtensionSubmit($pdo,$loggedInStaff,$ctx,$parts[2]??'',$telegramCallbackOperationId);$replyText=$result['text'];$replyMarkup=$result['markup'];
                        if(isset($result['broadcast']))$postCallbackBroadcasts[]=$result['broadcast'];
                    }catch(TelegramDuplicateOperationException $e){$replyText='ℹ️ Perpanjangan ini sudah diproses. Tidak ada tagihan/pembayaran ganda.';}
                    catch(Throwable $e){$replyText=clientExceptionMessage('Perpanjangan ditolak',$e);}
                } elseif (strpos($callbackData, "r_extend_confirm:") === 0) {
                    $replyText='⚠️ Tombol perpanjangan lama tidak berlaku. Buka kembali Perpanjang Kamar agar harga dan pembayaran sesuai.';
                } elseif (strpos($callbackData, "r_layanan:") === 0) {
                    try {
                        $roomNumber = explode(":", $callbackData)[1];
                        $stmtActive = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                        $stmtActive->execute([$roomNumber]);
                        $booking = $stmtActive->fetch();

                        if (!$booking) {
                            $replyText = "⚠️ Kamar nomor {$roomNumber} tidak sedang disewa (aktif)!";
                            $alertText = "Error";
                        } else {
                            if ($loggedInStaff) {
                                $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_type', telegram_context = ? WHERE id = ?");
                                $stmtSetState->execute([$roomNumber, $loggedInStaff['id']]);
                            }
                            $replyText = "🛠 *PILIH JENIS LAYANAN UNTUK KAMAR {$roomNumber}*\n" .
                                         "👤 Tamu: *" . tamasyaTelegramPlainText($booking['guestName'],120) . "*\n\n" .
                                         "Daftar layanan dibaca langsung dari Master Data property:";
                            $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',false);
                            $stmtExtraSubs=$pdo->prepare("SELECT id,name FROM subcategories WHERE category_id=? AND is_active=1 ORDER BY name,id");
                            $stmtExtraSubs->execute([(string)$extraRoot['id']]);$extraSubs=$stmtExtraSubs->fetchAll(PDO::FETCH_ASSOC)?:[];
                            $serviceButtons=[];$serviceRow=[];
                            foreach($extraSubs as $service){
                                $serviceRow[]=['text'=>'🛎️ '.(string)$service['name'],'callback_data'=>'r_layanan_type:'.$roomNumber.':'.(string)$service['id']];
                                if(count($serviceRow)>=2){$serviceButtons[]=$serviceRow;$serviceRow=[];}
                            }
                            if($serviceRow)$serviceButtons[]=$serviceRow;
                            $serviceButtons[]=[['text'=>'✍️ Layanan Kustom','callback_data'=>'r_layanan_type:'.$roomNumber.':__custom__']];
                            $serviceButtons[]=[["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]];
                            $replyMarkup = ["inline_keyboard" => $serviceButtons];
                            $alertText = "Layanan " . $roomNumber;
                        }
                    } catch (Throwable $e) {
                        $replyText = clientExceptionMessage('Layanan ditolak', $e);
                        $alertText = 'Error';
                    }
                } elseif (strpos($callbackData, "r_layanan_type:") === 0) {
                    $parts = explode(":", $callbackData,3);
                    $roomNumber = $parts[1]??'';
                    $serviceToken = $parts[2]??'';
                    $layananName = '';
                    if($serviceToken!=='__custom__'){
                        $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',false);
                        $stmtService=$pdo->prepare("SELECT name FROM subcategories WHERE id=? AND category_id=? AND is_active=1 LIMIT 1");
                        $stmtService->execute([$serviceToken,(string)$extraRoot['id']]);
                        $layananName=(string)($stmtService->fetchColumn()?:'');
                        if($layananName===''){$replyText='⚠️ Layanan tidak ditemukan/aktif di Master Data.';$alertText='Layanan tidak valid';}
                    }

                    if ($serviceToken === "__custom__") {
                        if ($loggedInStaff) {
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_custom_name', telegram_context = ? WHERE id = ?");
                            $stmtSetState->execute([$roomNumber, $loggedInStaff['id']]);
                        }
                        $replyText = "⚙️ *NAMA LAYANAN KUSTOM*\n\n" .
                                     "Silakan **ketik nama layanan tambahan kustom** Anda langsung di chat ini (tanpa perintah / command):\n\n" .
                                     "*Contoh*: 'Sewa Setrika' atau 'Extra Bantal'";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                        $alertText = "Ketik Nama Layanan";
                    } elseif($layananName!=='') {
                        $newCtx = $roomNumber . ":" . rawurlencode($layananName);

                        if ($loggedInStaff) {
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_custom_price', telegram_context = ? WHERE id = ?");
                            $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                        }

                        $replyText = "💰 *INPUT HARGA LAYANAN*\n\n" .
                                     "Layanan: *{$layananName}*\n" .
                                     "Silakan **ketik harga satuan** untuk layanan ini:\n" .
                                     "*(Ketik angka saja, contoh: `100000` atau `50000`)*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                        $alertText = "Ketik Harga Layanan";
                    }
                } elseif (strpos($callbackData, "r_layanan_confirm:") === 0) {
                    $parts=explode(":",$callbackData);
                    $roomNumber=$parts[1]??'';$name=rawurldecode($parts[2]??'');$price=is_numeric($parts[3]??null)?round((float)$parts[3],2):-1;$qty=preg_match('/^[1-9][0-9]*$/',(string)($parts[4]??''))?(int)$parts[4]:0;
                    $paymentStatus=strtolower((string)($parts[5]??'unpaid'));
                    $stmtActive=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmtActive->execute([$roomNumber]);$booking=$stmtActive->fetch(PDO::FETCH_ASSOC);
                    if($booking && $loggedInStaff){
                        try{
                            $baseCost=round($price*$qty,2);$bookingSource=trim((string)($booking['bookingSource']??'Direct'))?:'Direct';
                            $rate=resolveConfiguredTaxRate($pdo,$bookingSource,'extra',null,date('Y-m-d'));$taxAmount=round($baseCost*($rate/100),2);$gross=round($baseCost+$taxAmount,2);
                            $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',false);
                            $category=(string)$extraRoot['name'];$subcategory=null;
                            $stmtExtraSub=$pdo->prepare("SELECT name FROM subcategories WHERE category_id=? AND is_active=1 AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1");
                            $stmtExtraSub->execute([(string)$extraRoot['id'],$name]);$matchedExtraSub=$stmtExtraSub->fetchColumn();
                            if($matchedExtraSub!==false)$subcategory=(string)$matchedExtraSub;
                            if($paymentStatus==='paid'){
                                $selection=tamasyaTelegramChargeBegin($pdo,$loggedInStaff,$booking,['action'=>'extra','serviceName'=>$name,'unitPrice'=>$price,'qty'=>$qty,'category'=>$category,'subcategory'=>$subcategory,'amount'=>$gross,'quotedBaseAmount'=>$baseCost],$telegramCallbackOperationId);
                                $replyText=$selection['text'];$replyMarkup=$selection['markup'];$alertText='Pilih metode pembayaran';
                            }else{
                            $operation=telegramScopedOperationId($telegramCallbackOperationId,'service-confirm',[(string)$booking['id'],$roomNumber,$name,$price,$qty,$paymentStatus]);
                            $charge=applyCanonicalTelegramBookingChargeWorkflow($pdo,$loggedInStaff,[
                                'action'=>'extra','bookingId'=>(string)$booking['id'],'roomNumber'=>$roomNumber,'serviceName'=>$name,
                                'unitPrice'=>$price,'qty'=>$qty,'amount'=>$gross,'category'=>$category,'subcategory'=>$subcategory,'paymentStatus'=>$paymentStatus
                            ],$operation);
                            $after=$charge['booking'];
                            $tgMsg="🛠️ *TAMBAHAN LAYANAN KAMAR* (Bot Telegram)\n\n🚪 *Kamar*: {$roomNumber}\n👤 *Tamu*: *" . tamasyaTelegramPlainText((string)($after['guestName']??'-'),120) . "*\n" .
                                "🛠️ *Layanan*: {$name} (x{$qty})\n💰 *Total tagihan*: Rp ".tamasyaTelegramFormatAmount($gross)."\n" .
                                "💳 *Status Pembayaran*: ".($paymentStatus==='paid'?"✅ LUNAS":"⏳ BELUM LUNAS")."\n👤 *Oleh*: ".($loggedInStaff['name']??$loggedInStaff['username']);
                            $postCallbackBroadcasts[] = [$tgMsg,false];
                            $replyText="🎉 *TAMBAHAN LAYANAN BERHASIL*! 🎉\n\n🔑 Kamar: *{$roomNumber}*\n👤 Tamu: *" . tamasyaTelegramPlainText((string)($after['guestName']??'-'),120) . "*\n🛠️ Layanan: *{$name}* (x{$qty})\n" .
                                "💰 Total Tambahan: *Rp ".tamasyaTelegramFormatAmount($gross)."*\n💳 Status: *".$telegramPaymentStatusLabel($paymentStatus)."*\n\nData diproses melalui workflow booking canonical.";
                            $alertText='Layanan sukses!';
                        }
                        }catch(TelegramDuplicateOperationException $e){$replyText='ℹ️ Operasi layanan ini sudah diproses sebelumnya. Data tidak digandakan.';$alertText='Sudah diproses';}
                        catch(Throwable $e){$replyText=clientExceptionMessage('❌ Gagal memproses',$e);$alertText='Gagal memproses';}
                    }else{$replyText='❌ Booking aktif tidak ditemukan!';$alertText='Error';}
                    if($loggedInStaff && $paymentStatus!=='paid')$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);

                } elseif (strpos($callbackData, "r_transfer:") === 0) {
                    try {
                        $parts=explode(":",$callbackData);
                        $roomNumber=trim((string)($parts[1]??''));
                        $page=(isset($parts[2])&&$parts[2]==='p')?max(1,(int)($parts[3]??1)):1;
                        $stmtActive=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");
                        $stmtActive->execute([$roomNumber]);$booking=$stmtActive->fetch(PDO::FETCH_ASSOC);
                        if(!$booking){
                            $replyText="⚠️ Kamar nomor {$roomNumber} tidak sedang disewa (aktif)!";$alertText='Error';
                        }else{
                            if($loggedInStaff){
                                $stmtSetState=$pdo->prepare("UPDATE staff SET telegram_state='waiting_for_transfer_target',telegram_context=? WHERE id=?");
                                $stmtSetState->execute([$roomNumber,$loggedInStaff['id']]);
                            }
                            $menu=$buildTelegramSellableRoomPicker(
                                $pdo,'r_transfer_target:'.$roomNumber.':','r_transfer:'.$roomNumber.':p:',
                                "🔄 *PINDAH KAMAR - TAMU ".tamasyaTelegramPlainText(strtoupper((string)$booking['guestName']),120)."*
Kamar Lama: *{$roomNumber}*",
                                'Pilih nomor kamar kosong tujuan pindah.',
                                '❌ *MAAF, SEMUA KAMAR PENUH!*

Tidak ada kamar kosong yang tersedia secara operasional untuk dipindahkan.',
                                'booking_transfer_menu',$page
                            );
                            $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Pilih Kamar Tujuan';
                        }
                    } catch (Throwable $e) {
                        $replyText = clientExceptionMessage('Pindah kamar ditolak', $e);
                        $alertText = 'Error';
                    }
                } elseif (strpos($callbackData, "r_transfer_target:") === 0) {
                    try {
                        $parts = explode(":", $callbackData);
                        $roomNumber = $parts[1];
                        $targetRoomNumber = $parts[2];

                        $targetStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1");
                        $targetStmt->execute([$targetRoomNumber]);
                        $targetRoom=$targetStmt->fetch(PDO::FETCH_ASSOC);
                        $targetBlockers=$targetRoom ? getRoomOperationalBlockers($pdo,$targetRoomNumber,false) : [];
                        if(!$targetRoom || tamasyaDeriveRoomOperationalStatus($targetBlockers)!=='available'){
                            $replyText=$targetBlockers
                                ? "⚠️ ".roomOperationalBlockerMessage($targetRoomNumber,$targetBlockers)
                                : "⚠️ Kamar tujuan {$targetRoomNumber} sudah tidak tersedia. Silakan pilih kamar lain.";
                            $replyMarkup=["inline_keyboard"=>[[["text"=>"⬅️ Pilih Kamar Lain","callback_data"=>"r_transfer:".$roomNumber]]]];
                            $alertText='Kamar tujuan ditolak';
                        }else{
                            $newCtx = $roomNumber . ":" . $targetRoomNumber;
                            if ($loggedInStaff) {
                                $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_transfer_cost', telegram_context = ? WHERE id = ?");
                                $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                            }

                            $replyText = "💰 *BIAYA TAMBAHAN PINDAH KAMAR*\n\n" .
                                         "Apakah ada biaya tambahan (surcharge) untuk pemindahan Kamar *{$roomNumber}* ke Kamar *{$targetRoomNumber}*?\n\n" .
                                         "*(Ketik angka saja, contoh: `0` jika gratis, atau `50000` jika ada biaya)*";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                                ]
                            ];
                            $alertText = "Ketik Biaya Tambahan";
                        }
                    } catch (Throwable $e) {
                        $replyText = clientExceptionMessage('Pindah kamar ditolak', $e);
                        $alertText = 'Error';
                    }
                } elseif (strpos($callbackData, "r_transfer_confirm:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = trim((string)($parts[1] ?? ''));
                    $targetRoomNumber = trim((string)($parts[2] ?? ''));
                    $costRaw = trim((string)($parts[3] ?? ''));
                    $mode = strtolower(trim((string)($parts[4] ?? '')));
                    $cost = is_numeric($costRaw) ? (float)$costRaw : -1;
                    // Callback lama :paid sengaja ditolak. Pindah kamar hanya menambah
                    // surcharge ke folio; settlement dilakukan di Pembayaran/Checkout.
                    if ($cost < 0 || !in_array($mode,['folio','unpaid'],true)) {
                        $replyText = "❌ Konfirmasi pindah kamar sudah tidak valid. Biaya pindah tidak boleh langsung ditandai lunas. Mulai kembali dari menu Pindah Kamar.";
                        $alertText = "Data ditolak";
                    } else {
                        $paymentStatus='unpaid';
                        try {
                            $bookingKeyStmt=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' ORDER BY actualCheckInAt DESC,checkIn DESC LIMIT 1");
                            $bookingKeyStmt->execute([$roomNumber]);
                            $transferBooking=$bookingKeyStmt->fetch(PDO::FETCH_ASSOC);
                            if(!$transferBooking) throw new RuntimeException("Tidak ada booking aktif di Kamar {$roomNumber}.");
                            $keyContext=checkoutAccessSnapshot($pdo,$transferBooking,false);
                            if(!empty($keyContext['physicalOutstanding'])){
                                $ctxJson=json_encode([
                                    'flow'=>'room_transfer_key','roomNumber'=>$roomNumber,'targetRoomNumber'=>$targetRoomNumber,
                                    'cost'=>$cost,'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash'
                                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                                if($loggedInStaff){
                                    $stmtSetState=$pdo->prepare("UPDATE staff SET telegram_state='waiting_for_transfer_key', telegram_context=? WHERE id=?");
                                    $stmtSetState->execute([$ctxJson,$loggedInStaff['id']]);
                                }
                                $replyText="🔑 *STATUS KUNCI KAMAR ASAL*\n\nSebelum memindahkan Kamar *{$roomNumber}* ke *{$targetRoomNumber}*, konfirmasi kunci fisik kamar asal.\n\nBiaya pindah *Rp ".tamasyaTelegramFormatAmount($cost)."* akan ditambahkan ke folio, bukan dianggap pembayaran.\n\nPilih sesuai kondisi nyata:";
                                $replyMarkup=['inline_keyboard'=>[
                                    [["text"=>"✅ Kunci kembali/ditemukan","callback_data"=>"r_transfer_key:returned"]],
                                    [["text"=>"⚠️ Kunci belum kembali/hilang","callback_data"=>"r_transfer_key:missing"]],
                                    [["text"=>"❌ Batalkan Proses","callback_data"=>"cancel_booking_process"]]
                                ]];
                                $alertText='Konfirmasi kunci asal';
                            }else{
                                $result = finalizeTelegramRoomTransfer(
                                    $pdo,$loggedInStaff,$roomNumber,$targetRoomNumber,(float)$cost,'unpaid',$telegramCallbackOperationId,'callback','not_required','Kamar asal tidak memiliki kunci fisik yang masih outstanding.'
                                );
                                $guestName = tamasyaTelegramPlainText((string)($result['guestName'] ?? 'Tamu'), 120);
                                if (empty($result['duplicate'])) {
                                    $tgMsg = "🔄 *PEMINDAHAN KAMAR (TRANSFER ROOM)*\n\n" .
                                             "👤 *Tamu*: {$guestName}\n" .
                                             "🚪 *Kamar Asal*: {$roomNumber}\n" .
                                             "🚪 *Kamar Tujuan*: {$targetRoomNumber}\n" .
                                             "💰 *Biaya Tambahan Folio*: Rp " . tamasyaTelegramFormatAmount($cost) . "\n" .
                                             "💳 *Settlement*: ⏳ BELUM DIBAYAR — bayar melalui Pembayaran/Checkout\n" .
                                             "👤 *Oleh*: {$loggedInStaff['name']}";
                                    $postCallbackBroadcasts[] = [$tgMsg,false];
                                }
                                $replyText = !empty($result['duplicate'])
                                    ? "ℹ️ Pindah kamar dari tombol ini sudah diproses sebelumnya. Data tidak digandakan."
                                    : "🎉 *PROSES PINDAH KAMAR BERHASIL*! 🎉\n\n" .
                                      "👤 Tamu: *{$guestName}*\n" .
                                      "🚪 Kamar Asal: *{$roomNumber}* (Maintenance/Housekeeping)\n" .
                                      "🚪 Kamar Tujuan: *{$targetRoomNumber}* (Terisi)\n" .
                                      "💰 Biaya Tambahan Folio: *Rp " . tamasyaTelegramFormatAmount($cost) . "*\n" .
                                      "💳 Settlement: *BELUM DIBAYAR* — gunakan menu Pembayaran/Checkout untuk Tunai, Transfer, QRIS, Split, atau OTA yang sah.\n\n" .
                                      "Reservasi, folio, pajak, kunci, Housekeeping, dan audit telah disinkronkan tanpa membuat transaksi kas palsu.";
                                $alertText = !empty($result['duplicate']) ? "Sudah diproses" : "Pindah kamar sukses!";
                                if ($loggedInStaff) {
                                    $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                    $stmtClear->execute([$loggedInStaff['id']]);
                                }
                            }
                        } catch (Throwable $e) {
                            $replyText = clientExceptionMessage("❌ Gagal memproses", $e);
                            $alertText = "Gagal memproses";
                            if ($loggedInStaff) {
                                $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                            }
                        }
                    }
                } elseif (strpos($callbackData, "r_transfer_key:") === 0) {
                    $keyDisposition=strtolower(trim((string)(explode(":",$callbackData,2)[1]??'')));
                    if(!in_array($keyDisposition,['returned','missing'],true)){
                        $replyText='❌ Status kunci pindah kamar tidak valid.';
                        $alertText='Status kunci ditolak';
                    }else{
                        $ctxRaw=(string)($loggedInStaff['telegram_context']??'');
                        $ctx=json_decode($ctxRaw,true);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='room_transfer_key'){
                            $replyText='⚠️ Sesi konfirmasi pindah kamar sudah kedaluwarsa. Mulai kembali dari menu Pindah Kamar.';
                            $alertText='Sesi kedaluwarsa';
                        }else{
                            $roomNumber=trim((string)($ctx['roomNumber']??''));
                            $targetRoomNumber=trim((string)($ctx['targetRoomNumber']??''));
                            $cost=(float)($ctx['cost']??0);
                            $paymentStatus='unpaid';
                            try{
                                $keyReason=$keyDisposition==='returned'
                                    ? 'Petugas mengonfirmasi via Telegram bahwa kunci kamar asal sudah kembali/ditemukan saat pindah kamar.'
                                    : 'Petugas mengonfirmasi via Telegram bahwa kunci kamar asal belum kembali/hilang saat pindah kamar.';
                                $result=finalizeTelegramRoomTransfer(
                                    $pdo,$loggedInStaff,$roomNumber,$targetRoomNumber,$cost,$paymentStatus,$telegramCallbackOperationId,'callback',$keyDisposition,$keyReason
                                );
                                $guestName=tamasyaTelegramPlainText((string)($result['guestName']??'Tamu'),120);
                                if(empty($result['duplicate'])){
                                    $tgMsg="🔄 *PEMINDAHAN KAMAR (TRANSFER ROOM)*\n\n" .
                                           "👤 *Tamu*: {$guestName}\n" .
                                           "🚪 *Kamar Asal*: {$roomNumber}\n" .
                                           "🚪 *Kamar Tujuan*: {$targetRoomNumber}\n" .
                                           "🔑 *Kunci Asal*: " . ($keyDisposition==='returned'?'✅ KEMBALI/DITEMUKAN':'⚠️ BELUM KEMBALI/HILANG') . "\n" .
                                           "💰 *Biaya Tambahan*: Rp " . tamasyaTelegramFormatAmount($cost) . "\n" .
                                           "💳 *Settlement*: ⏳ BELUM DIBAYAR — melalui Pembayaran/Checkout\n" .
                                           "👤 *Oleh*: {$loggedInStaff['name']}";
                                    $postCallbackBroadcasts[] = [$tgMsg,false];
                                }
                                $replyText=!empty($result['duplicate'])
                                    ? 'ℹ️ Pindah kamar dari konfirmasi ini sudah diproses sebelumnya. Data tidak digandakan.'
                                    : "🎉 *PROSES PINDAH KAMAR BERHASIL*! 🎉\n\n👤 Tamu: *{$guestName}*\n🚪 Kamar Asal: *{$roomNumber}* (Maintenance/Housekeeping)\n🚪 Kamar Tujuan: *{$targetRoomNumber}* (Terisi)\n🔑 Kunci Asal: *".($keyDisposition==='returned'?'KEMBALI/DITEMUKAN':'BELUM KEMBALI/HILANG')."*\n💰 Biaya Tambahan: *Rp ".tamasyaTelegramFormatAmount($cost)."*\n💳 Settlement: *BELUM DIBAYAR* — melalui Pembayaran/Checkout\n\nReservasi, folio, pajak, kunci, Housekeeping, dan audit telah disinkronkan tanpa transaksi kas palsu.";
                                $alertText=!empty($result['duplicate'])?'Sudah diproses':'Pindah kamar sukses!';
                            }catch(Throwable $e){
                                $replyText=clientExceptionMessage('❌ Gagal memproses',$e);
                                $alertText='Gagal memproses';
                            }
                            if($loggedInStaff){
                                $stmtClear=$pdo->prepare("UPDATE staff SET telegram_state=NULL, telegram_context=NULL WHERE id=?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                            }
                        }
                    }
                } elseif (strpos($callbackData, "p_room:") === 0) {
                    $roomNumber = explode(":", $callbackData)[1];
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$roomNumber]);
                    $room = $stmtRoom->fetch();
                    
                    if ($room) {
                        $price = (int)$room['price'];
                        $roomCategoryName = getRoomRentalCategoryName($pdo);
                        $replyText = "📥 *INPUT PEMASUKAN MANUAL - KAMAR {$roomNumber}*\n" .
                                     "Tipe Kamar: *{$room['type']}*\n" .
                                     "Pilih kategori pemasukan umum. Pembayaran kamar/laundry/add-on yang menjadi bagian folio wajib melalui workflow Booking/Checkout/Layanan agar saldo folio, pajak, dan jurnal tetap sinkron:";
                        
                        // Fetch all active categories of type 'income' from database (except room sale)
                        $stmtInCats = $pdo->query("SELECT * FROM categories WHERE type='income' AND is_active=1 AND COALESCE(system_key,'') NOT IN ('room_rental','extra_service','pos_revenue','pos_cogs_reversal') ORDER BY name ASC");
                        $inCats = $stmtInCats->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        
                        $inlineKeyboard = [];
                        foreach ($inCats as $cat) {
                            $inlineKeyboard[] = [
                                ["text" => "📥 " . $cat['name'], "callback_data" => "p_room_cat:" . $roomNumber . ":" . $cat['id']]
                            ];
                        }
                        
                        $inlineKeyboard[] = [
                            ["text" => "✍️ Nominal Kustom...", "callback_data" => "p_room_custom:" . $roomNumber]
                        ];
                        
                        $inlineKeyboard[] = [
                            ["text" => "⬅️ Kembali ke Daftar Kamar", "callback_data" => "pemasukan_menu"]
                        ];
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Opsi Kamar {$roomNumber}";
                    } else {
                        $replyText = "❌ Kamar tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "p_room_cat:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $catId = $parts[2];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    if ($catRow) {
                        $catName = $catRow['name'];
                        $replyText = "📥 *PILIH SUBKATEGORI PEMASUKAN* (" . strtoupper($catName) . ")\n" .
                                     "Kamar: *{$roomNumber}*\n\n" .
                                     "Silakan pilih subkategori:";
                        
                        $stmtSubs = $pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND is_active=1 ORDER BY name ASC");
                        $stmtSubs->execute([$catId]);
                        $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
                        
                        $inlineKeyboard = [];
                        foreach ($subs as $sub) {
                            $inlineKeyboard[] = [
                                ["text" => "🔹 " . $sub['name'], "callback_data" => "p_room_sub:" . $roomNumber . ":" . $catId . ":" . $sub['id']]
                            ];
                        }
                        
                        $inlineKeyboard[] = [
                            ["text" => "⬅️ Kembali", "callback_data" => "p_room:" . $roomNumber]
                        ];
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Pilih Subkategori";
                    } else {
                        $replyText = "❌ Kategori tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "p_room_sub:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $catId = $parts[2];
                    $subId = $parts[3];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    $stmtSub = $pdo->prepare("SELECT * FROM subcategories WHERE id=? AND category_id=? AND is_active=1 LIMIT 1");
                    $stmtSub->execute([$subId,$catId]);
                    $subRow = $stmtSub->fetch();
                    
                    if ($catRow && $subRow) {
                        $catName = $catRow['name'];
                        $subName = $subRow['name'];
                        
                        $replyText = "💰 *PILIH NOMINAL PEMASUKAN*\n" .
                                     "• Kamar: *{$roomNumber}*\n" .
                                     "• Kategori: *{$catName}*\n" .
                                     "• Subkategori: *{$subName}*\n\n" .
                                     "Silakan pilih nominal cepat atau gunakan kustom:";
                                     
                        $quickAmounts = [15000, 25000, 50000, 75000, 100000, 150000, 200000];
                        $inlineKeyboard = [];
                        foreach ($quickAmounts as $amount) {
                            $inlineKeyboard[] = [
                                ["text" => "💵 Rp " . tamasyaTelegramFormatAmount($amount), "callback_data" => "p_room_confirm_direct:" . $roomNumber . ":" . $catId . ":" . $subId . ":" . $amount]
                            ];
                        }
                        
                        $inlineKeyboard[] = [
                            ["text" => "✍️ Nominal Kustom...", "callback_data" => "p_room_custom_sub:" . $roomNumber . ":" . $catId . ":" . $subId]
                        ];
                        $inlineKeyboard[] = [
                            ["text" => "⬅️ Kembali", "callback_data" => "p_room_cat:" . $roomNumber . ":" . $catId]
                        ];
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Pilih Nominal";
                    } else {
                        $replyText = "❌ Kategori/Subkategori tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "p_room_custom_sub:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $catId = $parts[2];
                    $subId = $parts[3];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    $stmtSub = $pdo->prepare("SELECT * FROM subcategories WHERE id=? AND category_id=? AND is_active=1 LIMIT 1");
                    $stmtSub->execute([$subId,$catId]);
                    $subRow = $stmtSub->fetch();
                    
                    $catName = $catRow ? $catRow['name'] : 'Lain-lain';
                    $subName = $subRow ? $subRow['name'] : 'Umum';
                    
                    $replyText = "✍ *INPUT NOMINAL PEMASUKAN KUSTOM* (" . strtoupper($catName) . " - " . strtoupper($subName) . ")\n\n" .
                                 "Kirim pesan teks manual ke Bot dengan format:\n" .
                                 "`/pemasukan_sub {$roomNumber} {$catId} {$subId} <nominal>`\n\n" .
                                 "*Contoh*:\n" .
                                 "`/pemasukan_sub {$roomNumber} {$catId} {$subId} 45000`";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "⬅️ Kembali", "callback_data" => "p_room_sub:" . $roomNumber . ":" . $catId . ":" . $subId]]
                        ]
                    ];
                    $alertText = "Pemasukan Kustom";
                } elseif (strpos($callbackData, "p_room_confirm_direct:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = $parts[1];
                    $catId = $parts[2];
                    $subId = $parts[3];
                    $amount = (float)$parts[4];
                    
                    if (!is_finite($amount) || $amount <= 0) {
                        $replyText = "❌ Nominal pemasukan tidak valid. Tidak ada transaksi yang dibuat.";
                        $alertText = "Nominal ditolak";
                    } elseif (!$loggedInStaff || !in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'], true)) {
                        $replyText = "🔒 Akses Ditolak!";
                        $alertText = "Akses ditolak!";
                    } else {
                        $catRow = $subRow = $validatedRoom = null;
                        try {
                            $validatedRoom = requireTelegramRoom($pdo,(string)$roomNumber,false);
                            if((string)$subId==='__room__'){
                                throw new RuntimeException('Tombol pemasukan kamar lama sudah dinonaktifkan. Pembayaran kamar wajib melalui workflow Booking/Checkout agar folio, pajak, dan jurnal tetap sinkron. Jalankan /start untuk memuat menu terbaru.');
                            }else{
                                $pair = requireTelegramFinancialCategoryPair($pdo,(string)$catId,(string)$subId,'income');
                                $catRow = ['id'=>(string)$catId,'name'=>$pair['category_name'],'system_key'=>null];
                                $subRow = ['id'=>(string)$subId,'name'=>$pair['subcategory_name']];
                            }
                        } catch (Throwable $pairError) {
                            $replyText = "❌ " . clientExceptionMessage('Data pemasukan ditolak',$pairError);
                            $alertText = "Data tidak valid";
                        }
                        
                        if ($catRow && $subRow && $validatedRoom) {
                            $category = $catRow['name'];
                            $subcat = $subRow['name'];
                            
                            // Booking aktif hanya dipakai sebagai konteks sumber/pajak. Command pemasukan manual tidak boleh menyelesaikan folio booking atau mewarisi Piutang OTA.
                            $bookingId = null;
                            $bookingForTax = null;
                            if (!empty($roomNumber)) {
                                $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                                $stmtB->execute([$roomNumber]);
                                $bRow = $stmtB->fetch();
                                if ($bRow) {
                                    $bookingForTax = $bRow;
                                }
                            }

                            $today = date("Y-m-d");
                            $desc = "Pemasukan {$category} - {$subcat} Kamar {$roomNumber} via Bot oleh " . $loggedInStaff['name'];
                            
                            $bookingSource = $bookingForTax['bookingSource'] ?? 'Direct';
                            $bankAccountId = null; // /pemasukan adalah kas manual; rekening booking tidak boleh diwarisi.
                            $notifMsg = "Transaksi Masuk oleh {$loggedInStaff['name']} via Telegram Bot: Pemasukan Kamar {$roomNumber} ({$category}) senilai Rp " . tamasyaTelegramFormatAmount($amount);
                                try {
                            $tgTx=createTelegramCashTransaction($pdo, $telegramCallbackOperationId, $loggedInStaff, [
                                'type'=>'income','category'=>$category,'categoryId'=>$catRow['id']??null,'categorySystemKey'=>$catRow['system_key']??null,'subcategory'=>$subcat,'subcategoryId'=>$subRow['id']??null,'roomNumber'=>$roomNumber,'amount'=>$amount,
                                'date'=>$today,'description'=>$desc,'bookingId'=>null,'bookingSource'=>$bookingSource,'bankAccountId'=>$bankAccountId
                            ], $notifMsg, 'callback');
                            $txRate=(float)($tgTx['taxRate']??0);
                            $txTax=(float)($tgTx['taxAmount']??0);
$txBase = array_key_exists('baseAmount', $tgTx) && $tgTx['baseAmount'] !== null ? (float) $tgTx['baseAmount'] : null;
                            $txBaseDisplay = $txBase === null ? 'unresolved' : tamasyaTelegramFormatAmount($txBase);
                            
                            $taxDetailMsg = "• Harga Dasar: " . $txBaseDisplay . "\n" .
                                ($txRate > 0
                                    ? "• Pajak PBJT ({$txRate}%): *Rp " . tamasyaTelegramFormatAmount($txTax) . "*\n"
                                    : "• Pajak PBJT: *Rp 0* (sesuai tax_rules aktif)\n");
                            
                            $replyText = "✅ *SUKSES INPUT PEMASUKAN*!\n\n" .
                                         "Arus kas masuk tersimpan:\n" .
                                         "• Kamar: *{$roomNumber}*\n" .
                                         "• Kategori: *{$category}*\n" .
                                         "• Subkategori: *{$subcat}*\n" .
                                         $taxDetailMsg .
                                         "• Total Nominal: *Rp " . tamasyaTelegramFormatAmount($amount) . "*\n" .
                                         "• Petugas: *{$loggedInStaff['name']}*\n\n" .
                                         "Terima kasih! Data sinkron ke dasbor.";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "📥 Input Pemasukan Lainnya", "callback_data" => "pemasukan_menu"]],
                                    [["text" => "📊 Cek Laporan Kas", "callback_data" => "laporan_kas_refresh"]]
                                ]
                            ];
                            $alertText = "Transaksi Disimpan!";
                                } catch (Throwable $incomeCallbackError) {
                                    $replyText = "❌ " . clientExceptionMessage('Pemasukan ditolak', $incomeCallbackError);
                                }
                        } else {
                            $replyText = "❌ Master kategori transaksi tidak valid!";
                        }
                    }
                } elseif (strpos($callbackData, "p_room_custom:") === 0) {
                    $roomNumber = explode(":", $callbackData)[1];
                    $replyText = "✍️ *INPUT PEMASUKAN KUSTOM - KAMAR {$roomNumber}*\n\n" .
                                 "Kirim pesan teks manual ke Bot dengan format:\n" .
                                 "`/pemasukan {$roomNumber} <nominal>`\n\n" .
                                 "*Contoh*:\n" .
                                 "`/pemasukan {$roomNumber} 350000` (Pemasukan Rp 350.000)";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "⬅️ Kembali ke Menu Kamar", "callback_data" => "p_room:" . $roomNumber]]
                        ]
                    ];
                    $alertText = "Input Kustom";
                } elseif ($callbackData === "pengeluaran_menu") {
                    if (!$loggedInStaff || !in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'])) {
                        $replyText = "🔒 Akses Ditolak! Menu ini hanya untuk Admin/Manajer/Finance.";
                        $alertText = "Akses ditolak!";
                    } else {
                        $replyText = "📤 *INPUT PENGELUARAN KAS*\n\nSilakan pilih kategori pengeluaran kas:";
                        
                        // Fetch all categories of type 'expense'
                        $stmtExCats = $pdo->query("SELECT * FROM categories WHERE type='expense' AND is_active=1 AND COALESCE(is_system,0)=0 AND COALESCE(system_key,'') NOT IN ('payroll_expense','maintenance_expense','pos_refund','pos_cogs') ORDER BY name ASC");
                        $dbCats = $stmtExCats->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        
                        $inlineKeyboard = [];
                        foreach ($dbCats as $cat) {
                            $inlineKeyboard[] = [
                                ["text" => "📂 " . $cat['name'], "callback_data" => "p_exp_cat:" . $cat['id']]
                            ];
                        }
                        
                        if (empty($dbCats)) {
                            $replyText .= "\n\n⚠️ Belum ada kategori pengeluaran aktif di Master Data. Telegram tidak membuat kategori sintetis.";
                            $inlineKeyboard = [[['text'=>'⬅️ Kas & Shift','callback_data'=>'cash_shift_menu']]];
                        }
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Kategori Pengeluaran";
                    }
                } elseif (strpos($callbackData, "p_exp_cat:") === 0) {
                    $catId = explode(":", $callbackData)[1];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    if ($catRow) {
                        $catName = $catRow['name'];
                        $replyText = "📤 *PILIH TRANSAKSI PENGELUARAN* (" . strtoupper($catName) . "):\n\nSilakan pilih salah satu opsi transaksi:";
                        
                        // Fetch all subcategories from database for this category name
                        $stmtSubs = $pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND is_active=1 ORDER BY name ASC");
                        $stmtSubs->execute([$catId]);
                        $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
                        
                        $inlineKeyboard = [];
                        foreach ($subs as $sub) {
                            $inlineKeyboard[] = [
                                ["text" => "🔹 " . $sub['name'] . " (Pilih)", "callback_data" => "p_exp_sub:" . $catId . ":" . $sub['id']]
                            ];
                        }

                        if (empty($subs)) {
                            $quickAmounts = [50000, 100000, 150000, 200000, 350000, 500000, 1000000];
                            if (stripos($catName, 'gaji') !== false) {
                                $quickAmounts = [1500000, 1800000, 2000000, 2500000, 3000000];
                            }
                            foreach ($quickAmounts as $amount) {
                                $inlineKeyboard[] = [
                                    ["text" => "💵 Rp " . tamasyaTelegramFormatAmount($amount), "callback_data" => "p_exp_confirm_direct:" . $catId . "::" . $amount]
                                ];
                            }
                        }

                        $inlineKeyboard[] = [
                            ["text" => "✍️ Nominal & Foto (Kustom)", "callback_data" => "p_exp_custom:" . $catId]
                        ];
                        $inlineKeyboard[] = [
                            ["text" => "⬅️ Kembali", "callback_data" => "pengeluaran_menu"]
                        ];
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Opsi Subkategori";
                    } else {
                        // Fresh V137 accepts only canonical category IDs issued by the current bot.
                        $replyText = "❌ Kategori tidak ditemukan atau sudah tidak aktif. Jalankan /start untuk memuat menu terbaru.";
                    }
                } elseif (strpos($callbackData, "p_exp_sub:") === 0) {
                    $parts = explode(":", $callbackData);
                    $catId = $parts[1];
                    $subId = $parts[2];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    $stmtSub = $pdo->prepare("SELECT * FROM subcategories WHERE id=? AND category_id=? AND is_active=1 LIMIT 1");
                    $stmtSub->execute([$subId,$catId]);
                    $subRow = $stmtSub->fetch();
                    
                    if ($catRow && $subRow) {
                        $catName = $catRow['name'];
                        $subName = $subRow['name'];
                        
                        $replyText = "💰 *PILIH NOMINAL PENGELUARAN*\n\n" .
                                     "• Kategori: *{$catName}*\n" .
                                     "• Subkategori: *{$subName}*\n\n" .
                                     "Silakan pilih nominal cepat atau gunakan input kustom:";
                        
                        $quickAmounts = [50000, 100000, 150000, 200000, 350000, 500000, 1000000];
                        if (stripos($catName, 'gaji') !== false) {
                            $quickAmounts = [1500000, 1800000, 2000000, 2500000, 3000000];
                        }
                        
                        $inlineKeyboard = [];
                        foreach ($quickAmounts as $amount) {
                            $inlineKeyboard[] = [
                                ["text" => "💵 Rp " . tamasyaTelegramFormatAmount($amount), "callback_data" => "p_exp_confirm_direct:" . $catId . ":" . $subId . ":" . $amount]
                            ];
                        }
                        
                        $inlineKeyboard[] = [
                            ["text" => "✍️ Nominal Kustom...", "callback_data" => "p_exp_custom_sub:" . $catId . ":" . $subId]
                        ];
                        $inlineKeyboard[] = [
                            ["text" => "⬅️ Kembali", "callback_data" => "p_exp_cat:" . $catId]
                        ];
                        
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        $alertText = "Pilih Nominal";
                    } else {
                        $replyText = "❌ Kategori atau Subkategori tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "p_exp_custom_sub:") === 0) {
                    $parts = explode(":", $callbackData);
                    $catId = $parts[1];
                    $subId = $parts[2];
                    
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    
                    $stmtSub = $pdo->prepare("SELECT * FROM subcategories WHERE id=? AND category_id=? AND is_active=1 LIMIT 1");
                    $stmtSub->execute([$subId,$catId]);
                    $subRow = $stmtSub->fetch();
                    
                    $catName = $catRow ? $catRow['name'] : 'Lain-lain';
                    $subName = $subRow ? $subRow['name'] : 'Umum';
                    
                    $ctx = json_encode(['flow'=>'expense_input','catId'=>$catId,'subId'=>$subId,'photoFileId'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_expense_input',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']='waiting_for_expense_input';
                    $loggedInStaff['telegram_context']=$ctx;
                    $replyText = "✍ *INPUT PENGELUARAN* (" . strtoupper($catName) . " - " . strtoupper($subName) . ")\n\nKetik nominal pengeluaran sekarang.\nContoh: `125000 beli sapu ijuk`\n\nAnda juga bisa kirim foto nota sebelum atau sesudah mengetik nominal.";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "❌ Batalkan", "callback_data" => "p_exp_sub:" . $catId . ":" . $subId]]
                        ]
                    ];
                    $alertText = "Input Pengeluaran";
                } elseif (strpos($callbackData, "p_exp_confirm_direct:") === 0) {
                    $parts = explode(":", $callbackData);
                    $catId = $parts[1];
                    $subId = $parts[2];
                    $amount = (float)$parts[3];
                    
                    if (!$loggedInStaff || !in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'], true)) {
                        $replyText = "🔒 Akses Ditolak!";
                        $alertText = "Akses ditolak!";
                    } elseif (!is_finite($amount) || $amount <= 0) {
                        $replyText = "❌ Nominal pengeluaran tidak valid. Tidak ada transaksi yang dibuat.";
                        $alertText = "Nominal ditolak";
                    } else {
                        $catRow = $subRow = null;
                        if ($subId === '') {
                            $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND type='expense' AND is_active=1 LIMIT 1");
                            $stmtCat->execute([$catId]);
                            $dbCat = $stmtCat->fetch(PDO::FETCH_ASSOC);
                            if ($dbCat) {
                                if (in_array((string)($dbCat['system_key'] ?? ''), ['payroll_expense','maintenance_expense','pos_refund','pos_cogs'], true)) {
                                    $replyText = "❌ Kategori sistem tidak dapat dipakai untuk input manual.";
                                    $alertText = "Kategori ditolak";
                                    $dbCat = null;
                                } else {
                                    $catRow = ['name'=>$dbCat['name']];
                                    $subRow = ['name'=>null];
                                }
                            } else {
                                $replyText = "❌ " . clientExceptionMessage('Data pengeluaran ditolak', new RuntimeException('Kategori pengeluaran tidak ditemukan.'));
                                $alertText = "Data tidak valid";
                            }
                        } else {
                            try {
                                $pair = requireTelegramFinancialCategoryPair($pdo,(string)$catId,(string)$subId,'expense');
                                $catRow = ['name'=>$pair['category_name']];
                                $subRow = ['name'=>$pair['subcategory_name']];
                            } catch (Throwable $pairError) {
                                $replyText = "❌ " . clientExceptionMessage('Data pengeluaran ditolak',$pairError);
                                $alertText = "Data tidak valid";
                            }
                        }

                        if ($catRow && $subRow) {
                            $finalCategory = $catRow['name'];
                            $subcat = $subRow['name'];

                            $today = date("Y-m-d");
                            $desc = "Pengeluaran {$finalCategory}" . ($subcat ? " - {$subcat}" : "") . " via Telegram Bot oleh " . $loggedInStaff['name'];
                            $notifMsg = "Transaksi Keluar oleh {$loggedInStaff['name']} via Telegram Bot: Pengeluaran {$finalCategory}" . ($subcat ? " ({$subcat})" : "") . " senilai Rp " . tamasyaTelegramFormatAmount($amount);
                            try {
                                createTelegramCashTransaction($pdo, $telegramCallbackOperationId, $loggedInStaff, [
                                    'type'=>'expense','categoryId'=>$catId,'category'=>$finalCategory,'subcategoryId'=>$subId !== '' ? $subId : null,'subcategory'=>$subcat,'amount'=>$amount,'date'=>$today,
                                    'description'=>$desc,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
                                    'bankAccountId'=>null
                                ], $notifMsg, 'callback');

                                $replyText = "✅ *SUKSES INPUT PENGELUARAN*!\n\n" .
                                             "Arus kas keluar tersimpan:\n" .
                                             "• Kategori: *{$finalCategory}*\n" .
                                             ($subcat ? "• Subkategori: *{$subcat}*\n" : "") .
                                             "• Nominal: *Rp " . tamasyaTelegramFormatAmount($amount) . "*\n" .
                                             "• Petugas: *{$loggedInStaff['name']}*\n\n" .
                                             "Data sinkron ke dasbor utama.";
                                $replyMarkup = [
                                    "inline_keyboard" => [
                                        [["text" => "📤 Input Pengeluaran Lainnya", "callback_data" => "pengeluaran_menu"]],
                                        [["text" => "📊 Cek Laporan Kas", "callback_data" => "laporan_kas_refresh"]]
                                    ]
                                ];
                                $alertText = "Pengeluaran Disimpan!";
                            } catch (Throwable $expenseDirectError) {
                                $replyText = "❌ " . clientExceptionMessage('Pengeluaran ditolak', $expenseDirectError);
                                $replyMarkup = null;
                                $alertText = "Pengeluaran ditolak";
                            }
                        } else {
                            $replyText = "❌ Kategori/Subkategori tidak valid!";
                        }
                    }
                } elseif (strpos($callbackData, "p_exp_custom:") === 0) {
                    $catId = explode(":", $callbackData)[1];
                    $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE id=? AND is_active=1 LIMIT 1");
                    $stmtCat->execute([$catId]);
                    $catRow = $stmtCat->fetch();
                    $catName = $catRow ? $catRow['name'] : 'Lain-lain';
                    
                    $ctx = json_encode(['flow'=>'expense_input','catId'=>$catId,'subId'=>null,'photoFileId'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_expense_input',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']='waiting_for_expense_input';
                    $loggedInStaff['telegram_context']=$ctx;
                    $replyText = "✍ *INPUT PENGELUARAN* (" . strtoupper($catName) . ")\n\nKetik nominal pengeluaran sekarang.\nContoh: `125000 token listrik`\n\nAnda juga bisa kirim foto nota sebelum atau sesudah mengetik nominal.";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "❌ Batalkan", "callback_data" => "p_exp_cat:" . $catId]]
                        ]
                    ];
                    $alertText = "Input Pengeluaran";
                } elseif ($callbackData === "laporan_kas_refresh") {
                    if (!$loggedInStaff || !in_array($loggedInStaff['role'], ['admin', 'owner', 'manager', 'finance'])) {
                        $replyText = "🔒 Akses Ditolak!";
                        $alertText = "Akses ditolak!";
                    } else {
                        $saldoAwal = isset($confRow['initial_balance']) ? (float)$confRow['initial_balance'] : 0;
                        $cashSummary = tamasyaCanonicalCashSummary($pdo,$saldoAwal);
                        $income = (float)$cashSummary['liquidIncome'];
                        $expense = (float)$cashSummary['liquidExpense'];
                        $cashflow = (float)$cashSummary['cashFlow'];
                        $cashOnlyIncome=(float)$cashSummary['cashOnlyIncome'];
                        $digitalIncome=(float)$cashSummary['digitalIncome'];
                        $finalBalance = (float)$cashSummary['liquidBalance'];
                        
                        $replyText = "📈 *LAPORAN ARUS KAS {$propertyName}*\n\n" .
                                     "👤 Diakses oleh: *{$loggedInStaff['name']}* (*" . $telegramRoleLabel((string)$loggedInStaff['role']) . "*)\n\n" .
                                     "💰 Saldo Awal: Rp " . tamasyaTelegramFormatAmount($saldoAwal) . "\n" .
                                     "📥 Total Pemasukan Likuid: Rp " . tamasyaTelegramFormatAmount($income) . "\n" .
                                     "   ├ Tunai: Rp " . tamasyaTelegramFormatAmount($cashOnlyIncome) . "\n" .
                                     "   └ Transfer/QRIS: Rp " . tamasyaTelegramFormatAmount($digitalIncome) . "\n" .
                                     "📤 Total Pengeluaran Likuid: Rp " . tamasyaTelegramFormatAmount($expense) . "\n" .
                                     "------------------------------------\n" .
                                     "💵 *Arus Likuid Bersih: Rp " . tamasyaTelegramFormatAmount($cashflow) . "*\n" .
                                     "💰 *Saldo Likuid Akhir: Rp " . tamasyaTelegramFormatAmount($finalBalance) . "*\n\n" .
                                     "Laporan langsung tersinkron ke semua perangkat terhubung.";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "🔄 Muat Ulang Laporan", "callback_data" => "laporan_kas_refresh"]]
                            ]
                        ];
                        $alertText = "Laporan dimuat ulang!";
                    }
                } elseif ($callbackData === "riwayat_shift_menu" || str_starts_with($callbackData,"riwayat_shift_menu:p:") || str_starts_with($callbackData,"riwayat_shift_detail:")) {
                    // Read-only: riwayat tutup shift untuk admin/owner/manager/finance.
                    $histPage=1;$histDetail='';
                    if(str_starts_with($callbackData,"riwayat_shift_menu:p:"))$histPage=max(1,(int)substr($callbackData,strlen("riwayat_shift_menu:p:")));
                    elseif(str_starts_with($callbackData,"riwayat_shift_detail:"))$histDetail=trim((string)substr($callbackData,strlen("riwayat_shift_detail:")));
                    if($histDetail!==''){
                        $hist=tamasyaClosedShiftDetail($pdo,$histDetail);
                        if(!$hist){$replyText='⚠️ Data shift tidak ditemukan (mungkin sudah berubah).';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Riwayat Shift','callback_data'=>'riwayat_shift_menu']]]];}
                        else{
                            $variance=(float)($hist['variance']??0);
                            $histDigital=(float)($hist['digital_revenue']??0);
                            $histTxCount=(int)($hist['transactions_count']??0);
                            $depLines=[];
                            foreach((array)($hist['depositSummary']??[]) as $depRow)$depLines[]='• '.(string)($depRow['entry_type']??'-').': Rp '.tamasyaTelegramFormatAmount((float)($depRow['total']??0)).' ('.(int)($depRow['n']??0).'x)';
                            $replyText="📋 *RINCIAN TUTUP SHIFT*\n\n"
                                ."📅 ".substr((string)($hist['shift_date']??'-'),0,10)." · ⏰ ".tamasyaTelegramPlainText((string)($hist['shift_time']??'-'),20)."\n"
                                ."👤 ".tamasyaTelegramPlainText((string)($hist['staff_name']??'-'),60).((string)($hist['companion_staff_name']??'')!==''?' + '.tamasyaTelegramPlainText((string)$hist['companion_staff_name'],60):'')."\n"
                                ."🕰️ ".substr((string)($hist['opened_at']??'-'),0,16)." → ".substr((string)($hist['closed_at']??'-'),0,16)."\n\n"
                                ."💰 Kas awal: Rp ".tamasyaTelegramFormatAmount((float)($hist['opening_cash']??0))."\n"
                                ."📥 Pemasukan tunai: Rp ".tamasyaTelegramFormatAmount((float)($hist['cash_income']??0))."\n"
                                ."💳 Pemasukan non-tunai (Transfer/QRIS): Rp ".tamasyaTelegramFormatAmount($histDigital)."\n"
                                ."📤 Pengeluaran: Rp ".tamasyaTelegramFormatAmount((float)($hist['cash_expense']??0))."\n"
                                ."🎯 Kas harapan (laci): Rp ".tamasyaTelegramFormatAmount((float)($hist['expected_cash']??0))."\n"
                                ."💵 Kas aktual (laci): Rp ".tamasyaTelegramFormatAmount((float)($hist['actual_cash']??0))."\n"
                                ."⚖️ Selisih: Rp ".tamasyaTelegramFormatAmount($variance).($variance!=0?' ⚠️':' ✅')."\n"
                                ."📈 Jumlah transaksi: {$histTxCount}\n"
                                .(((string)($hist['close_override_reason']??'')!=='')?"📝 Override: ".tamasyaTelegramPlainText((string)$hist['close_override_reason'],120)."\n":'')
                                .(((string)($hist['notes']??'')!=='')?"🗒️ Catatan: ".tamasyaTelegramPlainText((string)$hist['notes'],200)."\n":'')
                                ."\n🛡 Deposito:\n".($depLines?implode("\n",$depLines):'• tidak ada pergerakan');
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Riwayat Shift','callback_data'=>'riwayat_shift_menu']]]];
                        }
                        $alertText='Rincian shift';
                    }else{
                        $histData=tamasyaClosedShiftHistory($pdo,$histPage,5);
                        if($histData['count']<=0)$replyText="📋 *RIWAYAT TUTUP SHIFT*\n\nBelum ada shift yang ditutup.";
                        else{
                            $histLines=[];
                            foreach($histData['rows'] as $histRow){
                                $histVar=(float)($histRow['variance']??0);
                                $histLines[]='• '.substr((string)($histRow['shift_date']??'-'),0,10).' '.tamasyaTelegramPlainText((string)($histRow['shift_time']??'-'),12).' — '.tamasyaTelegramPlainText((string)($histRow['staff_name']??'-'),30).' — Rp '.tamasyaTelegramFormatAmount((float)($histRow['actual_cash']??0)).($histVar!=0?' (selisih Rp '.tamasyaTelegramFormatAmount($histVar).')':'');
                            }
                            $replyText="📋 *RIWAYAT TUTUP SHIFT*\n\n".implode("\n",$histLines)."\n\nHalaman *{$histData['page']}/{$histData['totalPages']}* · {$histData['count']} shift. Ketuk tombol untuk rincian.";
                        }
                        $histButtons=[];
                        foreach($histData['rows'] as $histRow)$histButtons[]=[['text'=>'🧾 '.substr((string)($histRow['shift_date']??'?'),5,5).' '.tamasyaTelegramPlainText((string)($histRow['shift_time']??''),12),'callback_data'=>'riwayat_shift_detail:'.(string)$histRow['id']]];
                        if($histData['totalPages']>1)$histButtons[]=$buildTelegramPagerRow($histData['page'],$histData['totalPages'],'riwayat_shift_menu:p:');
                        $histButtons[]=[['text'=>'⬅️ Kas & Shift','callback_data'=>'cash_shift_menu']];
                        $replyMarkup=['inline_keyboard'=>$histButtons];$alertText='Riwayat shift';
                    }
                } elseif ($callbackData === "housekeeping_menu" || str_starts_with($callbackData,"housekeeping_menu:p:")) {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $page = 1;
                    if (str_starts_with($callbackData,"housekeeping_menu:p:")) {
                        $page = max(1,(int)substr($callbackData,strlen("housekeeping_menu:p:")));
                    }
                    $pageSize = 30; // 10 baris x 3 tombol: tetap nyaman di HP dan ringan untuk hotel besar.
                    $countRooms = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
                    $totalPages = max(1,(int)ceil($countRooms/$pageSize));
                    $page = min($page,$totalPages);
                    $offset = ($page-1)*$pageSize;
                    $stmtRooms = $pdo->prepare("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC, number ASC LIMIT ? OFFSET ?");
                    $stmtRooms->bindValue(1,$pageSize,PDO::PARAM_INT);
                    $stmtRooms->bindValue(2,$offset,PDO::PARAM_INT);
                    $stmtRooms->execute();
                    $rooms = $stmtRooms->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));

                    $inlineKeyboardButtons = [];
                    $currentRow = [];
                    foreach ($rooms as $room) {
                        $number=(string)$room['number'];$canonical=$roomStatusMap[$number]??'maintenance';
                        $statusIcon=$canonical==='booked'?'🔴':($canonical==='dirty'?'🧹':($canonical==='maintenance'?'🛠️':'🟢'));
                        $currentRow[] = [
                            "text" => $statusIcon . " Kamar " . $number,
                            "callback_data" => "hk_room:" . $room['number']
                        ];
                        if (count($currentRow) >= 3) { $inlineKeyboardButtons[]=$currentRow; $currentRow=[]; }
                    }
                    if ($currentRow) $inlineKeyboardButtons[]=$currentRow;
                    if ($totalPages > 1) {
                        $nav=[];
                        if($page>1)$nav[]=["text"=>"⬅️ {$page}-1","callback_data"=>"housekeeping_menu:p:".($page-1)];
                        $nav[]=["text"=>"📄 {$page}/{$totalPages}","callback_data"=>"housekeeping_menu:p:{$page}"];
                        if($page<$totalPages)$nav[]=["text"=>"➡️ {$page}+1","callback_data"=>"housekeeping_menu:p:".($page+1)];
                        $inlineKeyboardButtons[]=$nav;
                    }
                    $inlineKeyboardButtons[] = [["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]];

                    $rangeStart = $countRooms ? $offset+1 : 0;
                    $rangeEnd = min($offset+$pageSize,$countRooms);
                    $replyText = "🧹 *HOUSEKEEPING KAMAR*\n\nPilih nomor kamar untuk mengelola status Housekeeping.\n📄 Halaman *{$page}/{$totalPages}* · kamar *{$rangeStart}-{$rangeEnd}* dari *{$countRooms}*.";
                    $replyMarkup = ["inline_keyboard" => $inlineKeyboardButtons];
                    $alertText = "Housekeeping {$page}/{$totalPages}";
                } elseif (strpos($callbackData, "hk_room:") === 0) {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                    $roomNumber = trim((string)(explode(":", $callbackData, 2)[1] ?? ''));
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$roomNumber]);
                    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
                    
                    if ($room) {
                        $roomBlockersAll=getRoomOperationalBlockersFastRead($pdo,$roomNumber);
                        $canonicalStatus=tamasyaDeriveRoomOperationalStatus($roomBlockersAll);
                        $statusIcon=$canonicalStatus==='booked'?'🔴':($canonicalStatus==='dirty'?'🧹':($canonicalStatus==='maintenance'?'🛠️':'🟢'));
                        $hkTaskStmt=$pdo->prepare("SELECT id,status,assigned_to FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at DESC,id DESC LIMIT 1");
                        $hkTaskStmt->execute([$roomNumber]);
                        $hkTask=$hkTaskStmt->fetch(PDO::FETCH_ASSOC)?:null;
                        $hkTaskStatus=strtolower(trim((string)($hkTask['status']??'')));
                        $externalBlockers=getRoomOperationalBlockersFastRead($pdo,$roomNumber,$hkTask?(string)$hkTask['id']:'');
                        $activeBookingId='';
                        foreach($externalBlockers as $roomBlocker){
                            if((string)($roomBlocker['type']??'')==='active_booking'){
                                $activeBookingId=trim((string)($roomBlocker['id']??''));
                                break;
                            }
                        }
                        $actionRows=[];$operationalNote='';
                        if($activeBookingId!==''){
                            $operationalNote="\n\n🔒 Kamar masih ditempati booking *{$activeBookingId}*. Housekeeping kamar kosong baru dapat diproses setelah checkout/pindah kamar selesai.";
                        }else{
                            if(!$hkTask || $hkTaskStatus!=='blocked'){
                                if($hkTaskStatus!=='cleaning')$actionRows[]=[["text"=>"🧹 Mulai Bersihkan","callback_data"=>"hk_set:".$roomNumber.":cleaning"]];
                                $actionRows[]=[["text"=>"⚠️ Kerusakan / Perlu Perbaikan","callback_data"=>"hk_set:".$roomNumber.":need_maintenance"]];
                            }
                            if($hkTask){
                                if(in_array($hkTaskStatus,['cleaning','inspection'],true) && !$externalBlockers){
                                    $actionRows[]=[["text"=>"✅ Selesai (Set Siap/Tersedia)","callback_data"=>"hk_set:".$roomNumber.":clean_available"]];
                                }elseif($hkTaskStatus==='blocked'){
                                    $operationalNote="\n\n⚠️ Task housekeeping berstatus *BLOCKED* dan menunggu maintenance/review sebelum dapat dinyatakan siap jual.";
                                }elseif($externalBlockers){
                                    $operationalNote="\n\n⚠️ Tombol Selesai ditahan: ".roomOperationalBlockerMessage($roomNumber,$externalBlockers);
                                }
                            }else{
                                $operationalNote="\n\nℹ️ Belum ada task housekeeping aktif. Mulai pembersihan atau laporkan perbaikan terlebih dahulu.";
                            }
                        }
                        $actionRows[]=[["text"=>"⬅️ Kembali ke Housekeeping","callback_data"=>"housekeeping_menu"]];
                        $taskLine=$hkTask?"\nTask: *".(string)$hkTask['id']."* (".strtoupper($hkTaskStatus).")":'';
                        $replyText = "🧹 *HOUSEKEEPING - KAMAR {$roomNumber}*\n\n" .
                                     "Tipe Kamar: *{$room['type']}*\n" .
                                     "Status Operasional Saat Ini: " . $statusIcon . " *" . $telegramRoomStatusLabel($canonicalStatus) . "*".$taskLine.$operationalNote;
                        $replyMarkup = ["inline_keyboard"=>$actionRows];
                        $alertText = "Kamar " . $roomNumber;
                    } else {
                        $replyText = "❌ Kamar tidak ditemukan!";
                    }
                } elseif (strpos($callbackData, "hk_set:") === 0) {
                    $parts = explode(":", $callbackData);
                    $roomNumber = trim((string)($parts[1] ?? ''));
                    $action = trim((string)($parts[2] ?? ''));
                    $actionMap = [
                        'cleaning' => ['status'=>'maintenance','notification'=>'🧹 [HOUSEKEEPING] Pembersihan dimulai','reply'=>'🧹 *PEMBERSIHAN DIMULAI*','broadcast'=>'🧹 *PEMBERSIHAN DIMULAI*'],
                        'clean_available' => ['status'=>'available','notification'=>'✅ [HOUSEKEEPING] Kamar selesai dibersihkan','reply'=>'✅ *PEMBERSIHAN SELESAI*','broadcast'=>'✅ *PEMBERSIHAN SELESAI*'],
                        'need_maintenance' => ['status'=>'maintenance','notification'=>'⚠️ [HOUSEKEEPING] Kerusakan teknis/fisik kamar dilaporkan ke Engineering','reply'=>'⚠️ *KERUSAKAN / PERLU PERBAIKAN DILAPORKAN*','broadcast'=>'⚠️ *KERUSAKAN KAMAR DILAPORKAN KE ENGINEERING*']
                    ];
                    if (!$loggedInStaff || !hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']) || !isset($actionMap[$action]) || $roomNumber === '') {
                        $replyText = "⚠️ Permintaan Housekeeping tidak valid atau sesi telah berakhir.";
                        $alertText = "Permintaan ditolak";
                    } else {
                        if($action==='need_maintenance'){
                            $ctx=json_encode(['roomNumber'=>$roomNumber,'operationId'=>$telegramCallbackOperationId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_housekeeping_damage_detail',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                            $replyText="🛠 *JELASKAN KERUSAKAN*\n\n🚪 Kamar: *{$roomNumber}*\nKetik komponen yang rusak dan gejalanya, contoh: `Kran wastafel bocor pada sambungan bawah`.\n\nHousekeeping/kamar belum diblokir sampai detail valid dan tiket Engineering berhasil dibuat.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'hk_room:'.$roomNumber]]]];
                            $alertText='Ketik detail kerusakan';
                        } else try {
                            $pdo->beginTransaction();
                            claimTelegramMutation($pdo,$telegramCallbackOperationId,$loggedInStaff['id'],'rooms',$roomNumber,['action'=>$action,'room'=>$roomNumber],'callback');
                            $rule = $actionMap[$action];
                            $hkResult=applyHousekeepingAction($pdo,$loggedInStaff,$roomNumber,$action,'telegram_housekeeping');
                            $staffName = (string)$loggedInStaff['name'];
                            completeTelegramMutation($pdo,$telegramCallbackOperationId,['room'=>$roomNumber,'status'=>$hkResult['roomStatus'],'taskId'=>$hkResult['taskId']]);
                            tamasyaFinancialCommit($pdo);
                            // Kirim konfirmasi ke petugas terlebih dahulu. Broadcast ke staf lain
                            // diproses setelah edit pesan actor agar HP lapangan tidak menunggu fan-out Telegram.
                            $postCallbackBroadcasts[] = [$rule['broadcast']."

🚪 Kamar: *{$roomNumber}*
👤 Petugas: *{$staffName}*
📌 Status: *".$telegramRoomStatusLabel((string)$hkResult['roomStatus'])."*.",false];
                            $replyText = $rule['reply']."

Kamar *{$roomNumber}* diperbarui oleh *{$staffName}*. Status: *".$telegramRoomStatusLabel((string)$hkResult['roomStatus'])."*.";
                            $alertText = "Status tersimpan";
                        } catch (TelegramDuplicateOperationException $duplicate) {
                            if($pdo->inTransaction())$pdo->rollBack();
                            $replyText = "ℹ️ Tindakan Housekeeping ini sudah diproses sebelumnya. Database tidak diubah dua kali.";
                            $alertText = "Sudah diproses";
                        } catch (Throwable $housekeepingError) {
                            if($pdo->inTransaction())$pdo->rollBack();
                            failTelegramMutation($pdo,$telegramCallbackOperationId,$housekeepingError);
                            $replyText = "⚠️ *STATUS KAMAR TIDAK DIUBAH*

" . clientExceptionMessage('Sinkronisasi booking dan kamar menolak perubahan', $housekeepingError);
                            $alertText = "Status ditolak";
                        }
                        if($action!=='need_maintenance')$replyMarkup = ["inline_keyboard" => [[["text" => "⬅️ Kembali ke Housekeeping", "callback_data" => "housekeeping_menu"]]]];
                    }
                } elseif ($callbackData === "patrol_reports_menu") {
                    $replyText = "🚨 *PATROLI & LAPORAN LAPANGAN*\n\nSelamat datang di konsol petugas lapangan harian. Silakan pilih tindakan keamanan atau buat laporan insiden:";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [
                                ["text" => "🏃‍♂️ Mulai Patroli Keliling", "callback_data" => "patrol_action:start"]
                            ],
                            [
                                ["text" => "🛡️ Patroli Selesai (Area Aman)", "callback_data" => "patrol_action:finish_safe"]
                            ],
                            [
                                ["text" => "📢 Buat Laporan Insiden Baru", "callback_data" => "incident_report_init"]
                            ],
                            [
                                ["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]
                            ]
                        ]
                    ];
                    $alertText = "Patrol Menu";
                } elseif (strpos($callbackData, "patrol_action:") === 0) {
                    $action = trim((string)(explode(":", $callbackData, 2)[1] ?? ''));
                    if (!$loggedInStaff || !in_array($action,['start','finish_safe'],true)) {
                        $replyText = "🔒 Sesi atau tindakan patroli tidak valid.";
                        $alertText = "Akses ditolak";
                    } else {
                        try {
                            claimTelegramMutation($pdo,$telegramCallbackOperationId,$loggedInStaff['id'],'patrol',$action,['action'=>$action],'callback');
                            $staffName = (string)$loggedInStaff['name'];
                            $isStart = $action === 'start';
                            $notifMsg = $isStart
                                ? "🚨 [PATROLI] Petugas {$staffName} memulai patroli keamanan keliling area hotel."
                                : "🚨 [PATROLI] Petugas {$staffName} menyelesaikan patroli. Kondisi area hotel AMAN terkendali.";
                            $pdo->prepare("INSERT INTO notifications (id,type,message,timestamp,`read`) VALUES (?,'system',?,?,0)")
                                ->execute([generateServerId('pt_notif'),$notifMsg,date('Y-m-d H:i:s')]);
                            completeTelegramMutation($pdo,$telegramCallbackOperationId,['action'=>$action,'staffId'=>$loggedInStaff['id']]);
                            $postCallbackBroadcasts[] = [$isStart
                                ? "🚨 *PATROLI DIMULAI*

👤 Petugas: *{$staffName}*
🏃‍♂️ Patroli keamanan sedang berlangsung."
                                : "🛡️ *PATROLI SELESAI*

👤 Petugas: *{$staffName}*
✅ Kondisi area dilaporkan aman.",false];
                            $replyText = $isStart
                                ? "🏃‍♂️ *PATROLI DIMULAI*

Petugas *{$staffName}* sedang memantau area hotel."
                                : "🛡️ *PATROLI SELESAI*

Petugas *{$staffName}* melaporkan kondisi area aman.";
                            $alertText = "Patroli tersimpan";
                        } catch (TelegramDuplicateOperationException $duplicate) {
                            $replyText = "ℹ️ Tindakan patroli ini sudah tercatat; notifikasi database tidak digandakan.";
                            $alertText = "Sudah diproses";
                        } catch (Throwable $patrolError) {
                            failTelegramMutation($pdo,$telegramCallbackOperationId,$patrolError);
                            $replyText = "⚠️ Patroli gagal dicatat: " . clientExceptionMessage('Operasi database gagal',$patrolError);
                            $alertText = "Gagal menyimpan";
                        }
                        $replyMarkup = ["inline_keyboard" => [[["text" => "⬅️ Kembali ke Patroli", "callback_data" => "patrol_reports_menu"]]]];
                    }
                } elseif ($callbackData === "incident_report_init") {
                    if ($loggedInStaff) {
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_incident_text', telegram_context = 'none' WHERE id = ?");
                        $stmtSetState->execute([$loggedInStaff['id']]);
                        
                        $replyText = "📢 *LAPORAN SECURITY / SAFETY / OCCUPANCY*\n\n" .
                                     "Ketik kondisi lapangan secara bebas dan spesifik. Laporan ini menjadi incident domain, bukan sekadar notifikasi.\n\n" .
                                     "Gunakan Housekeeping/Maintenance untuk kerusakan teknis kamar, Lost & Found untuk barang tertinggal, dan Key Control untuk kunci hilang.\n\n" .
                                     "*Contoh*: 'Orang tidak dikenal memaksa masuk gudang' atau 'Asap terlihat dari panel listrik lobby'.";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_patrol_process"]]
                            ]
                        ];
                        $alertText = "Ketik Laporan Anda";
                    } else {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                    }
                } elseif ($callbackData === "cancel_patrol_process") {
                    if ($loggedInStaff) {
                        $stmtClearState = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        $stmtClearState->execute([$loggedInStaff['id']]);
                    }
                    $replyText = "❌ *Pembuatan laporan insiden dibatalkan.*";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "🚨 Menu Patroli & Laporan", "callback_data" => "patrol_reports_menu"]]
                        ]
                    ];
                    $alertText = "Dibatalkan";
                } elseif ($callbackData === "panjar_menu" || str_starts_with($callbackData,"panjar_menu:p:")) {
                    $page=str_starts_with($callbackData,"panjar_menu:p:")?max(1,(int)substr($callbackData,strlen("panjar_menu:p:"))):1;
                    $pageSize=30;$count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
                    if($count<=0){$replyText='ℹ️ Tidak ada booking aktif yang dapat menerima panjar.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];}
                    else{
                        $totalPages=max(1,(int)ceil($count/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;
                        $stmtActive=$pdo->prepare("SELECT id,roomNumber,guestName,isOpenEnded,totalAmount,amountPaid,downPaymentAmount FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber LIMIT ? OFFSET ?");
                        $stmtActive->bindValue(1,$pageSize,PDO::PARAM_INT);$stmtActive->bindValue(2,$offset,PDO::PARAM_INT);$stmtActive->execute();$active=$stmtActive->fetchAll(PDO::FETCH_ASSOC)?:[];
                        $buttons=[];foreach($active as $booking){$paid=(float)($booking['amountPaid']??$booking['downPaymentAmount']??0);$buttons[]=[["text"=>'🚪 '.$booking['roomNumber'].' · Rp '.tamasyaTelegramFormatAmount($paid),"callback_data"=>'dp_room:'.$booking['id']]];}
                        if($totalPages>1)$buttons[]=$buildTelegramPagerRow($page,$totalPages,'panjar_menu:p:');
                        $buttons[]=[["text"=>'⬅️ Menu Utama',"callback_data"=>'main_menu']];$replyMarkup=['inline_keyboard'=>$buttons];
                        $rangeStart=$offset+1;$rangeEnd=min($offset+$pageSize,$count);
                        $replyText="💵 *TERIMA PANJAR BERULANG*

Pilih kamar. Setiap pembayaran disimpan sebagai ledger terpisah dan tidak menimpa pembayaran sebelumnya.
📄 Halaman *{$page}/{$totalPages}* · booking aktif *{$rangeStart}-{$rangeEnd}* dari *{$count}*.";
                    }
                    $alertText='Pilih kamar';
                } elseif (strpos($callbackData, "dp_room:") === 0) {
                    $bookingId=trim((string)(explode(':',$callbackData,2)[1]??''));
                    $stmtDpBooking=$pdo->prepare("SELECT id,roomNumber,guestName,status,isOpenEnded,totalAmount,amountPaid,downPaymentAmount FROM bookings WHERE id=? LIMIT 1");
                    $stmtDpBooking->execute([$bookingId]);$dpBooking=$stmtDpBooking->fetch(PDO::FETCH_ASSOC);
                    if(!$loggedInStaff || !$dpBooking || (string)$dpBooking['status']!=='active'){
                        $replyText='⚠️ Booking aktif tidak ditemukan atau akses ditolak.';$alertText='Booking tidak tersedia';
                    } else {
                        $context=['bookingId'=>$bookingId,'roomNumber'=>$dpBooking['roomNumber'],'guestName'=>$dpBooking['guestName']];
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_dp_amount',telegram_context=? WHERE id=?")
                            ->execute([json_encode($context),$loggedInStaff['id']]);
                        $paid=(float)($dpBooking['amountPaid']??$dpBooking['downPaymentAmount']??0);
                        $openText=(int)($dpBooking['isOpenEnded']??0)===1?'Durasi terbuka; panjar dapat ditambah berkali-kali.':'Sisa saat ini: Rp '.tamasyaTelegramFormatAmount(max(0,(float)$dpBooking['totalAmount']-$paid));
                        $replyText="💵 *PANJAR KAMAR {$dpBooking['roomNumber']}*

👤 {$dpBooking['guestName']}
🧾 Total diterima: *Rp ".tamasyaTelegramFormatAmount($paid)."*
{$openText}

Ketik nominal panjar baru, misalnya `300000`.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'main_menu']]]];$alertText='Masukkan nominal';
                    }
                } elseif (strpos($callbackData, "dp_method:") === 0) {
                    $method=trim((string)(explode(':',$callbackData,2)[1]??''));
                    $ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                    if(!is_array($ctx)||empty($ctx['bookingId'])||empty($ctx['amount'])||!in_array($method,['cash','transfer','qris'],true)){
                        $replyText='⚠️ Sesi panjar tidak valid. Mulai ulang dari menu Terima Panjar.';$alertText='Sesi tidak valid';
                    } elseif($method==='cash') {
                        try{
                            $operationId='tg_dp_'.substr(hash('sha256',(string)$fromId.'|'.(string)$ctx['bookingId'].'|'.(string)$ctx['requestUpdateId']),0,60);
                            $pdo->beginTransaction();
                            $result=recordBookingDeposit($pdo,$loggedInStaff,(string)$ctx['bookingId'],['amount'=>$ctx['amount'],'paymentMethod'=>'cash','operationId'=>$operationId,'date'=>date('Y-m-d')],'telegram');
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                            tamasyaFinancialCommit($pdo);
                            if(empty($result['duplicate'])&&!empty($result['telegramMessage']))$postCallbackBroadcasts[]=[(string)$result['telegramMessage'],false];
                            $replyText=!empty($result['duplicate'])?'ℹ️ Panjar ini sudah tersimpan sebelumnya; tidak dibuat ganda.':'✅ Panjar tunai berhasil disimpan dan masuk ke shift kas aktif.';$alertText='Panjar tersimpan';
                        }catch(Throwable $dpError){if($pdo->inTransaction())$pdo->rollBack();$replyText='⚠️ '.clientExceptionMessage('Panjar ditolak',$dpError);$alertText='Gagal menyimpan';}
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    } else {
                        $type=$method==='qris'?'edc_qris':'bank';
                        $stmtAccounts=$pdo->prepare("SELECT id,name,accountNumber FROM bank_accounts WHERE isActive=1 AND type=? ORDER BY name LIMIT 12");$stmtAccounts->execute([$type]);$accounts=$stmtAccounts->fetchAll(PDO::FETCH_ASSOC)?:[];
                        if(!$accounts){$replyText='⚠️ Tidak ada akun '.strtoupper($method).' aktif. Atur rekening terlebih dahulu.';$alertText='Akun tidak tersedia';}
                        else{
                            $ctx['paymentMethod']=$method;$pdo->prepare("UPDATE staff SET telegram_state='waiting_for_dp_account',telegram_context=? WHERE id=?")->execute([json_encode($ctx),$loggedInStaff['id']]);
                            $buttons=[];foreach($accounts as $account)$buttons[]=[["text"=>substr((string)$account['name'],0,48),"callback_data"=>'dp_account:'.$method.':'.$account['id']]];
                            $buttons[]=[["text"=>'❌ Batalkan',"callback_data"=>'main_menu']];$replyMarkup=['inline_keyboard'=>$buttons];
                            $replyText='🏦 *PILIH AKUN '.strtoupper($method)."*

Nominal: *Rp ".tamasyaTelegramFormatAmount((float)$ctx['amount']).'*';$alertText='Pilih akun';
                        }
                    }
                } elseif (strpos($callbackData, "dp_account:") === 0) {
                    $parts=explode(':',$callbackData,3);$method=$parts[1]??'';$accountId=$parts[2]??'';$ctx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                    if(!is_array($ctx)||empty($ctx['bookingId'])||empty($ctx['amount'])||!in_array($method,['transfer','qris'],true)||$accountId===''){
                        $replyText='⚠️ Sesi rekening panjar tidak valid.';$alertText='Sesi tidak valid';
                    } else {
                        try{
                            $operationId='tg_dp_'.substr(hash('sha256',(string)$fromId.'|'.(string)$ctx['bookingId'].'|'.(string)$ctx['requestUpdateId']),0,60);
                            $pdo->beginTransaction();
                            $result=recordBookingDeposit($pdo,$loggedInStaff,(string)$ctx['bookingId'],['amount'=>$ctx['amount'],'paymentMethod'=>$method,'bankAccountId'=>$accountId,'operationId'=>$operationId,'date'=>date('Y-m-d')],'telegram');
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);tamasyaFinancialCommit($pdo);
                            if(empty($result['duplicate'])&&!empty($result['telegramMessage']))$postCallbackBroadcasts[]=[(string)$result['telegramMessage'],false];
                            $replyText=!empty($result['duplicate'])?'ℹ️ Panjar ini sudah tersimpan sebelumnya; tidak dibuat ganda.':'✅ Panjar '.strtoupper($method).' berhasil disimpan ke akun yang dipilih.';$alertText='Panjar tersimpan';
                        }catch(Throwable $dpError){if($pdo->inTransaction())$pdo->rollBack();$replyText='⚠️ '.clientExceptionMessage('Panjar ditolak',$dpError);$alertText='Gagal menyimpan';}
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    }
                } elseif ($callbackData === "buka_shift_menu") {
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Sesi Berakhir";
                    } elseif (!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])) {
                        $replyText = "🔒 Hanya Admin, Manajer, Finance, atau Resepsionis yang dapat membuka shift kas.";
                        $alertText = "Akses ditolak";
                    } elseif ($existingOpenShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'])) {
                        $replyText = "ℹ️ Anda sudah terikat pada shift terbuka *".getShiftDisplayName($existingOpenShift)."* (".strtoupper((string)$existingOpenShift['shift_time'])."). Tutup shift tersebut sebelum membuka shift baru.";
                        $replyMarkup = ["inline_keyboard" => [[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        $alertText = "Shift sudah terbuka";
                    } else {
                        $replyText = "🔑 *BUKA SHIFT & PENYIAPAN KAS AWAL*

Pilih jadwal shift yang benar. Sistem tidak lagi menetapkan shift pagi secara otomatis.";
                        $replyMarkup = ["inline_keyboard" => [
                            [["text" => "🌅 Shift Pagi (07:00 - 15:00)", "callback_data" => "buka_shift_time:pagi"]],
                            [["text" => "☀️ Shift Siang (15:00 - 23:00)", "callback_data" => "buka_shift_time:siang"]],
                            [["text" => "🌙 Shift Malam (23:00 - 07:00)", "callback_data" => "buka_shift_time:malam"]],
                            [["text" => "📅 Satu Hari Penuh (24 Jam)", "callback_data" => "buka_shift_time:all"]],
                            [["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]
                        ]];
                        $alertText = "Pilih jadwal shift";
                    }
                } elseif ($callbackData === "tutup_shift_menu") {
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Sesi Berakhir";
                    } else {
                        $menu=$buildTelegramShiftCloseMenu($pdo,$loggedInStaff);
                        $replyText=$menu['text'];$replyMarkup=$menu['markup'];$alertText='Pilih shift';
                    }
                } elseif (strpos($callbackData, "buka_shift_time:") === 0) {
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Sesi Berakhir";
                    } elseif (!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])) {
                        $replyText = "🔒 Hanya Admin, Manajer, Finance, atau Resepsionis yang dapat membuka shift kas.";
                        $alertText = "Akses ditolak";
                    } elseif ($existingOpenShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'])) {
                        $replyText = "ℹ️ Anda sudah terikat pada shift terbuka *".getShiftDisplayName($existingOpenShift)."* (".strtoupper((string)$existingOpenShift['shift_time'])."). Tutup shift tersebut sebelum membuka shift baru.";
                        $replyMarkup = ["inline_keyboard" => [[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        $alertText = "Shift sudah terbuka";
                    } else {
                        $shiftTime = trim((string)(explode(":", $callbackData,2)[1]??''));
                        if(!in_array($shiftTime,['pagi','siang','malam','all'],true)){
                            $replyText='⚠️ Jadwal shift tidak valid.';$alertText='Jadwal tidak valid';
                        } else {
                            $contextData = ['shiftTime'=>$shiftTime,'shiftDate'=>date('Y-m-d'),'companionStaffId'=>null,'companionStaffName'=>null];
                            $stmtCandidates=$pdo->prepare("SELECT s.id,s.name,s.username FROM staff s WHERE s.status='active' AND s.role='receptionist' AND s.id<>? AND NOT EXISTS (SELECT 1 FROM shift_sessions sh WHERE sh.status='open' AND (sh.staff_id=s.id OR sh.companion_staff_id=s.id)) ORDER BY s.name ASC LIMIT 12");
                            $stmtCandidates->execute([(string)$loggedInStaff['id']]);
                            $candidates=$stmtCandidates->fetchAll(PDO::FETCH_ASSOC)?:[];
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_buka_shift_companion',telegram_context=? WHERE id=?")
                                ->execute([json_encode($contextData),$loggedInStaff['id']]);
                            $buttons=[[['text'=>'👤 Tanpa pendamping (jaga sendiri)','callback_data'=>'buka_shift_companion:none']]];
                            foreach($candidates as $candidate){
                                $buttons[]=[["text"=>'👥 '.substr((string)$candidate['name'],0,45),"callback_data"=>'buka_shift_companion:'.$candidate['id']]];
                            }
                            $buttons[]=[["text"=>'❌ Batalkan',"callback_data"=>'cancel_buka_shift']];
                            $shiftLabel=$shiftTime==='pagi'?'Pagi (07:00–15:00)':($shiftTime==='siang'?'Siang (15:00–23:00)':($shiftTime==='malam'?'Malam (23:00–07:00)':'Satu Hari Penuh'));
                            $replyText="👥 *PILIH PENDAMPING SHIFT*

⏰ Jadwal: *{$shiftLabel}*

Jika lobi dijaga sendiri pilih *Tanpa pendamping*. Jika dua resepsionis memakai satu laci kas, pilih rekan pendamping sejak shift dibuka.";
                            $replyMarkup=['inline_keyboard'=>$buttons];
                            $alertText='Pilih pendamping';
                        }
                    }
                } elseif (strpos($callbackData, "buka_shift_companion:") === 0) {
                    if(!$loggedInStaff){$replyText='🔒 Sesi berakhir.';$alertText='Sesi Berakhir';}
                    else{
                        $context=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        if(!is_array($context) || empty($context['shiftTime'])){
                            $replyText='⚠️ Data buka shift tidak ditemukan. Mulai ulang dari /buka_shift.';$alertText='Sesi tidak ditemukan';
                        } else {
                            $selected=trim((string)(explode(':',$callbackData,2)[1]??'none'));
                            $companionId=$selected==='none'?'':$selected;
                            try{
                                $companion=validateOptionalShiftCompanion($pdo,(string)$loggedInStaff['id'],$companionId,false);
                                $context['companionStaffId']=$companion['id']??null;
                                $context['companionStaffName']=$companion['name']??null;
                                $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_buka_shift_cash',telegram_context=? WHERE id=?")
                                    ->execute([json_encode($context),$loggedInStaff['id']]);
                                $partnerText=$companion?'👥 Pendamping: *'.$companion['name'].'*':'👤 Pendamping: *Tidak ada*';
                                $replyText="💵 *MASUKKAN KAS AWAL SHIFT*

{$partnerText}
📅 Tanggal: *{$context['shiftDate']}*
⏰ Shift: *".strtoupper((string)$context['shiftTime'])."*

Ketik nominal kas awal laci, misalnya `500000` atau `0`.";
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_buka_shift']]]];
                                $alertText='Masukkan kas awal';
                            }catch(Throwable $companionError){
                                $replyText='⚠️ '.clientExceptionMessage('Pendamping tidak dapat dipilih',$companionError);
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Pilih ulang jadwal','callback_data'=>'buka_shift_time:'.($context['shiftTime']??'pagi')],['text'=>'❌ Batalkan','callback_data'=>'cancel_buka_shift']]]];
                                $alertText='Pendamping tidak tersedia';
                            }
                        }
                    }
                } elseif ($callbackData === "cancel_buka_shift") {
                    if ($loggedInStaff) {
                        $stmtClearState = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        $stmtClearState->execute([$loggedInStaff['id']]);
                    }
                    $replyText = "❌ *Buka Shift & Pengisian Modal Kas dibatalkan.*";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]
                        ]
                    ];
                    $alertText = "Dibatalkan";
                } elseif (strpos($callbackData, "tutup_shift_select:") === 0) {
                    if(!$loggedInStaff){$replyText='🔒 Sesi Anda telah berakhir.';$alertText='Sesi Berakhir';}
                    elseif(!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])){$replyText='🔒 Akun ini tidak memiliki izin menutup shift kas.';$alertText='Akses ditolak';}
                    else{
                        $shiftId=trim((string)(explode(':',$callbackData,2)[1]??''));$role=strtolower((string)($loggedInStaff['role']??''));
                        $stmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1");$stmt->execute([$shiftId]);$openShift=$stmt->fetch(PDO::FETCH_ASSOC);
                        if(!$openShift){$replyText='⚠️ Shift tersebut sudah tidak terbuka. Muat ulang daftar shift.';$replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Muat Ulang','callback_data'=>'tutup_shift_menu']]]];$alertText='Shift berubah';}
                        else{
                            $participantIds=getShiftParticipantIds($openShift);
                            if($role==='receptionist'&&!in_array((string)$loggedInStaff['id'],$participantIds,true)){
                                $replyText='🔒 Resepsionis hanya boleh menutup shift yang diikutinya.';$alertText='Akses ditolak';
                            }elseif(!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])){
                                $replyText='🔒 Peran akun tidak diizinkan menutup shift ini.';$alertText='Akses ditolak';
                            }else{
                                $selection=['flow'=>'shift_close_select','selectedShiftId'=>$shiftId];
                                $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=? WHERE id=?")->execute([json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                                $actualShiftTime=(string)($openShift['shift_time']??'all');
                                $replyText="📝 *SHIFT DIPILIH*

👥 Petugas: *".getShiftDisplayName($openShift)."*
📅 Tanggal: *".(string)$openShift['shift_date']."*
⏰ Jadwal: *".strtoupper($actualShiftTime)."*

Lanjutkan rekonsiliasi menggunakan sesi database ini.";
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'💵 Lanjut Rekonsiliasi','callback_data'=>'tutup_shift_time:'.$actualShiftTime]],[['text'=>'⬅️ Pilih Shift Lain','callback_data'=>'tutup_shift_menu']]]];$alertText='Shift dipilih';
                            }
                        }
                    }
                } elseif (strpos($callbackData, "tutup_shift_time:") === 0 || strpos($callbackData, "tutup_shift_companion:") === 0) {
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Sesi Berakhir";
                    } elseif (!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])) {
                        $replyText = "🔒 Hanya Admin, Manajer, Finance, atau Resepsionis yang dapat menutup shift kas.";
                        $alertText = "Akses ditolak";
                    } else {
                        $selectionCtx=json_decode((string)($loggedInStaff['telegram_context']??''),true);
                        $selectedShiftId=is_array($selectionCtx)&&($selectionCtx['flow']??'')==='shift_close_select'?trim((string)($selectionCtx['selectedShiftId']??'')):'';
                        $role=strtolower((string)($loggedInStaff['role']??''));$openShift=null;
                        if($selectedShiftId!==''&&(tamasyaCanOperateShift($loggedInStaff)||hasCapability($loggedInStaff,'receive_payment_access',[]))){
                            $stmtSelected=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1");$stmtSelected->execute([$selectedShiftId]);$candidate=$stmtSelected->fetch(PDO::FETCH_ASSOC);
                            if($candidate){
                                $participants=getShiftParticipantIds($candidate);
                                if($role!=='receptionist'||in_array((string)$loggedInStaff['id'],$participants,true))$openShift=$candidate;
                            }
                        }
                        if(!$openShift)$openShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id']);
                        if(!$openShift){
                            $replyText='⚠️ Tidak ada shift terbuka yang terikat pada akun Anda.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                            $alertText='Shift tidak ditemukan';
                        } else {
                            $shiftTime=(string)($openShift['shift_time']??'all');
                            $shiftDate=(string)($openShift['shift_date']??date('Y-m-d'));
                            $requestedShiftTime=str_starts_with($callbackData,'tutup_shift_time:') ? trim((string)(explode(':',$callbackData,2)[1]??'')) : $shiftTime;
                            if($requestedShiftTime!=='' && $requestedShiftTime!==$shiftTime){
                                $replyText="⚠️ Tombol shift lama tidak cocok dengan sesi aktif. Sesi Anda adalah *".strtoupper($shiftTime)."*. Buka ulang menu Tutup Shift.";
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Muat Shift Aktif','callback_data'=>'tutup_shift_menu'],['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                                $alertText='Jadwal tidak cocok';
                            } else {
                                $companionId=trim((string)($openShift['companion_staff_id']??''))?:'none';
                                try {
                                    $financials=getShiftFinancials($pdo,$loggedInStaff,$shiftTime,$shiftDate,$companionId,true,(string)$openShift['id']);
                                    $contextData=[
                                        'shiftId'=>(string)$openShift['id'],'shiftTime'=>$shiftTime,'shiftDate'=>$shiftDate,
                                        'startingCash'=>$financials['startingCash'],'expectedCash'=>$financials['expectedCash'],
                                        'txCount'=>$financials['txCount'],'digitalRevenue'=>$financials['digitalInc'],
                                        'companionId'=>$companionId,'companionName'=>$openShift['companion_staff_name']??null
                                    ];
                                    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_tutup_shift_cash',telegram_context=? WHERE id=?")
                                        ->execute([json_encode($contextData),$loggedInStaff['id']]);
                                    $companionText=!empty($openShift['companion_staff_name'])?'👥 Pendamping: *'.$openShift['companion_staff_name'].'*':'👤 Pendamping: *Tidak ada*';
                                    $replyText="💵 *REKONSILIASI SHIFT BERSAMA*

📅 Tanggal: *{$shiftDate}*
⏰ Jadwal: *".strtoupper($shiftTime)."*
👤 Petugas utama: *{$openShift['staff_name']}*
{$companionText}
📈 Transaksi: *{$financials['txCount']}*
💳 Digital/QRIS: *Rp ".tamasyaTelegramFormatAmount($financials['digitalInc'])."*
📊 Tunai seharusnya: *Rp ".tamasyaTelegramFormatAmount($financials['expectedCash'])."*

Ketik uang fisik aktual di laci.";
                                    $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_tutup_shift']]]];
                                    $alertText='Masukkan kas aktual';
                                } catch (Throwable $financialError) {
                                    $replyText="⚠️ *REKONSILIASI TIDAK DAPAT DIMULAI*

Perhitungan transaksi shift gagal. Sistem tidak menggunakan angka Rp0 sebagai pengganti. Tidak ada shift yang ditutup.

".clientExceptionMessage('Periksa sinkronisasi database',$financialError);
                                    $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Coba Lagi','callback_data'=>'tutup_shift_menu'],['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                                    $alertText='Perhitungan gagal';
                                }
                            }
                        }
                    }
                } elseif ($callbackData === "cancel_tutup_shift") {
                    if ($loggedInStaff) {
                        $stmtClearState = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        $stmtClearState->execute([$loggedInStaff['id']]);
                    }
                    $replyText = "❌ *Tutup Shift & Rekonsiliasi Kas dibatalkan.*";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]
                        ]
                    ];
                    $alertText = "Dibatalkan";
                } elseif (strpos($callbackData, "tutup_shift_done:") === 0) {
                    if (!$loggedInStaff) {
                        $replyText = "🔒 Sesi Anda telah berakhir. Silakan login kembali.";
                        $alertText = "Sesi Berakhir";
                    } else {
                        $context = json_decode((string)($loggedInStaff['telegram_context'] ?? ''),true);
                        if (!is_array($context)) {
                            $replyText = "⚠️ Data sesi tutup shift tidak ditemukan; database tidak diubah.";
                            $alertText = "Data sesi tidak ada";
                        } else {
                            try {
                                $result = finalizeTelegramShiftReport($pdo,$telegramCallbackOperationId,$loggedInStaff,$context,'','callback');
                                $postCallbackBroadcasts[]=[(string)$result['broadcastText'],true,'committed_shift'];
                                $replyText = "✅ *LAPORAN SHIFT TERSIMPAN*

Shift telah ditutup, sesi server berstatus *CLOSED*, dan rekonsiliasi kas tersimpan satu kali. Selisih: *{$result['varianceText']}*.".(!empty($result['needsReview'])?"\n\n🔎 Selisih menunggu pemeriksaan Admin/Manager di web.":"");
                                $alertText = "Shift ditutup";
                            } catch (TelegramDuplicateOperationException $duplicate) {
                                $replyText = "ℹ️ Penutupan shift ini sudah diproses sebelumnya; laporan dan sesi tidak digandakan.";
                                $alertText = "Sudah diproses";
                            } catch (Throwable $shiftError) {
                                $replyText = "⚠️ *SHIFT BELUM DITUTUP*

" . clientExceptionMessage('Sinkronisasi database gagal',$shiftError);
                                $alertText = "Gagal menyimpan";
                            }
                        }
                        $replyMarkup = ["inline_keyboard" => [[["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]]];
                    }
                } elseif ($callbackData === "main_menu") {
                    if($loggedInStaff){
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    }
                    // Header nama properti berasal dari konfigurasi dan boleh mengandung
                    // karakter Markdown (mis. underscore). Menu Utama tidak perlu parse_mode.
                    $replyText = "🤖 {$propertyName} Bot\n\nPilih menu dengan tombol. Perintah /... tetap tersedia sebagai shortcut/fallback.";
                    $replyMarkup = $buildTelegramMainMenuMarkup($loggedInStaff ?: null);
                    $alertText = "Menu Utama";
                }
                
                // Telegram membatasi callback_data hingga 64 byte. Payload booking
                // panjang ditokenisasi setelah otorisasi/handler selesai dan tokennya
                // direplikasi ke standby bersama state Telegram staf.
                $replyMarkup = tamasyaTelegramCompactReplyMarkup($pdo, $loggedInStaff ?: null, $replyMarkup);

                // answerCallbackQuery bersifat best-effort karena callback dapat kedaluwarsa,
                // tetapi edit pesan wajib berhasil agar pengguna tidak melihat sukses palsu.
                if (empty($telegramCallbackEarlyAcked)) {
                    telegramApiCall($token,'answerCallbackQuery', [
                        "callback_query_id" => $callbackQueryId,
                        "text" => $alertText
                    ]);
                }

                $telegramCallbackEditStartedAt = microtime(true);
                try {
                    if (!empty($replyText)) {
                        $editPayload = [
                            "chat_id" => $chatId,
                            "message_id" => $messageId,
                            "text" => $replyText,
                            "parse_mode" => "Markdown"
                        ];
                        // Menu Utama hanya teks + keyboard; jangan parse nama hotel
                        // sebagai Markdown. Semua callback lain tetap dilindungi fallback
                        // format di lapisan Telegram tanpa mengulang mutasi hotel.
                        if ($callbackData === 'main_menu') unset($editPayload['parse_mode']);
                        if ($replyMarkup) $editPayload["reply_markup"] = $replyMarkup;
                        telegramApiCallRequired($token,'editMessageText',$editPayload,true);
                    }
                } finally {
                    // Untuk operasi lapangan seperti Housekeeping, actor menerima hasil
                    // lebih dulu. Broadcast tetap dijalankan walau edit actor gagal.
                    foreach ($postCallbackBroadcasts as $queuedBroadcast) {
                        try{broadcastTelegramNotification($pdo,(string)($queuedBroadcast[0]??''),!empty($queuedBroadcast[1]));}
                        catch(Throwable $deliveryError){
                            if(!in_array($queuedBroadcast[2]??'',['committed_shift','committed_booking'],true))throw $deliveryError;
                            error_log(clientExceptionMessage('[Telegram] Operasi sudah tersimpan; broadcast gagal',$deliveryError));
                        }
                    }
                }
                $GLOBALS['tamasya_telegram_latency_edit_ms']=(int)round((microtime(true)-$telegramCallbackEditStartedAt)*1000);
                $GLOBALS['tamasya_telegram_latency_callback_ms']=(int)round((microtime(true)-$telegramCallbackStartedAt)*1000);
                
                if (!empty($GLOBALS['is_telegram_simulation'])) {
                    $botMsgId = generateServerId('tg_sim_reply');
                    $stmtBotLog = $pdo->prepare("INSERT INTO telegram_messages (id, sender, `text`, timestamp, isAi) VALUES (?, 'bot', ?, ?, 0)");
                    $stmtBotLog->execute([$botMsgId, $replyText, date("Y-m-d H:i:s")]);

                    echo json_encode([
                        "success" => true,
                        "message" => [
                            "id" => $botMsgId,
                            "sender" => "bot",
                            "text" => $replyText,
                            "timestamp" => date("c"),
                            "isAi" => false,
                            "replyMarkup" => $replyMarkup
                        ],
                        "simulationIdentity" => [
                            "telegramUserId" => (string)$fromId,
                            "bound" => (bool)$loggedInStaff,
                            "staffId" => $loggedInStaff['id'] ?? null,
                            "staffName" => $loggedInStaff['name'] ?? null,
                            "role" => $loggedInStaff['role'] ?? null
                        ],
                        "db" => tamasyaTelegramSimulationHotelData($pdo, $loggedInStaff)
                    ]);
                    break;
                }

                echo json_encode(["success" => true]);
                break;
            }

            // Extract chat ID and message text safely
            $chatId = null;
            if (isset($update['message']['chat']['id'])) {
                $chatId = $update['message']['chat']['id'];
            } elseif (isset($update['my_chat_member']['chat']['id'])) {
                $chatId = $update['my_chat_member']['chat']['id'];
            } elseif (isset($update['chat_member']['chat']['id'])) {
                $chatId = $update['chat_member']['chat']['id'];
            }

            if (!$chatId) {
                echo json_encode(["success" => true, "status" => "No chat ID found"]);
                break;
            }

            // Handle bot added to group chat (my_chat_member / chat_member)
            if ($isMyChatMember || $isChatMember) {
                $newStatus = '';
                if ($isMyChatMember && isset($update['my_chat_member']['new_chat_member']['status'])) {
                    $newStatus = $update['my_chat_member']['new_chat_member']['status'];
                } elseif ($isChatMember && isset($update['chat_member']['new_chat_member']['status'])) {
                    $newStatus = $update['chat_member']['new_chat_member']['status'];
                }
                
                if ($newStatus === 'member' || $newStatus === 'administrator') {
                    $welcomeText = "🔒 *BOT BELUM MENGAKTIFKAN GRUP INI*

Demi keamanan data hotel, grup harus ditambahkan oleh Administrator melalui menu konfigurasi aplikasi. Chat ID grup: `{$chatId}`.";
                    $welcomeDelivery = tamasyaTelegramApiCallWithFormatRecovery($token, 'sendMessage', ["chat_id" => $chatId, "text" => $welcomeText, "parse_mode" => "Markdown"]);
                    if (empty($welcomeDelivery['ok'])) {
                        error_log('[Telegram Group Welcome] deliver gagal: '.telegramApiErrorMessage($welcomeDelivery));
                    }
                }
                echo json_encode(["success" => true, "status" => "Processed member update"]);
                break;
            }

            $text = isset($update['message']['text']) ? trim($update['message']['text']) : '';
            $photoMsg = null;
            if (isset($update['message']['photo'])) {
                $photoMsg = $update['message']['photo'];
                if (empty($text)) {
                    $text = '[Foto KTP]';
                }
            }
            $telegramUpdateOperationId = 'tgupd_' . substr(hash('sha256', implode('|', [
                (string)($update['update_id'] ?? ''), (string)$fromId, (string)$chatId,
                (string)($update['message']['message_id'] ?? ''), (string)$text
            ])), 0, 64);

            // Chat ID tidak otomatis berlangganan. Hanya staff terverifikasi atau grup yang disetujui admin yang menerima notifikasi.

            // 2. Log user message safely. Passwords typed after the inline login
            // prompt are plain text messages, so detect the pending state before logging.
            $isPendingPasswordInput = false;
            $isInlineLoginCommand = (bool)preg_match('/^\/login(?:\s+.*)?$/i', $text);
            $isBindingCommand = (bool)preg_match('/^\/bind(?:\s+.*)?$/i', $text);
            $safeLogText = ($isPendingPasswordInput || $isInlineLoginCommand || $isBindingCommand)
                ? ( $isBindingCommand ? '/bind [REDACTED]' : '/login [REDACTED]' )
                : $text;
            $userMsgId = generateServerId('tg_real');
            $stmtUserLog = $pdo->prepare("INSERT INTO telegram_messages (id, sender, `text`, timestamp, isAi, telegram_user_id, command) VALUES (?, 'user', ?, ?, 0, ?, ?)");
            $stmtUserLog->execute([$userMsgId, $safeLogText, date("Y-m-d H:i:s"), (string)$fromId, substr($safeLogText,0,100)]);

            // Remove password-bearing messages from the Telegram chat when possible.
            if (($isPendingPasswordInput || $isInlineLoginCommand) && empty($GLOBALS['is_telegram_simulation']) && !empty($update['message']['message_id'])) {
                // This delete removes a password-bearing message from the chat, so a
                // silent failure leaves the credential on screen. Log it when it fails.
                $deleteDelivery = telegramApiCall($token,'deleteMessage',["chat_id" => $chatId, "message_id" => $update['message']['message_id']]);
                if (empty($deleteDelivery['ok'])) {
                    error_log('[Telegram Secret Cleanup] deleteMessage gagal: '.telegramApiErrorMessage($deleteDelivery));
                }
            }

            // 3. Process reply text exactly like simulator but with database writes
            $replyText = "";
            $isAi = 0;

            // Check if this Telegram User ID is linked to an active staff account.
            $loggedInStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
            if ($loggedInStaff) {
                $pdo->prepare("UPDATE telegram_messages SET staff_id=? WHERE id=?")->execute([(string)$loggedInStaff['id'],$userMsgId]);
            }

            // Normalize custom keyboard button text to standard commands
            $command = trim($text);
            if ($command === "🏠 Menu Utama" || strtolower($command) === "menu utama" || strtolower($command) === "menu") {
                $command = "/menu";
            } elseif ($command === "🗓️ Cuti Saya" || strtolower($command) === "cuti saya" || strtolower($command) === "cuti") {
                $command = "/cuti";
            } elseif ($command === "📊 Cek Kamar" || strtolower($command) === "cek kamar") {
                $command = "/status_kamar";
            } elseif ($command === "📅 Reservasi" || strtolower($command) === "reservasi") {
                $command = "/reservasi";
            } elseif ($command === "🛒 Jual Kamar" || $command === "🛒 Jual Kamar..." || strtolower($command) === "jual kamar") {
                $command = "/jual_kamar";
            } elseif ($command === "📈 Laporan Kas" || strtolower($command) === "laporan") {
                $command = "/laporan";
            } elseif ($command === "📋 Riwayat Shift" || strtolower($command) === "riwayat shift") {
                $command = "/riwayat_shift";
            } elseif (in_array($command,["🤖 Tanya AI","🤖 Bantuan AI","Bantuan AI","Tanya AI"],true) || in_array(strtolower($command),["help","tanya ai","bantuan ai"],true)) {
                $command = "/ai_help";
            } elseif ($command === "🧹 Housekeeping" || strtolower($command) === "housekeeping" || $command === "🧹 Housekeeping...") {
                $command = "/housekeeping";
            } elseif ($command === "🚨 Patroli & Laporan" || $command === "patroli" || $command === "🚨 Patrol & Lapor...") {
                $command = "/patroli_laporan";
            } elseif ($command === "🔑 Buka Shift" || $command === "🔑 Buka Shift..." || strtolower($command) === "buka shift" || strtolower($command) === "buka_shift") {
                $command = "/buka_shift";
            } elseif ($command === "📝 Tutup Shift" || $command === "📝 Kirim Laporan" || strtolower($command) === "tutup shift" || strtolower($command) === "tutup_shift") {
                $command = "/tutup_shift";
            } elseif ($command === "⏳ Perpanjang Sewa" || $command === "⏳ Perpanjang Sewa..." || strtolower($command) === "perpanjang") {
                $command = "/perpanjang";
            } elseif ($command === "🛠️ Tambah Layanan" || $command === "🛠️ Tambah Layanan..." || strtolower($command) === "layanan") {
                $command = "/layanan";
            } elseif ($command === "🔄 Pindah Kamar" || $command === "🔄 Pindah Kamar..." || strtolower($command) === "pindah") {
                $command = "/pindah";
            } elseif ($command === "🚪 Check-out Kamar" || $command === "🚪 Check-out Kamar..." || strtolower($command) === "checkout kamar" || strtolower($command) === "checkout") {
                $command = "/checkout";
            } elseif ($command === "🚪 Kamar Kosong" || strtolower($command) === "kamar kosong" || strtolower($command) === "laporkan kamar kosong") {
                $command = "/kamar_kosong";
            } elseif ($command === "🧾 Cetak Nota" || $command === "🧾 Cetak Nota..." || strtolower($command) === "cetak nota" || strtolower($command) === "nota" || strtolower($command) === "invoice") {
                $command = "/cetak_nota";
            } elseif ($command === "🪪 Lihat KTP" || strtolower($command) === "lihat ktp") {
                $command = "/ktp";
            } elseif ($command === "💵 Terima Panjar" || strtolower($command) === "terima panjar" || strtolower($command) === "panjar") {
                $command = "/panjar";
            } elseif (in_array($command, ["🔑 Login Admin", "🔗 Hubungkan Akun"], true) || in_array(strtolower($command), ["login admin", "hubungkan akun"], true)) {
                $replyText = "🔗 *HUBUNGKAN AKUN DENGAN AMAN*\n\n" .
                             "Telegram User ID Anda: `{$fromId}`\n\n" .
                             "Buat kode binding dari menu Karyawan/Pusat Operasional, lalu kirim `/bind KODE` ke bot dalam 10 menit. Password tidak pernah diminta melalui Telegram.";
                $replyMarkup = null;
            } elseif ($command === "🛡️ Consistency Guard" || strtolower($command) === "integritas" || strtolower($command) === "consistency guard") {
                $command = "/integritas";
            } elseif ($command === "🔒 Logout Admin" || $command === "🔒 Logout" || strtolower($command) === "logout") {
                $command = "/logout";
            }

            // Chat grup hanya menerima status agregat. Binding, AI, data tamu,
            // keuangan, dan seluruh mutasi database wajib melalui chat privat bot.
            if (!$isPrivateTelegramChat) {
                if (strpos($command, '/status_kamar') === 0) {
                    $stmtGroupRooms = $pdo->query("SELECT number FROM rooms ORDER BY CAST(number AS UNSIGNED),number");
                    $groupRoomNumbers=$stmtGroupRooms?($stmtGroupRooms->fetchAll(PDO::FETCH_COLUMN)?:[]):[];
                    $groupStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_map('strval',$groupRoomNumbers));
                    $groupCounts = ['available'=>0,'booked'=>0,'dirty'=>0,'maintenance'=>0];
                    foreach ($groupRoomNumbers as $number) {
                        $key=(string)($groupStatusMap[(string)$number]??'maintenance');
                        if (array_key_exists($key,$groupCounts)) $groupCounts[$key]++;
                    }
                    $replyText = "📊 *KETERSEDIAAN KAMAR (AGREGAT)*:

" .
                                 "🟢 Tersedia: *{$groupCounts['available']} kamar*
" .
                                 "🔴 Terisi: *{$groupCounts['booked']} kamar*
" .
                                 "🧹 Housekeeping/QC: *{$groupCounts['dirty']} kamar*
" .
                                 "🛠️ Servis/Blocker: *{$groupCounts['maintenance']} kamar*

" .
                                 "Angka berasal dari lifecycle operasional canonical, bukan cache status kamar. Nomor kamar, data tamu, dan data keuangan hanya tersedia melalui chat privat bot.";
                } else {
                    $replyText = "🔒 *CHAT PRIVAT DIPERLUKAN*

Demi keamanan database multi-user, command, tombol, binding akun, data tamu, AI, dan transaksi hanya diproses melalui chat privat dengan bot. Di grup hanya `/status_kamar` agregat yang diizinkan.";
                }
                $groupBotMsgId = generateServerId('tg_group_reply');
                $pdo->prepare("INSERT INTO telegram_messages (id,sender,`text`,timestamp,isAi,telegram_user_id,staff_id,command) VALUES (?,'bot',?,?,0,?,?,?)")
                    ->execute([$groupBotMsgId,$replyText,date('Y-m-d H:i:s'),(string)$fromId,$loggedInStaff['id'] ?? null,substr($command,0,100)]);
                if (empty($GLOBALS['is_telegram_simulation'])) {
                    $restrictedDelivery = tamasyaTelegramApiCallWithFormatRecovery($token,'sendMessage', ["chat_id"=>$chatId,"text"=>$replyText,"parse_mode"=>"Markdown"]);
                    if (empty($restrictedDelivery['ok'])) {
                        error_log('[Telegram Group Restricted] deliver gagal: '.telegramApiErrorMessage($restrictedDelivery));
                    }
                }
                echo json_encode(["success"=>true,"status"=>"group_restricted"]);
                break;
            }

            // If the user entered a command or system menu button, clear conversational state for this chatId
            $isCommandOrMenu = (strpos($command, '/') === 0) || in_array($command, [
                "📊 Cek Kamar", "🔑 Login Admin", "🔗 Hubungkan Akun", "🤖 Tanya AI", "🤖 Bantuan AI", "📅 Reservasi", "🛒 Jual Kamar", "🛒 Jual Kamar...",
                "🔎 Diagnosa Kamar", "🔧 Update Kamar", "📈 Laporan Kas", "📋 Riwayat Shift", "📥 Input Pemasukan", "📤 Input Pengeluaran", "🔒 Logout Admin", "🔒 Logout",
                "🧹 Housekeeping", "🚨 Patroli & Laporan", "🧹 Housekeeping...", "🚨 Patrol & Lapor...",
                "🔑 Buka Shift", "🔑 Buka Shift...", "📝 Tutup Shift", "📝 Kirim Laporan",
                "⏳ Perpanjang Sewa", "⏳ Perpanjang Sewa...", "🛠️ Tambah Layanan", "🛠️ Tambah Layanan...",
                "🔄 Pindah Kamar", "🔄 Pindah Kamar...", "🚪 Check-out Kamar", "🚪 Check-out Kamar...",
                "🧾 Cetak Nota", "🧾 Cetak Nota...", "🪪 Lihat KTP", "💵 Terima Panjar", "🗓️ Cuti Saya"
            ]);

            if ($isCommandOrMenu) {
                if ($loggedInStaff) {
                    $stmtClearState = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                    $stmtClearState->execute([$loggedInStaff['id']]);
                    // Refresh dari binding aktif server; jangan memakai statement
                    // yang belum dibuat pada request webhook ini.
                    $loggedInStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
                } else {
                    // Clear any pending login states for this fromId
                    $stmtReset = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE telegram_context = ? AND telegram_state = 'waiting_for_login_password'");
                    $stmtReset->execute([$fromId]);
                }
            }

            $stateProcessed = false;

            if (preg_match('/^\/(?:balas|reply)\s+(SUP-[A-Za-z0-9-]{8,64})\s+([\s\S]{1,2000})$/u', $command, $supportReplyMatch)) {
                if (!$loggedInStaff || !in_array(strtolower((string)($loggedInStaff['role'] ?? '')), ['admin','manager','receptionist'], true)) {
                    $replyText = "🔒 *AKSES DITOLAK*\n\nHanya Admin, Manajer, atau Resepsionis terverifikasi yang dapat membalas chat website.";
                } else {
                    try {
                        tamasyaPublicSupportReply($pdo,$supportReplyMatch[1],trim($supportReplyMatch[2]),$loggedInStaff,'telegram',(string)$fromId);
                        $replyText = "✅ *BALASAN TERKIRIM*\n\nPercakapan `{$supportReplyMatch[1]}` telah diperbarui. Jawaban akan muncul otomatis pada chat website dan kotak masuk aplikasi.";
                    } catch (Throwable $supportReplyError) {
                        $replyText = "❌ *BALASAN GAGAL*\n\n".clientExceptionMessage('Pesan tidak dapat dikirim',$supportReplyError);
                    }
                }
                $stateProcessed = true;
            }

            if (!$stateProcessed && preg_match('/^\/bind\s+([A-Z0-9-]{8,20})$/i', $command, $bindMatch)) {
                try {
                    $bound = consumeTelegramBindingCode($pdo,$bindMatch[1],$fromId,$chatId);
                    $loggedInStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
                    if (!$loggedInStaff) throw new RuntimeException('Binding tersimpan tetapi akun staf aktif tidak dapat dibaca kembali.');
                    $replyText = "✅ *AKUN TELEGRAM TERHUBUNG*\n\nHalo *" . ($bound['name'] ?? 'Staf') . "*. Telegram User ID Anda sekarang terikat ke akun server.\n\nKode sudah hangus dan tidak dapat digunakan ulang.";
                    $replyMarkup = $keyboardForTelegramRole($loggedInStaff);
                } catch (Throwable $bindError) {
                    $replyText = "❌ *BINDING GAGAL*\n\n" . clientExceptionMessage("Kode tidak dapat digunakan", $bindError) . "\n\nBuat kode baru dari aplikasi dan kirim dalam 10 menit.";
                    $replyMarkup = $userKeyboard;
                }
                $stateProcessed = true;
            }

            // Revalidasi role pada setiap langkah state machine. Perubahan role di
            // dashboard harus langsung membatalkan flow lama yang masih tersimpan.
            if (!$isCommandOrMenu && $loggedInStaff && !empty($loggedInStaff['telegram_state']) && !$stateProcessed) {
                $pendingState = (string)$loggedInStaff['telegram_state'];
                $pendingRole = strtolower((string)($loggedInStaff['role'] ?? ''));
                $pendingAllowed = true;
                if ($pendingState === 'waiting_for_support_reply') {
                    $pendingAllowed = in_array($pendingRole,['admin','manager','receptionist'],true);
                } elseif ($pendingState === 'waiting_for_incident_text') {
                    $pendingAllowed = in_array($pendingRole,['admin','manager','receptionist','keamanan'],true);
                } elseif ($pendingState === 'waiting_for_housekeeping_damage_detail') {
                    $pendingAllowed = hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']);
                } elseif ($pendingState === 'waiting_for_guest_service_completion_note') {
                    $pendingAllowed = in_array($pendingRole,$guestServiceTelegramRoles,true);
                } elseif ($pendingState === 'waiting_for_expense_input') {
                    $pendingAllowed = in_array($pendingRole,['admin','manager','finance'],true);
                } elseif (in_array($pendingState,['waiting_for_vacancy_belongings_detail','waiting_for_vacancy_damage_detail'],true)) {
                    $pendingAllowed = in_array($pendingRole,['admin','manager','receptionist','finance','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'],true);
                } elseif ($pendingState === 'waiting_for_leave_reject_reason') {
                    $pendingAllowed = in_array($pendingRole,['admin','manager'],true);
                } elseif (str_starts_with($pendingState,'waiting_for_leave_')) {
                    $pendingAllowed = $pendingRole!=='owner';
                } elseif (str_starts_with($pendingState,'waiting_for_dp_')) {
                    $pendingAllowed = hasCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance']);
                } elseif (str_starts_with($pendingState,'waiting_for_buka_shift_') || str_starts_with($pendingState,'waiting_for_tutup_shift_')) {
                    $pendingAllowed = tamasyaCanOperateShift($loggedInStaff)||hasCapability($loggedInStaff,'receive_payment_access',[]);
                } elseif (str_starts_with($pendingState,'waiting_mr_') || $pendingState==='mr_done') {
                    $pendingAllowed = hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']);
                } elseif ($pendingState !== 'waiting_for_login_password') {
                    // Langkah lanjutan alur jual/tetap/panjar: boleh via role
                    // baseline ATAU salah satu centangan penjualan. Tanpa
                    // centangan, fallback = perilaku lama persis.
                    $pendingAllowed = hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist'])
                        || hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist'])
                        || hasCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance']);
                }
                if (!$pendingAllowed) {
                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    $loggedInStaff['telegram_state'] = null;
                    $loggedInStaff['telegram_context'] = null;
                    $replyText = "🔒 *AKSES DITOLAK*

Peran akun Anda tidak lagi berhak melanjutkan proses Telegram ini. State lama telah dibatalkan tanpa mengubah database transaksi.";
                    $stateProcessed = true;
                }
            }

            if (!$isCommandOrMenu && $loggedInStaff && !empty($loggedInStaff['telegram_state']) && !$stateProcessed) {
                $currentState = $loggedInStaff['telegram_state'];
                $currentCtx = $loggedInStaff['telegram_context'];

                if(str_starts_with($currentState,'waiting_mr_')) {
                    try{$groupReply=tamasyaMultiRoomTelegramMessage($pdo,$loggedInStaff,(string)$command);$replyText=$groupReply['text'];$replyMarkup=$groupReply['markup'];}
                    catch(Throwable $e){$replyText=clientExceptionMessage('Langkah grup belum diproses',$e);}
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_support_reply') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $publicCode=trim((string)($ctx['publicCode']??''));$expiresAt=(int)($ctx['expiresAt']??0);
                    if($expiresAt<=0||time()>$expiresAt){
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $replyText="⌛ *MODE BALAS KEDALUWARSA*\n\nPilih tombol Balas pada notifikasi chat website terbaru lalu kirim pesan kembali.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    }elseif(!preg_match('/^SUP-[A-Za-z0-9-]{8,64}$/',$publicCode)){
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $replyText="⚠️ *KONTEKS BALAS TIDAK VALID*\n\nMode balas dibatalkan tanpa mengirim pesan.";
                    }else{
                        try{
                            $message=trim((string)$command);
                            if($message===''||tamasyaStringLength($message)>2000)throw new InvalidArgumentException('Balasan harus berisi 1 sampai 2.000 karakter.');
                            tamasyaPublicSupportReply($pdo,$publicCode,$message,$loggedInStaff,'telegram',(string)$fromId);
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                            $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                            $replyText="✅ *BALASAN TERKIRIM*\n\nPercakapan `{$publicCode}` sudah diperbarui. Mode balas otomatis selesai agar pesan berikutnya tidak salah masuk ke thread yang sama.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        }catch(Throwable $supportStateError){
                            $replyText="❌ *BALASAN GAGAL*\n\n".clientExceptionMessage('Pesan tidak dapat dikirim',$supportStateError)."\n\nMode balas tetap aktif selama belum kedaluwarsa.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'✖️ Batalkan Balas','callback_data'=>'support_reply_cancel']]]];
                        }
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_leave_start') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $start=trim((string)$command);
                    if(!validIsoDate($start)||$start<date('Y-m-d')){
                        $replyText="⚠️ Tanggal mulai tidak valid atau berada di masa lalu. Gunakan format `YYYY-MM-DD`.";
                    }else{
                        $ctx['startDate']=$start;
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_leave_end',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $replyText="📅 *TANGGAL SELESAI CUTI*\n\nTanggal mulai: `{$start}`\nKetik tanggal selesai dengan format `YYYY-MM-DD`. Maksimal total pengajuan 90 hari.";
                    }
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'leave_menu']]]];$stateProcessed=true;
                } elseif ($currentState === 'waiting_for_leave_end') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];$end=trim((string)$command);$start=(string)($ctx['startDate']??'');
                    $days=(validIsoDate($start)&&validIsoDate($end)&&$end>=$start)?((int)((strtotime($end)-strtotime($start))/86400)+1):0;
                    if($days<1||$days>90){$replyText="⚠️ Tanggal selesai tidak valid. Pastikan tidak sebelum tanggal mulai dan durasi 1-90 hari.";}
                    else{
                        $ctx['endDate']=$end;
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_leave_reason',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $replyText="📝 *ALASAN CUTI*\n\nPeriode: {$start} s.d. {$end} ({$days} hari)\nKetik alasan cuti minimal 5 karakter dan maksimal 1000 karakter.";
                    }
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'leave_menu']]]];$stateProcessed=true;
                } elseif ($currentState === 'waiting_for_leave_reason') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];$reason=trim((string)$command);
                    if(tamasyaStringLength($reason)<5||tamasyaStringLength($reason)>1000){$replyText='⚠️ Alasan cuti harus 5-1000 karakter.';}
                    else{
                        $ctx['reason']=$reason;
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_leave_confirm',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $safeReason=$telegramPlainText($reason);$days=(int)((strtotime((string)$ctx['endDate'])-strtotime((string)$ctx['startDate']))/86400)+1;
                        $replyText="🔎 *KONFIRMASI PENGAJUAN CUTI*\n\nJenis: ".tamasyaEmployeeLeaveTypeLabel((string)$ctx['leaveType'])."\nPeriode: {$ctx['startDate']} s.d. {$ctx['endDate']} ({$days} hari)\nAlasan: {$safeReason}\n\nPeriksa kembali sebelum menyimpan.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Kirim Pengajuan','callback_data'=>'leave_confirm']],[['text'=>'❌ Batalkan','callback_data'=>'leave_menu']]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_leave_confirm') {
                    $replyText='ℹ️ Gunakan tombol *Kirim Pengajuan* untuk menyimpan atau *Batalkan* untuk keluar. Pesan tambahan tidak mengubah database.';
                    $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Kirim Pengajuan','callback_data'=>'leave_confirm']],[['text'=>'❌ Batalkan','callback_data'=>'leave_menu']]]];$stateProcessed=true;
                } elseif ($currentState === 'waiting_for_leave_reject_reason') {
                    $ctx=json_decode((string)$currentCtx,true);$reason=trim((string)$command);$requestId=is_array($ctx)?trim((string)($ctx['requestId']??'')):'';
                    if($requestId===''||tamasyaStringLength($reason)<5||tamasyaStringLength($reason)>1000){$replyText='⚠️ Alasan penolakan harus 5-1000 karakter. Belum ada keputusan yang disimpan.';}
                    else{
                        try{
                            tamasyaEmployeeDecideLeaveRequest($pdo,$loggedInStaff,$requestId,'rejected',$reason,'telegram');
                            try{$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);}
                            catch(Throwable $leaveStateCleanupError){error_log(clientExceptionMessage('[telegram] leave reject state cleanup failed',$leaveStateCleanupError));}
                            $replyText='✅ *CUTI DITOLAK*\n\nKeputusan dan alasan tersimpan, staf akan menerima notifikasi, dan jejak audit telah dicatat.';$replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Persetujuan Lain','callback_data'=>'leave_team'],['text'=>'⬅️ Menu Cuti','callback_data'=>'leave_menu']]]];
                        }catch(Throwable $leaveRejectError){$replyText='⚠️ '.clientExceptionMessage('Keputusan cuti tidak dapat disimpan',$leaveRejectError);$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Persetujuan Cuti','callback_data'=>'leave_team']]]];}
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_guest_service_completion_note') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];$id=trim((string)($ctx['requestId']??''));$note=trim((string)$command);
                    if($id===''||tamasyaStringLength($note)<3||tamasyaStringLength($note)>2000){$replyText='⚠️ Catatan penyelesaian harus 3-2.000 karakter. Permintaan belum ditutup.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kembali','callback_data'=>$id!==''?'gsd:'.$id:'gsm:p:1']]]];}
                    else{
                        $operationId=telegramScopedOperationId($telegramUpdateOperationId,'guest-service-complete',[$id,hash('sha256',$note)]);
                        try{
                            $pdo->beginTransaction();claimTelegramMutation($pdo,$operationId,$loggedInStaff['id'],'guest_service_request',$id,['action'=>'fulfilled','id'=>$id,'note'=>$note],'message');
                            $current=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);if(!$current)throw new RuntimeException('Permintaan tamu tidak ditemukan.');$role=strtolower((string)$loggedInStaff['role']);$assignee=trim((string)($current['assigned_to']??''));
                            if($assignee!==''&&$assignee!==(string)$loggedInStaff['id']&&!hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist']))throw new RuntimeException('Permintaan ini ditugaskan ke staf lain.');
                            $result=tamasyaCloseGuestServiceRequest($pdo,$loggedInStaff,$id,'fulfilled',$note,'telegram-guest-service');completeTelegramMutation($pdo,$operationId,['id'=>$id,'status'=>'fulfilled']);$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                            if(empty($result['idempotent'])&&!empty($result['request']))broadcastTelegramNotification($pdo,tamasyaGuestServiceTelegramMessage((array)$result['request'],'fulfilled',$loggedInStaff),false,'operations');
                            $replyText="✅ *PERMINTAAN TAMU SELESAI*\n\nCatatan: ".$telegramPlainText($note)."\n\nState aplikasi dan Telegram sudah menggunakan record Guest Service yang sama.";$replyMarkup=['inline_keyboard'=>[[['text'=>'🔔 Antrean Lain','callback_data'=>'gsm:p:1']],[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        }catch(TelegramDuplicateOperationException $duplicate){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);$replyText='ℹ️ Penyelesaian ini sudah diproses sebelumnya; database tidak digandakan.';$replyMarkup=['inline_keyboard'=>[[['text'=>'🔔 Antrean','callback_data'=>'gsm:p:1']]]];}
                        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();failTelegramMutation($pdo,$operationId,$e);$replyText='⚠️ '.clientExceptionMessage('Permintaan belum dapat diselesaikan',$e).'\n\nCatatan belum diterapkan; silakan perbaiki lalu kirim ulang.';$replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Detail','callback_data'=>'gsd:'.$id]]]];}
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_housekeeping_damage_detail') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $roomNumber=trim((string)($ctx['roomNumber']??''));
                    try{
                        $damageDetail=tamasyaRequireTechnicalDamageDetail((string)$command,'Detail kerusakan Housekeeping');
                        if($roomNumber==='')throw new RuntimeException('Konteks kamar Housekeeping sudah tidak valid.');
                        $operationId=trim((string)($ctx['operationId']??''))?:telegramScopedOperationId($telegramUpdateOperationId,'housekeeping-damage-detail',[$roomNumber,hash('sha256',$damageDetail)]);
                        $pdo->beginTransaction();
                        claimTelegramMutation($pdo,$operationId,$loggedInStaff['id'],'rooms',$roomNumber,['action'=>'need_maintenance','room'=>$roomNumber,'damageDetail'=>$damageDetail],'message');
                        $hkResult=applyHousekeepingAction($pdo,$loggedInStaff,$roomNumber,'need_maintenance','telegram_housekeeping',$ctx['taskId']??'',$damageDetail);
                        completeTelegramMutation($pdo,$operationId,['room'=>$roomNumber,'status'=>$hkResult['roomStatus'],'taskId'=>$hkResult['taskId'],'maintenanceTicketId'=>$hkResult['maintenanceTicketId']??null]);
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        tamasyaFinancialCommit($pdo);
                        $postCallbackBroadcasts[]=["⚠️ *KERUSAKAN KAMAR DILAPORKAN KE ENGINEERING*\n\n🚪 Kamar: *{$roomNumber}*\n📝 Detail: *".$telegramPlainText($damageDetail)."*\n👤 Petugas: *".currentStaffLabel($loggedInStaff)."*",false];
                        $replyText="✅ *TEMUAN KERUSAKAN TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n📝 Detail: ".$telegramPlainText($damageDetail)."\n📌 Housekeeping baru diblokir setelah tiket Engineering berhasil dibuat.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kembali ke Housekeeping','callback_data'=>'hk_room:'.$roomNumber]]]];
                    }catch(TelegramDuplicateOperationException $duplicate){
                        if($pdo->inTransaction())$pdo->rollBack();
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $replyText='ℹ️ Temuan kerusakan ini sudah diproses sebelumnya; database tidak digandakan.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kembali ke Housekeeping','callback_data'=>'hk_room:'.$roomNumber]]]];
                    }catch(Throwable $housekeepingDamageError){
                        if($pdo->inTransaction())$pdo->rollBack();
                        if(isset($operationId))failTelegramMutation($pdo,$operationId,$housekeepingDamageError);
                        $replyText='⚠️ '.clientExceptionMessage('Temuan kerusakan belum disimpan',$housekeepingDamageError).'\n\nKetik ulang detail yang spesifik; status kamar/task belum diubah.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'hk_room:'.$roomNumber]]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_vacancy_belongings_detail') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $roomNumber=trim((string)($ctx['roomNumber']??''));$key=trim((string)($ctx['keyObservation']??'unknown'));$condition=trim((string)($ctx['condition']??'belongings'));
                    try{
                        $belongingsDetail=tamasyaRequireLostFoundItemDetail((string)$command,'Detail barang Kamar Kosong');
                        if($roomNumber==='')throw new RuntimeException('Konteks laporan kamar kosong sudah tidak valid.');
                        if(($ctx['damageStatus']??'none')==='found'){
                            $ctx['belongingsDetail']=$belongingsDetail;
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_vacancy_damage_detail',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                            $replyText="✅ Detail barang tersimpan sementara.\n\n🛠 *SEKARANG DETAIL KERUSAKAN*\n🚪 Kamar: *{$roomNumber}*\nKetik apa yang rusak atau gejalanya secara bebas.\n\nContoh: `AC tidak dingin` atau `engsel lemari oblak`.\n\nBelum ada laporan final sampai detail kerusakan valid.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'vacancy_menu']]]];
                        }else{
                            $operationId=trim((string)($ctx['operationId']??''))?:telegramScopedOperationId($telegramUpdateOperationId,'vacancy-belongings-detail',[$roomNumber,hash('sha256',$belongingsDetail)]);
                            $result=createOrRefreshRoomVacancyReport($pdo,$loggedInStaff,[
                                'roomNumber'=>$roomNumber,'operationId'=>$operationId,'keyObservation'=>$key,
                                'belongingsStatus'=>'found','belongingsDetail'=>$belongingsDetail,
                                'damageStatus'=>'none','damageDetail'=>'','notes'=>'','observedAt'=>date('c')
                            ],'telegram');
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                            $isStale=!empty($result['stale']);$isDuplicate=!empty($result['duplicate']);
                            if(!$isStale&&!$isDuplicate)$postCallbackBroadcasts[]=["🚪 *KAMAR DILAPORKAN KOSONG*\n\nKamar: *{$roomNumber}*\n📦 Barang: *".$telegramPlainText($belongingsDetail)."*\nPelapor: *".currentStaffLabel($loggedInStaff)."*\nStatus: *MENUNGGU VERIFIKASI CHECKOUT*",false];
                            $replyText=$isStale?"ℹ️ *LAPORAN TERLAMBAT TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n📦 Barang: ".$telegramPlainText($belongingsDetail)."\nBooking yang diamati sudah tidak aktif; temuan tetap disimpan pada booking lama.":"✅ *LAPORAN KAMAR KOSONG TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n📦 Barang tertinggal: ".$telegramPlainText($belongingsDetail)."\n📌 Menunggu verifikasi checkout.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'📊 Cek Status Kamar','callback_data'=>'room_list']]]];
                        }
                    }catch(Throwable $vacancyBelongingsError){
                        $replyText='⚠️ '.clientExceptionMessage('Detail barang belum disimpan',$vacancyBelongingsError).'\n\nKetik ulang nama/deskripsi barang; laporan belum dibuat.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'vacancy_menu']]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_vacancy_damage_detail') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $roomNumber=trim((string)($ctx['roomNumber']??''));$key=trim((string)($ctx['keyObservation']??'unknown'));$condition=trim((string)($ctx['condition']??'damage'));
                    $belongings=trim((string)($ctx['belongingsDetail']??''))!==''?'found':'none';$belongingsDetail=trim((string)($ctx['belongingsDetail']??''));
                    try{
                        $damageDetail=tamasyaRequireTechnicalDamageDetail((string)$command,'Detail kerusakan Kamar Kosong');
                        if($roomNumber==='')throw new RuntimeException('Konteks laporan kamar kosong sudah tidak valid.');
                        $operationId=trim((string)($ctx['operationId']??''))?:telegramScopedOperationId($telegramUpdateOperationId,'vacancy-damage-detail',[$roomNumber,hash('sha256',$damageDetail)]);
                        $result=createOrRefreshRoomVacancyReport($pdo,$loggedInStaff,[
                            'roomNumber'=>$roomNumber,'operationId'=>$operationId,'keyObservation'=>$key,
                            'belongingsStatus'=>$belongings,'belongingsDetail'=>$belongingsDetail,
                            'damageStatus'=>'found','damageDetail'=>$damageDetail,'notes'=>'','observedAt'=>date('c')
                        ],'telegram');
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $isStale=!empty($result['stale']);$isDuplicate=!empty($result['duplicate']);
                        if(!$isStale&&!$isDuplicate)$postCallbackBroadcasts[]=["🚪 *KAMAR DILAPORKAN KOSONG*\n\nKamar: *{$roomNumber}*\n🛠 Kerusakan: *".$telegramPlainText($damageDetail)."*\nPelapor: *".currentStaffLabel($loggedInStaff)."*\nStatus: *MENUNGGU VERIFIKASI CHECKOUT*",false];
                        $replyText=$isStale?"ℹ️ *LAPORAN TERLAMBAT TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n🛠 Detail: ".$telegramPlainText($damageDetail)."\nBooking yang diamati sudah tidak aktif; temuan tetap disimpan pada booking lama.":"✅ *LAPORAN KAMAR KOSONG TERSIMPAN*\n\n🚪 Kamar: *{$roomNumber}*\n🛠 Detail kerusakan: ".$telegramPlainText($damageDetail)."\n📌 Menunggu verifikasi checkout. Kamar belum dianggap bersih/siap jual.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'📊 Cek Status Kamar','callback_data'=>'room_list']]]];
                    }catch(Throwable $vacancyDamageError){
                        $replyText='⚠️ '.clientExceptionMessage('Laporan kerusakan kamar kosong belum disimpan',$vacancyDamageError).'\n\nKetik ulang detail kerusakan yang spesifik; laporan belum dibuat.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'vacancy_menu']]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_incident_text') {
                    $staffName=(string)$loggedInStaff['name'];
                    try{
                        $reportText=tamasyaRequireOperationalIncidentDetail((string)$command,'Laporan security/safety/occupancy');
                        $operationId=telegramScopedOperationId($telegramUpdateOperationId,'field-incident',[hash('sha256',$reportText)]);
                        $pdo->beginTransaction();
                        $incident=tamasyaCreateOperationalIncident($pdo,$loggedInStaff,'laporan lapangan',$reportText,'warning','','area umum',false,'telegram_patrol',$operationId,'telegram');
                        $incidentId=(string)$incident['id'];
                        $notifMsg="📢 [LAPORAN LAPANGAN] {$reportText} (Dilaporkan oleh {$staffName})";
                        $notifId='inc_notif_'.substr(hash('sha256',$incidentId),0,48);
                        $pdo->prepare("INSERT INTO notifications (id,type,message,timestamp,`read`) VALUES (?,'system',?,CURRENT_TIMESTAMP,0) ON DUPLICATE KEY UPDATE id=id")
                            ->execute([$notifId,$notifMsg]);
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        tamasyaFinancialCommit($pdo);
                        broadcastTelegramNotification($pdo,"📢 *LAPORAN LAPANGAN BARU*\n\n📝 Detail: *\"".$telegramPlainText($reportText)."\"*\n👤 Pelapor: *{$staffName}*\n🧭 Incident ID: `{$incidentId}`",false);
                        $replyText="✅ *INSIDEN OPERASIONAL TERCATAT*\n\n_\"".$telegramPlainText($reportText)."\"_\n\nID: `{$incidentId}`\nStatus: *OPEN*\n\nNotifikasi sudah dikirim. Jika kejadian harus menahan kamar, Manager/Admin menetapkan room hold secara eksplisit; laporan ini tidak menebak nomor kamar dari teks.";
                        $replyMarkup=["inline_keyboard"=>[[["text"=>"🚨 Kembali ke Menu Patroli","callback_data"=>"patrol_reports_menu"]]]];
                    }catch(Throwable $incidentError){
                        if($pdo->inTransaction())$pdo->rollBack();
                        $replyText='⚠️ '.clientExceptionMessage('Laporan insiden belum disimpan',$incidentError).'\n\nKetik ulang kondisi/lokasi yang spesifik; tidak ada incident yang dibuat.';
                        $replyMarkup=["inline_keyboard"=>[[["text"=>"❌ Batalkan","callback_data"=>"cancel_patrol_process"]]]];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_checkout_missing_key_reason') {
                    $reason=trim((string)$command);$ctx=json_decode((string)$currentCtx,true);$roomNumber=trim((string)($ctx['roomNumber']??''));$bookingId=trim((string)($ctx['bookingId']??''));
                    if(tamasyaStringLength($reason)<5||$roomNumber===''||$bookingId===''){
                        $replyText="⚠️ Alasan minimal 5 karakter. Jelaskan mengapa kunci belum kembali atau tidak ditemukan.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                    }else{
                        try{$stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND roomNumber=? AND status='active' LIMIT 1");$stmt->execute([$bookingId,$roomNumber]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                            $step=prepareTelegramCheckoutFinancialStep($pdo,$loggedInStaff,$booking,'missing',$reason,isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null);$replyText=$step['text'];$replyMarkup=$step['markup'];
                        }catch(Throwable $missingKeyError){$replyText='❌ '.clientExceptionMessage('Alasan kunci ditolak',$missingKeyError);$replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];}
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_checkout_split_cash') {
                    $splitCash=tamasyaTelegramParseMoney((string)$command)??-1;
                    $ctx=json_decode((string)$currentCtx,true);
                    $roomNumber=trim((string)($ctx['roomNumber']??''));$bookingId=trim((string)($ctx['bookingId']??''));
                    if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_payment'||$roomNumber===''||$bookingId===''){
                        $replyText='⚠️ Sesi split checkout tidak valid. Buka kembali menu checkout.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]];
                    }else{
                        try{
                            $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND roomNumber=? AND status='active' LIMIT 1");$stmt->execute([$bookingId,$roomNumber]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
                            if(!$booking)throw new RuntimeException('Booking aktif tidak ditemukan.');
                            $ledger=bookingLedgerTotals($pdo,$bookingId);$remaining=max(0.0,round((float)$booking['totalAmount']-(float)$ledger['net'],2));
                            if($splitCash<=0||$splitCash>=$remaining)throw new InvalidArgumentException('Bagian tunai harus lebih dari Rp0 dan lebih kecil dari sisa tagihan.');
                            $splitTransfer=round($remaining-$splitCash,2);
                            $stmtAcc=$pdo->query("SELECT id,name,type FROM bank_accounts WHERE isActive=1 AND type IN ('bank','edc_qris') ORDER BY name ASC LIMIT 8");
                            $bankMap=[];$buttons=[];
                            foreach(($stmtAcc?$stmtAcc->fetchAll(PDO::FETCH_ASSOC):[])?:[] as $account){
                                $method=tamasyaPaymentMethodForAccountType((string)($account['type']??''))??'';if(!in_array($method,['transfer','qris'],true))continue;
                                $key=(string)count($bankMap);$bankMap[$key]=['id'=>(string)$account['id'],'method'=>$method,'name'=>(string)$account['name']];
                                $buttons[]=[['text'=>($method==='qris'?'📱 QRIS · ':'🏦 Transfer · ').(string)$account['name'],'callback_data'=>'r_checkout_split_bank:'.$roomNumber.':'.$key]];
                            }
                            if(!$bankMap)throw new RuntimeException('Tidak ada akun Transfer/QRIS aktif untuk bagian non-tunai.');
                            $ctx['flow']='checkout_split_bank';$ctx['splitCashAmount']=$splitCash;$ctx['splitTransferAmount']=$splitTransfer;$ctx['bankMap']=$bankMap;
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_split_bank',telegram_context=? WHERE id=?")
                                ->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                            $buttons[]=[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']];
                            $replyText="🏦 *PILIH AKUN BAGIAN NON-TUNAI*

💵 Tunai: *Rp ".tamasyaTelegramFormatAmount($splitCash)."*
💳 Transfer/QRIS: *Rp ".tamasyaTelegramFormatAmount($splitTransfer)."*";
                            $replyMarkup=['inline_keyboard'=>$buttons];
                        }catch(Throwable $splitCashError){$replyText='⚠️ '.clientExceptionMessage('Nominal split ditolak',$splitCashError);$replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];}
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_checkout_nego_amount' || $currentState === 'waiting_for_checkout_nego_reason' || $currentState === 'waiting_for_checkout_nego_confirmation') {
                    try{
                        $ctx=json_decode((string)$currentCtx,true);
                        tamasyaTelegramChargeAssertRole($loggedInStaff);
                        if(!is_array($ctx)||($ctx['flow']??'')!=='checkout_negotiation'||($ctx['expiresAt']??0)<time())throw new RuntimeException('Draft harga nego kedaluwarsa. Buka kembali checkout.');
                        if($currentState==='waiting_for_checkout_nego_amount'){
                            $amount=tamasyaTelegramParseMoney((string)$command);
                            // The figure is what the guest hands over now, so the booking
                            // bounds apply to that figure plus the cash already received.
                            $negoFloor=max(0.0,round((float)$ctx['quote']['minimumTotal']-(float)$ctx['quote']['amountPaid'],2));
                            $negoCeiling=max(0.0,round((float)$ctx['quote']['totalAmount']-(float)$ctx['quote']['amountPaid'],2));
                            if($amount===null||$amount<=0||$amount<$negoFloor||$amount>$negoCeiling)throw new InvalidArgumentException('Uang yang dibayar sekarang harus antara Rp '.tamasyaTelegramFormatAmount($negoFloor).' dan Rp '.tamasyaTelegramFormatAmount($negoCeiling).'.');
                            $q=$pdo->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');$q->execute([$ctx['quote']['bookingId']]);$booking=$q->fetch(PDO::FETCH_ASSOC);
                            if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
                            $fresh=tamasyaBookingNegotiationQuote($pdo,$booking);
                            if(!hash_equals($fresh['quoteToken'],$ctx['quote']['quoteToken']))throw new RuntimeException('Tagihan berubah. Buka ulang checkout.');
                            $plan=tamasyaNegotiatedPricePlan($booking,$fresh['components'],$fresh['paid'],$fresh['amountPaid'],round($amount+(float)$fresh['amountPaid'],2));
                            $ctx['finalVatAmount']=$plan['vatAmount'];$ctx['finalTotal']=$amount;
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_nego_reason',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                            $replyText='📝 Ketik alasan/kesepakatan nego minimal 5 karakter. Harga baru belum disimpan.';
                        }elseif($currentState==='waiting_for_checkout_nego_reason'){
                            if(tamasyaStringLength(trim((string)$command))<5)throw new InvalidArgumentException('Alasan minimal 5 karakter.');
                            $ctx['reason']=trim((string)$command);
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_nego_confirmation',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                            $replyText="🧾 *KONFIRMASI HARGA NEGO*\n\nTotal lama: Rp ".tamasyaTelegramFormatAmount($ctx['quote']['totalAmount'])."\nSudah dibayar (tetap): Rp ".tamasyaTelegramFormatAmount($ctx['quote']['amountPaid'])."\nTotal booking baru termasuk PBJT: Rp ".tamasyaTelegramFormatAmount(round((float)$ctx['finalTotal']+(float)$ctx['quote']['amountPaid'],2))."\nPBJT final: Rp ".tamasyaTelegramFormatAmount($ctx['finalVatAmount'])."\nTagihan saat checkout: Rp ".tamasyaTelegramFormatAmount($ctx['finalTotal'])."\nAlasan: ".tamasyaTelegramPlainText($ctx['reason'])."\n\nSimpan harga dahulu, lalu pilih pembayaran. Ini tidak mencatat penerimaan uang.";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Simpan Harga Nego','callback_data'=>'r_checkout_nego_save:'.$ctx['nonce']]],[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                        }else{$replyText='Silakan gunakan tombol Simpan Harga Nego atau Batalkan pada konfirmasi terakhir.';}
                    }catch(Throwable $e){$replyText=clientExceptionMessage('Harga nego belum dapat diproses',$e);}
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_checkout_total') {
                    $actualTotal=tamasyaTelegramParseMoney((string)$command)??-1;
                    $ctx = json_decode((string)$currentCtx,true);
                    $roomNumber = trim((string)($ctx['roomNumber'] ?? ''));
                    $bookingId = trim((string)($ctx['bookingId'] ?? ''));
                    if ($actualTotal <= 0 || $roomNumber === '' || $bookingId === '') {
                        $replyText = "⚠️ *TOTAL TAGIHAN TIDAK VALID*\n\nKetik angka total tagihan aktual, contoh: `850000`.";
                        $replyMarkup = ['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                    } else {
                        try {
                            $checkoutTotalOperationId=telegramScopedOperationId($telegramUpdateOperationId,'checkout-total',[$bookingId,$roomNumber,number_format($actualTotal,2,'.','')]);
                            tamasyaRequirePropertyReadyForLiveMutation($pdo,'finalisasi total checkout via Telegram');
                            $pdo->beginTransaction();
                            claimTelegramMutation($pdo,$checkoutTotalOperationId,$loggedInStaff['id'],'bookings',$bookingId,[
                                'action'=>'finalize_open_ended_total','bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'actualTotal'=>$actualTotal
                            ],'message');
                            $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id=? AND roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");
                            $stmtBooking->execute([$bookingId,$roomNumber]);
                            $booking = $stmtBooking->fetch(PDO::FETCH_ASSOC);
                            if (!$booking) throw new RuntimeException('Booking aktif tidak ditemukan.');
                            if (empty($booking['isOpenEnded'])) throw new RuntimeException('Booking ini bukan lagi durasi terbuka.');
                            $beforeBooking=tamasyaBookingAuditSnapshot($booking);
                            $ledger = bookingLedgerTotals($pdo,$bookingId);
                            if ($actualTotal + 1.0 < $ledger['net']) {
                                throw new InvalidArgumentException('Total aktual tidak boleh lebih kecil dari penerimaan/DP yang sudah tercatat.');
                            }
                            // Stated figure is what the guest hands over now; cash received
                            // earlier stays closed in its own shift and is added back.
                            $collectedNow=round((float)$actualTotal,2);
                            $actualTotal=round($collectedNow+(float)$ledger['net'],2);
                            $tax = resolveBookingAggregateTaxSnapshot(
                                $pdo,array_merge($booking,['bookingSource'=>$booking['bookingSource']??'Direct']),$actualTotal,date('Y-m-d')
                            );
                            $remaining = max(0.0,round($collectedNow,2));
                            $paymentStatus = $remaining > 0 ? 'partial' : 'paid';
                            $pdo->prepare("UPDATE bookings SET totalAmount=?,checkOut=?,vatRate=?,vatAmount=?,extras=?,isOpenEnded=0,paymentStatus=?,version=version+1,updatedBy=?,updatedSource='telegram' WHERE id=?")
                                ->execute([
                                    $actualTotal,date('Y-m-d'),$tax['taxRate'],$tax['taxAmount'],
                                    json_encode($tax['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                                    $paymentStatus,(string)$loggedInStaff['id'],$bookingId
                                ]);
                            recalculateBookingFinancials($pdo,(string)$booking['id'],true);
                            assertBookingLedgerInvariant($pdo,(string)$booking['id'],$loggedInStaff,'telegram',false);
                            $afterStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
                            $afterStmt->execute([$bookingId]);
                            $afterBooking=$afterStmt->fetch(PDO::FETCH_ASSOC);
                            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menetapkan total aktual booking durasi terbuka','booking',$bookingId,$beforeBooking,tamasyaBookingAuditSnapshot($afterBooking),'telegram');
                            completeTelegramMutation($pdo,$checkoutTotalOperationId,['bookingId'=>$bookingId,'actualTotal'=>$actualTotal,'paymentStatus'=>$paymentStatus]);
                            bumpServerRevision($pdo);
                            tamasyaFinancialCommit($pdo);
                            $booking=$afterBooking?:$booking;
                            $step=prepareTelegramCheckoutFinancialStep($pdo,$loggedInStaff,$booking,(string)($ctx['keyDisposition']??'not_required'),(string)($ctx['keyReason']??''),isset($ctx['vacancyReportId'])?(string)$ctx['vacancyReportId']:null);
                            $replyText="✅ *TOTAL AKTUAL TERSIMPAN*\n\n".$step['text'];
                            $replyMarkup=$step['markup'];
                        } catch (TelegramDuplicateOperationException $duplicate) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $replyText='ℹ️ Total aktual checkout ini sudah diproses; booking dan ledger tidak digandakan.';
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        } catch (Throwable $checkoutTotalError) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            if(isset($checkoutTotalOperationId))failTelegramMutation($pdo,$checkoutTotalOperationId,$checkoutTotalError);
                            $replyText = "❌ " . clientExceptionMessage('Total checkout ditolak',$checkoutTotalError);
                            $replyMarkup = ['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                        }
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_dp_amount') {
                    $ctx=json_decode((string)$currentCtx,true);$amount=tamasyaTelegramParseMoney((string)$command)??-1;
                    if(!is_array($ctx)||empty($ctx['bookingId'])){
                        $replyText='⚠️ Sesi panjar hilang. Mulai ulang dari menu Terima Panjar.';
                    } elseif($amount<=0){
                        $replyText='⚠️ Nominal tidak valid. Ketik angka lebih dari nol, misalnya `300000`.';
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'main_menu']]]];
                    } else {
                        $ctx['amount']=$amount;$ctx['requestUpdateId']=$update['update_id']??generateServerId('tgdp');
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_dp_method',telegram_context=? WHERE id=?")->execute([json_encode($ctx),$loggedInStaff['id']]);
                        $replyText="💳 *PILIH METODE PANJAR*

Kamar: *{$ctx['roomNumber']}*
Nominal: *Rp ".tamasyaTelegramFormatAmount($amount)."*";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'💵 Tunai','callback_data'=>'dp_method:cash']],[['text'=>'🏦 Transfer','callback_data'=>'dp_method:transfer'],['text'=>'📱 QRIS','callback_data'=>'dp_method:qris']],[['text'=>'❌ Batalkan','callback_data'=>'main_menu']]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_buka_shift_cash') {
                    $startingCash=tamasyaTelegramParseMoney((string)$command)??-1;
                    if($startingCash<0){
                        $replyText="⚠️ *NOMINAL TIDAK VALID!*

Ketik kas awal berupa angka, misalnya `500000` atau `0`.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_buka_shift']]]];
                    } else {
                        $context=json_decode((string)$currentCtx,true);
                        if(!is_array($context)||empty($context['shiftTime'])||empty($context['shiftDate'])){
                            $replyText='⚠️ Data sesi buka shift tidak ditemukan. Mulai ulang dengan /buka_shift.';
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        } else {
                            try{
                                $shiftTime=(string)$context['shiftTime'];$shiftDate=(string)$context['shiftDate'];
                                $companionId=trim((string)($context['companionStaffId']??''));
                                $shiftOperationId=telegramScopedOperationId($telegramUpdateOperationId,'open-shift',[$shiftDate,$shiftTime,$companionId,number_format($startingCash,2,'.','')]);
                                tamasyaRequirePropertyReadyForLiveMutation($pdo,'pembukaan shift kas via Telegram');
                                $pdo->beginTransaction();
                                claimTelegramMutation($pdo,$shiftOperationId,$loggedInStaff['id'],'shift_sessions','open',[                                    'action'=>'open_shift','shiftDate'=>$shiftDate,'shiftTime'=>$shiftTime,'companionStaffId'=>$companionId,'openingCash'=>$startingCash
                                ],'message');
                                $staffLock=$pdo->prepare("SELECT id,name FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                                $staffLock->execute([(string)$loggedInStaff['id']]);
                                if(!$staffLock->fetch(PDO::FETCH_ASSOC))throw new RuntimeException('Akun petugas utama tidak ditemukan.');
                                $existing=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'],true);
                                $createdShift=false;
                                if($existing){
                                    $sameCompanion=trim((string)($existing['companion_staff_id']??''))===$companionId;
                                    if((string)$existing['shift_time']!==$shiftTime||(string)$existing['shift_date']!==$shiftDate||!$sameCompanion)throw new RuntimeException('Akun sudah terikat pada shift terbuka yang berbeda.');
                                    $shiftSessionId=(string)$existing['id'];$startingCash=(float)$existing['opening_cash'];
                                    $companion=$companionId!==''?['id'=>$companionId,'name'=>$existing['companion_staff_name']]:null;
                                    $shiftAfter=$existing;
                                }else{
                                    $companion=validateOptionalShiftCompanion($pdo,(string)$loggedInStaff['id'],$companionId,true);
                                    $shiftSessionId=generateServerId('shift');
                                    $pdo->prepare("INSERT INTO shift_sessions(id,staff_id,staff_name,companion_staff_id,companion_staff_name,shift_date,shift_time,opening_cash,expected_cash,status,notes,opened_at) VALUES (?,?,?,?,?,?,?,?,?,'open','Dibuka melalui Telegram',CURRENT_TIMESTAMP)")
                                        ->execute([$shiftSessionId,$loggedInStaff['id'],$loggedInStaff['name'],$companion['id']??null,$companion['name']??null,$shiftDate,$shiftTime,$startingCash,$startingCash]);
                                    $shiftStmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? LIMIT 1");$shiftStmt->execute([$shiftSessionId]);$shiftAfter=$shiftStmt->fetch(PDO::FETCH_ASSOC);
                                    $partnerName=$companion['name']??null;
                                    $displayName=$partnerName?($loggedInStaff['name'].' + '.$partnerName):(string)$loggedInStaff['name'];
                                    $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
                                        ->execute([generateServerId('notif_shift'),'Shift '.$shiftTime.' dibuka oleh '.$displayName.' dengan kas awal Rp '.tamasyaTelegramFormatAmount($startingCash)]);
                                    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuka shift kas fleksibel','shift_session',$shiftSessionId,null,[
                                        'id'=>(string)($shiftAfter['id']??$shiftSessionId),'staffId'=>(string)($shiftAfter['staff_id']??$loggedInStaff['id']),
                                        'companionStaffId'=>$shiftAfter['companion_staff_id']??null,'shiftDate'=>$shiftAfter['shift_date']??$shiftDate,
                                        'shiftTime'=>$shiftAfter['shift_time']??$shiftTime,'openingCash'=>(float)($shiftAfter['opening_cash']??$startingCash),
                                        'status'=>$shiftAfter['status']??'open','source'=>'telegram'
                                    ],'telegram');
                                    bumpServerRevision($pdo);
                                    $createdShift=true;
                                }
                                $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                                completeTelegramMutation($pdo,$shiftOperationId,['shiftSessionId'=>$shiftSessionId,'created'=>$createdShift,'openingCash'=>$startingCash]);
                                tamasyaFinancialCommit($pdo);
                                $partnerName=$companion['name']??null;
                                $displayName=$partnerName?($loggedInStaff['name'].' + '.$partnerName):(string)$loggedInStaff['name'];
                                if($createdShift){
                                    $tgMessage="🔔 *SHIFT KAS DIBUKA*\n\n📅 {$shiftDate}\n⏰ ".strtoupper($shiftTime)."\n👥 Petugas: *{$displayName}*\n💵 Kas awal: *Rp ".tamasyaTelegramFormatAmount($startingCash)."*";
                                    try{broadcastTelegramNotification($pdo,$tgMessage,true);}catch(Throwable $deliveryError){error_log(clientExceptionMessage('[Telegram] Shift terbuka tersimpan; broadcast gagal',$deliveryError));}
                                }
                                $replyText="✅ *SHIFT BERHASIL DIBUKA*\n\n👥 Petugas: *{$displayName}*\n💵 Kas awal: *Rp ".tamasyaTelegramFormatAmount($startingCash)."*\n\nTransaksi kedua peserta akan masuk ke sesi kas yang sama.";
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                            }catch(TelegramDuplicateOperationException $duplicate){
                                if($pdo->inTransaction())$pdo->rollBack();
                                $replyText='ℹ️ Perintah buka shift ini sudah diproses; shift tidak digandakan.';
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                            }catch(Throwable $shiftOpenError){
                                if($pdo->inTransaction())$pdo->rollBack();
                                if(isset($shiftOperationId))failTelegramMutation($pdo,$shiftOperationId,$shiftOpenError);
                                $replyText='⚠️ '.clientExceptionMessage('Shift tidak dapat dibuka',$shiftOpenError);
                                $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Mulai ulang','callback_data'=>'buka_shift_time:'.($context['shiftTime']??'pagi')],['text'=>'❌ Batalkan','callback_data'=>'cancel_buka_shift']]]];
                            }
                        }
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_tutup_shift_cash') {
                    $actualCash=tamasyaTelegramParseMoney((string)$command)??-1;
                    if ($actualCash < 0) {
                        $replyText = "⚠️ *NOMINAL TIDAK VALID!*\n\nSilakan masukkan jumlah nominal uang fisik aktual di laci kas saat ini berupa angka saja (contoh: `350000` atau `0` jika laci kosong):";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_tutup_shift"]]
                            ]
                        ];
                    } else {
                        $context = json_decode($currentCtx, true);
                        if ($context) {
                            $context['actualCash'] = $actualCash;
                            try{
                                $fresh=getShiftFinancials($pdo,$loggedInStaff,$context['shiftTime']??'',$context['shiftDate']??'',$context['companionId']??'none',true,(string)($context['shiftId']??''));
                            }catch(Throwable $previewError){
                                throw new RuntimeException('Perhitungan shift berubah/gagal. Buka ulang Tutup Shift.',0,$previewError);
                            }
                            $expectedCash=(float)$fresh['expectedCash'];$context['expectedCash']=$expectedCash;
                            $variance = $actualCash - $expectedCash;
                            $settings=$pdo->query("SELECT cash_variance_tolerance FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
                            $tolerance=max(0.0,(float)($settings['cash_variance_tolerance']??0));
                            $role=strtolower((string)($loggedInStaff['role']??''));

                            $varianceText = "PAS / COCOK (Rp 0)";
                            if ($variance > 0) {
                                $varianceText = "LEBIH (+Rp " . tamasyaTelegramFormatAmount($variance) . ")";
                            } else if ($variance < 0) {
                                $varianceText = "KURANG (Rp " . tamasyaTelegramFormatAmount(abs($variance)) . ")";
                            }

                            $newCtx=json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            $needsOverride=abs(round($variance,2))>$tolerance&&hasCapability($loggedInStaff,'approve_sensitive_actions',['admin','manager']);
                            $pdo->prepare("UPDATE staff SET telegram_state=?,telegram_context=? WHERE id=?")
                                ->execute([$needsOverride?'waiting_for_tutup_shift_override_reason':'waiting_for_tutup_shift_notes',$newCtx,$loggedInStaff['id']]);
                            $replyText="🔍 *REKONSILIASI KAS SEMENTARA*\n\n💵 Fisik Aktual: *Rp ".tamasyaTelegramFormatAmount($actualCash)."*\n📊 Seharusnya: *Rp ".tamasyaTelegramFormatAmount($expectedCash)."*\n⚠️ Selisih Laci: *{$varianceText}*\n\n";
                            if($needsOverride){
                                $replyText.="Ketik alasan override minimal 5 karakter. Penutupan belum dilakukan.";
                            }elseif(round($variance,2)!=0.0){
                                $replyText.="Ketik keterangan uang kurang/lebih minimal 5 karakter. ".(abs($variance)>$tolerance?"Shift akan ditutup dengan selisih tercatat dan diajukan untuk pemeriksaan Admin/Manager di web.":"Selisih berada dalam toleransi dan tetap dicatat.");
                            }else{
                                $replyText.="Ketik catatan serah terima atau kirim tanpa catatan.";
                            }
                            $buttons=[];
                            if(round($variance,2)==0.0)$buttons[]=[['text'=>'✅ Kirim Tanpa Catatan','callback_data'=>'tutup_shift_done:no_notes']];
                            $buttons[]=[['text'=>'❌ Batalkan','callback_data'=>'cancel_tutup_shift']];
                            $replyMarkup=['inline_keyboard'=>$buttons];
                        } else {
                            $replyText = "⚠️ Terjadi kesalahan memuat data sesi Tutup Shift.";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]
                                ]
                            ];
                            $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                            $stmtClear->execute([$loggedInStaff['id']]);
                        }
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_tutup_shift_override_reason') {
                    $overrideReason=trim((string)$command);
                    $context=json_decode((string)$currentCtx,true);
                    if(!is_array($context)){
                        $replyText="⚠️ Data sesi tutup shift tidak ditemukan; database tidak diubah.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    }elseif(!hasCapability($loggedInStaff,'approve_sensitive_actions',['admin','manager'])){
                        $replyText="🔒 Hanya Admin/Manager yang boleh memberikan alasan override selisih kas.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_tutup_shift']]]];
                    }elseif(tamasyaStringLength($overrideReason)<5){
                        $replyText="⚠️ Alasan override minimal 5 karakter. Jelaskan penyebab selisih kas sebelum shift dapat ditutup.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_tutup_shift']]]];
                    }else{
                        $context['overrideReason']=$overrideReason;
                        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_tutup_shift_notes',telegram_context=? WHERE id=?")
                            ->execute([json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                        $replyText="✅ *ALASAN OVERRIDE TERSIMPAN*

📝 {$overrideReason}

Ketik catatan serah-terima tambahan, atau kirim tanpa catatan.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Kirim Tanpa Catatan','callback_data'=>'tutup_shift_done:no_notes']],[['text'=>'❌ Batalkan','callback_data'=>'cancel_tutup_shift']]]];
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_tutup_shift_notes') {
                    $notesText = trim($command);
                    $context = json_decode((string)$currentCtx,true);
                    if (!is_array($context)) {
                        $replyText = "⚠️ Data sesi tutup shift tidak ditemukan; database tidak diubah.";
                    } else {
                        $operationId = $telegramUpdateOperationId . '_close_shift';
                        try {
                            $result = finalizeTelegramShiftReport($pdo,$operationId,$loggedInStaff,$context,$notesText,'message');
                            $deliveryWarning='';
                            try{broadcastTelegramNotification($pdo,$result['broadcastText'],true);}
                            catch(Throwable $deliveryError){$deliveryWarning="\n\n⚠️ Data tersimpan, tetapi notifikasi grup gagal terkirim.";error_log(clientExceptionMessage('[Telegram] Broadcast shift gagal setelah commit',$deliveryError));}
                            $replyText = "✅ *LAPORAN SHIFT TERSIMPAN*

Shift ditutup di database server dengan catatan Anda. Selisih: *{$result['varianceText']}*.".(!empty($result['needsReview'])?"\n\n🔎 Selisih menunggu pemeriksaan Admin/Manager di web.":"").$deliveryWarning;
                        } catch (TelegramDuplicateOperationException $duplicate) {
                            $replyText = "ℹ️ Laporan shift ini sudah diproses; database tidak digandakan.";
                        } catch (Throwable $shiftError) {
                            $replyText = "⚠️ *SHIFT BELUM DITUTUP*

" . clientExceptionMessage('Sinkronisasi database gagal',$shiftError);
                        }
                    }
                    $replyMarkup = ["inline_keyboard" => [[["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]]]];
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_guest_name') {
                    $roomNumber = $currentCtx;
                    $guestName = str_replace(':', '__COLON__', $command); // The text they typed is the guest name, escaped for safety

                    $newCtx = $roomNumber . ":" . $guestName;
                    $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_ktp', telegram_context = ? WHERE id = ?");
                    $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                    $decodedGuestName = str_replace('__COLON__', ':', $guestName);
                    $replyText = "👤 Nama Tamu: *{$decodedGuestName}*\n" .
                                 "🚪 Kamar: *{$roomNumber}*\n\n" .
                                 "📸 *HARAP UPLOAD FOTO KTP TAMU*\n" .
                                 "Silakan kirimkan foto KTP langsung ke chat ini (sebagai Gambar/Foto).\n\n" .
                                 "Teks biasa tidak akan disimpan sebagai KTP.";
                    $ktpKeyboard = [];
                    if (!empty($GLOBALS['is_telegram_simulation'])) {
                        $ktpKeyboard[] = [["text" => "📸 Simulasi Upload KTP", "callback_data" => "simulate_ktp_upload"]];
                    }
                    $ktpKeyboard[] = [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]];
                    $replyMarkup = ["inline_keyboard" => $ktpKeyboard];
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_ktp') {
                    $ktpPhotoPath = '';
                    if ($photoMsg) {
                        // Extract file_id of largest photo
                        $largestPhoto = end($photoMsg);
                        $fileId = trim((string)($largestPhoto['file_id'] ?? ''));
                        
                        // Download file from Telegram
                        if (!empty($token)) {
                            $fileInfoResult = telegramApiCall($token,'getFile',['file_id'=>$fileId]);
                            $filePath = (string)($fileInfoResult['data']['result']['file_path'] ?? '');
                            if (!empty($fileInfoResult['ok']) && $filePath !== '' && !str_contains($filePath,'..')) {
                                    $fileUrl = getTelegramApiBaseUrl() . "/file/bot{$token}/" . ltrim($filePath,'/');
                                    
                                    $uploadsDirectory = TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'uploads';
                                    if (!is_dir($uploadsDirectory) && !@mkdir($uploadsDirectory, 0750, true) && !is_dir($uploadsDirectory)) {
                                        error_log('[Telegram KTP] Folder uploads tidak dapat dibuat.');
                                    }
                                    $downloadContext = stream_context_create(['http'=>['timeout'=>12,'follow_location'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
                                    $fileContent = @file_get_contents($fileUrl,false,$downloadContext,0,8 * 1024 * 1024 + 1);
                                    $mime = null;
                                    if ($fileContent !== false && strlen($fileContent) > 0 && strlen($fileContent) <= 8 * 1024 * 1024 && function_exists('finfo_open')) {
                                        $fi=finfo_open(FILEINFO_MIME_TYPE);$mime=$fi?finfo_buffer($fi,$fileContent):null;if($fi)unset($fi);
                                    }
                                    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                                    $ext=$extensions[$mime]??null;
                                    $localFileName = $ext ? ('ktp_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext) : '';
                                    $localRelativePath = $localFileName !== '' ? ('uploads/' . $localFileName) : '';
                                    $localAbsolutePath = $localFileName !== '' ? ($uploadsDirectory . DIRECTORY_SEPARATOR . $localFileName) : '';
                                    if ($localAbsolutePath !== '' && @file_put_contents($localAbsolutePath, $fileContent, LOCK_EX) !== false) {
                                        @chmod($localAbsolutePath, 0640);
                                        $ktpPhotoPath = '/' . $localRelativePath;
                                    } else {
                                        // Jangan pernah menyimpan URL file Telegram karena URL tersebut
                                        // memuat bot token. Simpan file_id yang aman untuk retry admin.
                                        $ktpPhotoPath = "telegram_file_id:" . $fileId;
                                        error_log('[Telegram KTP] File gagal disimpan lokal; hanya file_id yang disimpan.');
                                    }
                            } else {
                                $ktpPhotoPath = "telegram_file_id:" . $fileId;
                            }
                        } else {
                            $ktpPhotoPath = "telegram_file_id:" . $fileId;
                        }
                    } elseif (!empty($GLOBALS['is_telegram_simulation'])) {
                        $ktpPhotoPath = '/uploads/ktp_simulated_placeholder.jpg';
                    }

                    if ($ktpPhotoPath !== '') {
                        try {
                            $ktpPhotoPath = normalizeBookingIdentityReferenceForStorage($pdo,$ktpPhotoPath,!empty($GLOBALS['is_telegram_simulation']));
                        } catch (Throwable $ktpValidationError) {
                            error_log(clientExceptionMessage('[Telegram KTP] Referensi ditolak',$ktpValidationError));
                            $ktpPhotoPath = '';
                        }
                    }

                    // Context could contain $roomNumber:$guestName or $roomNumber:Walk-in:1:$paymentStatus
                    $parts = explode(":", $currentCtx);
                    if ($ktpPhotoPath === '') {
                        $replyText = "⚠️ *FOTO KTP BELUM VALID*\n\nKirim foto KTP sebagai gambar JPEG, PNG, atau WEBP maksimal 8 MB. Teks dan URL tidak disimpan sebagai identitas tamu.";
                        $replyMarkup = ["inline_keyboard" => [[['text'=>'❌ Batalkan Proses','callback_data'=>'cancel_booking_process']]]];
                    } elseif (count($parts) >= 4 && $parts[1] === 'Walk-in') {
                        // Quick walk-in flow is completing!
                        $roomNumber = $parts[0];
                        $guestName = $parts[1];
                        $nights = (int)$parts[2];
                        $paymentStatus = $parts[3];

                        if ($paymentStatus === 'paid') {
                            $newCtx = $roomNumber . ":" . $guestName . ":" . $ktpPhotoPath . ":" . $nights;
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_method', telegram_context = ? WHERE id = ?");
                            $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                            try {
                                $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 ORDER BY name ASC");
                                $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            } catch (Throwable $e) {
                                $bankAccounts = [];
                            }

                            $inlineKeyboard = [
                                [
                                    ["text" => "💵 Tunai", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:cash:none"]
                                ]
                            ];

                            foreach ($bankAccounts as $bank) {
                                $icon = "🏦";
                                if ($bank['type'] === 'edc_qris') {
                                    $icon = "📱";
                                } elseif ($bank['type'] === 'edc_card') {
                                    $icon = "💳";
                                }

                                $inlineKeyboard[] = [
                                    ["text" => $icon . " " . $bank['name'], "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:" . ($bank['type'] === 'edc_qris' ? 'qris' : 'transfer') . ":" . $bank['id']]
                                ];
                            }

                            $inlineKeyboard[] = [
                                ["text" => "🌓 Split (Tunai & Transfer/QRIS)", "callback_data" => "r_sell_split_select:" . $roomNumber . ":" . $guestName . ":" . $nights]
                            ];

                            $inlineKeyboard[] = [
                                ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                            ];

                            $replyText = "💳 *PILIH METODE PEMBAYARAN*\n\n" .
                                         "🚪 Kamar: *{$roomNumber}*\n" .
                                         "👤 Tamu: *{$guestName}*\n" .
                                         "📅 Durasi: *{$nights} Malam*\n\n" .
                                         "Silakan pilih metode pembayaran untuk transaksi Walk-in ini:";
                            $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        } else {
                            $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                            $stmtRoom->execute([$roomNumber]);
                            $room = $stmtRoom->fetch();

                            if ($room && tamasyaDeriveRoomOperationalStatus(getRoomOperationalBlockers($pdo,$roomNumber,false)) === 'available') {
                                try {
                                    $checkIn = date("Y-m-d");
                                    $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                    $vatRate = resolveConfiguredTaxRate($pdo, 'Direct', 'room', 0, $checkIn);
                                    $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                                    $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                    $baseAmount = $inclusive['baseAmount'];
                                    $vatAmount = $inclusive['taxAmount'];
                                    $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                        'guestName' => str_replace('__COLON__', ':', $guestName),
                                        'roomNumber' => $roomNumber,
                                        'roomType' => $room['type'],
                                        'checkIn' => $checkIn,
                                        'checkOut' => $checkOut,
                                        'totalAmount' => $totalAmount,
                                        'paymentStatus' => 'unpaid',
                                        'bookingSource' => 'Direct',
                                            'lifecycleIntent' => 'check_in_now',
                                        'ktpPhoto' => str_replace('__COLON__', ':', $ktpPhotoPath ?? '')
                                    ], 'telegram', $telegramUpdateOperationId . ':booking');
                                    $bookingId = (string)$bookingResult['bookingId'];
                                    $vatRate = (float)$bookingResult['vatRate'];
                                    $vatAmount = (float)$bookingResult['vatAmount'];
                                    $totalAmount = (float)$bookingResult['totalAmount'];
                                    $baseAmount = max(0, $totalAmount - $vatAmount);

                                    $replyText = "🎉 *PROSES SEWA KAMAR WALK-IN BERHASIL*! 🎉\n\n" .
                                                 "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                                 "👤 Tamu: *{$guestName}*\n" .
                                                 "📸 KTP: *Terunggah*\n" .
                                                 "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                 "📅 Tanggal Keluar: *{$checkOut}* (*{$nights} Malam*)\n" .
                                                 "💵 Tarif Kamar: *Rp " . tamasyaTelegramFormatAmount($baseAmount) . "*\n" .
                                                 "🧾 Pajak PBJT (" . $vatRate . "%): *Rp " . tamasyaTelegramFormatAmount($vatAmount) . "*\n" .
                                                 "💰 Total: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                                 "💳 Status: *UNPAID*\n" .
                                                 "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                 "Data otomatis tersinkron ke dasbor hotel!";
                                } catch (Exception $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $replyText = clientExceptionMessage("❌ Gagal memproses Walk-in", $e);
                                }
                            } else {
                                $replyText = "⚠️ Gagal! Kamar {$roomNumber} sudah tidak tersedia.";
                            }

                            // Clear state
                            $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                            $stmtClear->execute([$loggedInStaff['id']]);
                        }
                    } else {
                        // Custom booking flow transitions from waiting_for_ktp to waiting_for_nights
                        $roomNumber = $parts[0];
                        $guestName = $parts[1];

                        $newCtx = $roomNumber . ":" . $guestName . ":" . str_replace(':', '__COLON__', $ktpPhotoPath);
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_nights', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $replyText = "👤 Nama Tamu: *{$guestName}*\n" .
                                     "🚪 Kamar: *{$roomNumber}*\n" .
                                     "📸 KTP: *Terunggah*\n\n" .
                                     "Berapa malam tamu akan menginap?\n" .
                                     "*(Silakan ketik angka saja, contoh: `1` atau `2`)*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "🕒 Durasi Terbuka (Durasi Terbuka / Tanpa Checkout)", "callback_data" => "r_sell_open_ended_select:" . $roomNumber . ":" . $guestName]],
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_nights') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0];
                    $guestName = $parts[1];
                    $ktpPhotoPath = $parts[2];

                    $nights = preg_match('/^[1-9][0-9]*$/',trim((string)$command)) ? (int)$command : 0;
                    if ($nights <= 0) {
                        $replyText = "⚠️ Input tidak valid! Harap ketik jumlah malam dengan angka bulat positif (contoh: `1` atau `3`):\n\n" .
                                     "Tamu: *{$guestName}*\n" .
                                     "Kamar: *{$roomNumber}*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    } else {
                        $newCtx = $roomNumber . ":" . $guestName . ":" . $ktpPhotoPath . ":" . $nights;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_status', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $stmtStaff = $pdo->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
                        $stmtStaff->execute([$loggedInStaff['id']]);
                        $updatedStaff = $stmtStaff->fetch();

                        $conf = renderBookingConfirmation($pdo, $updatedStaff);
                        $replyText = $conf['text'];
                        $replyMarkup = $conf['markup'];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_sell_dp_amount') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0] ?? '';
                    $guestName = $parts[1] ?? '';
                    $ktpPhotoPath = $parts[2] ?? '';

                    $dpAmount = tamasyaTelegramParseMoney((string)$command)??-1;
                    if ($dpAmount < 0) {
                        $replyText = "⚠️ Nominal tidak valid! Harap ketik angka nominal uang panjar (DP) saja (contoh: `250000`):";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    } else {
                        $newCtx = $roomNumber . ":" . $guestName . ":" . $ktpPhotoPath . ":" . $dpAmount;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_sell_dp_method', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        try {
                            $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 AND type IN ('bank','edc_qris') ORDER BY name ASC");
                            $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        } catch (Throwable $e) {
                            $bankAccounts = [];
                        }

                        $inlineKeyboard = [
                            [
                                ["text" => "💵 Tunai", "callback_data" => "r_sell_dp_confirm:" . $roomNumber . ":" . $guestName . ":" . $dpAmount . ":cash:none"]
                            ]
                        ];

                        foreach ($bankAccounts as $bank) {
                            $icon = "🏦";
                            if ($bank['type'] === 'edc_qris') {
                                $icon = "📱";
                            } elseif ($bank['type'] === 'edc_card') {
                                $icon = "💳";
                            }

                            $inlineKeyboard[] = [
                                ["text" => $icon . " " . $bank['name'], "callback_data" => "r_sell_dp_confirm:" . $roomNumber . ":" . $guestName . ":" . $dpAmount . ":" . ($bank['type'] === 'edc_qris' ? 'qris' : 'transfer') . ":" . $bank['id']]
                            ];
                        }

                        $inlineKeyboard[] = [
                            ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                        ];

                        $replyText = "💳 *PILIH METODE PEMBAYARAN DP*\n\n" .
                                     "• Kamar: *{$roomNumber}*\n" .
                                     "• Tamu: *{$guestName}*\n" .
                                     "• Uang Panjar (DP): *Rp " . tamasyaTelegramFormatAmount($dpAmount) . "*\n\n" .
                                     "Silakan pilih metode pembayaran DP untuk transaksi durasi terbuka ini:";
                        $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_sell_dp_method') {
                    $replyText='💳 Pilih tombol metode DP yang ditampilkan sebelumnya, atau /batal untuk mulai ulang.';$stateProcessed=true;
                } elseif ($currentState === 'waiting_for_nego_price') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0] ?? '';
                    $guestName = $parts[1] ?? '';
                    $ktpPhotoPath = $parts[2] ?? '';
                    $nights = isset($parts[3]) ? (int)$parts[3] : 1;
                    
                    $bookingSource = null;
                    $remainingParts = array_slice($parts, 4);
                    foreach ($remainingParts as $part) {
                        if (!is_numeric($part) && !empty($part) && $part !== 'splitbank') {
                            $bookingSource = $part;
                        }
                    }

                    $negoPrice = tamasyaTelegramParseMoney((string)$command)??-1;
                    if ($negoPrice <= 0) {
                        $replyText = "⚠️ Nominal tidak valid! Harap ketik angka nominal harga nego saja (contoh: `350000`):";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    } else {
                        // Rebuild context
                        $newCtx = $roomNumber . ":" . $guestName . ":" . $ktpPhotoPath . ":" . $nights;
                        if ($bookingSource) {
                            $newCtx .= ":" . $bookingSource;
                        }
                        $newCtx .= ":" . $negoPrice;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_status', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $stmtStaff = $pdo->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
                        $stmtStaff->execute([$loggedInStaff['id']]);
                        $updatedStaff = $stmtStaff->fetch();

                        $conf = renderBookingConfirmation($pdo, $updatedStaff);
                        $replyText = $conf['text'];
                        $replyMarkup = $conf['markup'];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_payment_status') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0];
                    $guestName = $parts[1];
                    $ktpPhotoPath = $parts[2];
                    $nights = (int)$parts[3];

                    $bookingSource = 'Direct';
                    $customPrice = null;
                    $remainingParts = array_slice($parts, 4);
                    $i = 0;
                    while ($i < count($remainingParts)) {
                        if ($remainingParts[$i] === 'splitbank') {
                            $i += 2;
                        } elseif (is_numeric($remainingParts[$i])) {
                            $customPrice = (float)$remainingParts[$i];
                            $i++;
                        } elseif (!empty($remainingParts[$i])) {
                            $bookingSource = $remainingParts[$i];
                            $i++;
                        } else {
                            $i++;
                        }
                    }

                    $paymentStatus = null;
                    $normalizedInput = strtolower($command);
                    if (in_array($normalizedInput, ["lunas", "paid", "1", "yes", "ya", "sudah"])) {
                        $paymentStatus = "paid";
                    } elseif (in_array($normalizedInput, ["belum", "unpaid", "2", "no", "tidak", "belum lunas"])) {
                        $paymentStatus = "unpaid";
                    }

                    if ($paymentStatus) {
                        if ($paymentStatus === 'paid') {
                            $newCtxParts = [$roomNumber, $guestName, $ktpPhotoPath, $nights];
                            if ($customPrice !== null) {
                                $newCtxParts[] = $customPrice;
                            }
                            $newCtxParts[] = $bookingSource;
                            $newCtx = implode(":", $newCtxParts);
                            
                            $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_method', telegram_context = ? WHERE id = ?");
                            $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                            try {
                                $stmtBank = $pdo->query("SELECT * FROM bank_accounts WHERE isActive = 1 ORDER BY name ASC");
                                $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            } catch (Throwable $e) {
                                $bankAccounts = [];
                            }

                            $inlineKeyboard = [
                                [
                                    ["text" => "💵 Tunai", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:cash:none"]
                                ]
                            ];

                            foreach ($bankAccounts as $bank) {
                                $icon = "🏦";
                                if ($bank['type'] === 'edc_qris') {
                                    $icon = "📱";
                                } elseif ($bank['type'] === 'edc_card') {
                                    $icon = "💳";
                                }

                                $inlineKeyboard[] = [
                                    ["text" => $icon . " " . $bank['name'], "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:" . ($bank['type'] === 'edc_qris' ? 'qris' : 'transfer') . ":" . $bank['id']]
                                ];
                            }

                            if (tamasyaIsExplicitOtaSourceLabel($bookingSource)) {
                                $inlineKeyboard[] = [
                                    ["text" => "🌐 OTA (Online Travel Agent)", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid:ota:ota_receivable"]
                                ];
                            }

                            $inlineKeyboard[] = [
                                ["text" => "🌓 Split (Tunai & Transfer/QRIS)", "callback_data" => "r_sell_split_select:" . $roomNumber . ":" . $guestName . ":" . $nights]
                            ];

                            $inlineKeyboard[] = [
                                ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                            ];

                            $replyText = "💳 *PILIH METODE PEMBAYARAN*\n\n" .
                                         "• Nama Tamu: *{$guestName}*\n" .
                                         "• Kamar: *{$roomNumber}*\n" .
                                         "• Durasi: *{$nights} Malam*\n\n" .
                                         "Silakan pilih metode pembayaran untuk transaksi ini:";
                            $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                        } else {
                            $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                            $stmtRoom->execute([$roomNumber]);
                            $room = $stmtRoom->fetch();

                            if ($room && tamasyaDeriveRoomOperationalStatus(getRoomOperationalBlockers($pdo,$roomNumber,false)) === 'available') {
                                try {
                                    $checkIn = date("Y-m-d");
                                    $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                    $vatRate = resolveConfiguredTaxRate($pdo, $bookingSource, 'room', 0, $checkIn);
                                    if ($customPrice !== null && $customPrice > 0) {
                                        $totalAmount = $customPrice;
                                        $taxBreakdown = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                        $baseAmount = (float)$taxBreakdown['baseAmount'];
                                        $vatAmount = (float)$taxBreakdown['taxAmount'];
                                    } else {
                                        $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                                        $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                        $baseAmount = $inclusive['baseAmount'];
                                        $vatAmount = $inclusive['taxAmount'];
                                    }
                                    $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                        'guestName' => str_replace('__COLON__', ':', $guestName),
                                        'roomNumber' => $roomNumber,
                                        'roomType' => $room['type'],
                                        'checkIn' => $checkIn,
                                        'checkOut' => $checkOut,
                                        'totalAmount' => $totalAmount,
                                        'paymentStatus' => 'unpaid',
                                        'bookingSource' => $bookingSource,
                                                'lifecycleIntent' => 'check_in_now',
                                        'ktpPhoto' => str_replace('__COLON__', ':', $ktpPhotoPath ?? '')
                                    ], 'telegram', $telegramUpdateOperationId . ':booking');
                                    $bookingId = (string)$bookingResult['bookingId'];
                                    $vatRate = (float)$bookingResult['vatRate'];
                                    $vatAmount = (float)$bookingResult['vatAmount'];
                                    $totalAmount = (float)$bookingResult['totalAmount'];
                                    $baseAmount = max(0, $totalAmount - $vatAmount);

                                    $replyText = "🎉 *PROSES SEWA KAMAR BERHASIL (Melalui Chat)*! 🎉\n\n" .
                                                 "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                                 "👤 Tamu: *{$guestName}*\n" .
                                                 "📸 KTP: *Terunggah*\n" .
                                                 "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                 "📅 Tanggal Keluar: *{$checkOut}* (*{$nights} Malam*)\n" .
                                                 "💵 Tarif Kamar: *Rp " . tamasyaTelegramFormatAmount($baseAmount) . "*\n" .
                                                 ($vatRate > 0
                                                    ? "🧾 Pajak PBJT ({$vatRate}%): *Rp " . tamasyaTelegramFormatAmount($vatAmount) . "*\n"
                                                    : "🧾 Pajak PBJT: *Rp 0* (sesuai tax_rules aktif)\n") .
                                                 "💰 Total: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                                 "💳 Status: *UNPAID*\n" .
                                                 "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                 "Data otomatis tersinkron ke dasbor hotel!";
                                } catch (Exception $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $replyText = clientExceptionMessage("❌ Gagal memproses", $e);
                                }
                            } else {
                                $replyText = "⚠️ Gagal! Kamar {$roomNumber} sudah tidak tersedia.";
                            }

                            // Clear state
                            $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                            $stmtClear->execute([$loggedInStaff['id']]);
                        }
                    } else {
                        $replyText = "⚠️ Input tidak valid! Silakan pilih tombol atau ketik `lunas` / `belum lunas`.";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [
                                    ["text" => "🟢 Lunas", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid"]
                                ],
                                [
                                    ["text" => "🔴 Belum Lunas", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":unpaid"]
                                ],
                                [
                                    ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                                ]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_split_cash') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0] ?? '';
                    $guestName = str_replace('__COLON__', ':', $parts[1] ?? '');
                    $ktpPhotoPath = str_replace('__COLON__', ':', $parts[2] ?? '');
                    $nights = isset($parts[3]) ? (int)$parts[3] : 1;
                    
                    $bankId = null;
                    $customPrice = null;
                    $bookingSource = 'Direct';
                    
                    // Parse dynamically
                    $remainingParts = array_slice($parts, 4);
                    $i = 0;
                    while ($i < count($remainingParts)) {
                        if ($remainingParts[$i] === 'splitbank') {
                            $bankId = $remainingParts[$i+1] ?? null;
                            $i += 2;
                        } elseif (is_numeric($remainingParts[$i])) {
                            $customPrice = (float)$remainingParts[$i];
                            $i++;
                        } elseif (!empty($remainingParts[$i])) {
                            $bookingSource = $remainingParts[$i];
                            $i++;
                        } else {
                            $i++;
                        }
                    }
                    
                    $splitCashAmount = tamasyaTelegramParseMoney((string)$command)??-1;
                    
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$roomNumber]);
                    $room = $stmtRoom->fetch();
                    
                    if (!$room) {
                        $replyText = "❌ Kamar {$roomNumber} tidak ditemukan!";
                        $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        $stmtClear->execute([$loggedInStaff['id']]);
                    } else {
                        $vatRate = resolveConfiguredTaxRate($pdo, $bookingSource, 'room', 0, date('Y-m-d'));
                        if ($customPrice !== null && $customPrice > 0) {
                            $totalAmount = $customPrice;
                            $taxBreakdown = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                            $baseAmount = (int)round($taxBreakdown['baseAmount']);
                            $vatAmount = (int)round($taxBreakdown['taxAmount']);
                        } else {
                            $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                            $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                            $baseAmount = $inclusive['baseAmount'];
                            $vatAmount = $inclusive['taxAmount'];
                        }
                        
                        if ($splitCashAmount <= 0 || $splitCashAmount >= $totalAmount) {
                            $replyText = "⚠️ Nominal Tunai harus lebih besar dari 0 dan kurang dari total biaya (*Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*).\n\n" .
                                         "Silakan masukkan kembali nominal *TUNAI / CASH* yang dibayar tamu:";
                        } else {
                            $splitTransferAmount = $totalAmount - $splitCashAmount;
                            
                            try {
                                $checkIn = date("Y-m-d");
                                $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                    'guestName' => $guestName,
                                    'roomNumber' => $roomNumber,
                                    'roomType' => $room['type'],
                                    'checkIn' => $checkIn,
                                    'checkOut' => $checkOut,
                                    'totalAmount' => $totalAmount,
                                    'paymentStatus' => 'paid',
                                    'paymentMethod' => 'split',
                                    'paymentDate' => $checkIn,
                                    'bookingSource' => $bookingSource,
                                                'lifecycleIntent' => 'check_in_now',
                                    'ktpPhoto' => $ktpPhotoPath,
                                    'isSplitPayment' => 1,
                                    'splitCashAmount' => $splitCashAmount,
                                    'splitTransferAmount' => $splitTransferAmount,
                                    'splitTransferBankAccountId' => $bankId
                                ], 'telegram', $telegramUpdateOperationId . ':booking');
                                $bookingId = (string)$bookingResult['bookingId'];
                                $vatRate = (float)$bookingResult['vatRate'];
                                $vatAmount = (float)$bookingResult['vatAmount'];
                                $totalAmount = (float)$bookingResult['totalAmount'];
                                $baseAmount = max(0, $totalAmount - $vatAmount);
                                $bankTypeLabel = 'Transfer';
                                if (!empty($bankId)) {
                                    $accStmt = $pdo->prepare("SELECT type FROM bank_accounts WHERE id = ?");
                                    $accStmt->execute([$bankId]);
                                    $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($accRow && $accRow['type'] === 'edc_qris') $bankTypeLabel = 'QRIS';
                                }
                                
                                // Clear state
                                $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                                
                                $replyText = "🎉 *PROSES SPLIT PAYMENT BERHASIL*! 🎉\n\n" .
                                             "🔑 Kamar: *{$roomNumber}* (*{$room['type']}*)\n" .
                                             "👤 Tamu: *{$guestName}*\n" .
                                             "📅 Durasi: *{$nights} Malam*\n\n" .
                                             "💰 Rincian Pembayaran:\n" .
                                             "• Total Tagihan: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                             "• Porsi Tunai: *Rp " . tamasyaTelegramFormatAmount($splitCashAmount) . "*\n" .
                                             "• Porsi Non-Tunai (" . $bankTypeLabel . "): *Rp " . tamasyaTelegramFormatAmount($splitTransferAmount) . "*\n\n" .
                                             "Data otomatis tersinkron ke dasbor hotel!";
                                             
                                $replyMarkup = [
                                    "inline_keyboard" => [
                                        [["text" => "🛒 Jual Kamar Lainnya", "callback_data" => "sell_room_list"]],
                                        [["text" => "📊 Cek Status Kamar Live", "callback_data" => "room_list"]]
                                    ]
                                ];
                                
                            } catch (Exception $e) {
                                if ($pdo->inTransaction()) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                }
                                $replyText = clientExceptionMessage("❌ Gagal memproses split payment", $e);
                                
                                // Reset state so the bot does not freeze/get stuck!
                                $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                                
                                $replyMarkup = [
                                    "inline_keyboard" => [
                                        [["text" => "🛒 Mulai Jual Kamar Lagi", "callback_data" => "sell_room_list"]]
                                    ]
                                ];
                            }
                        }
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_booking_source_text') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0] ?? '';
                    $guestName = $parts[1] ?? '';
                    $ktpPhotoPath = $parts[2] ?? '';
                    $nights = isset($parts[3]) ? (int)$parts[3] : 1;
                    
                    $bookingSource = str_replace(":", "", trim($command));
                    if (empty($bookingSource)) {
                        $bookingSource = 'Direct';
                    }
                    
                    $customPrice = null;
                    $bankId = null;
                    $remainingParts = array_slice($parts, 4);
                    $i = 0;
                    while ($i < count($remainingParts)) {
                        if ($remainingParts[$i] === 'splitbank') {
                            $bankId = $remainingParts[$i+1] ?? null;
                            $i += 2;
                        } elseif (is_numeric($remainingParts[$i])) {
                            $customPrice = (float)$remainingParts[$i];
                            $i++;
                        } else {
                            $i++; // ignore existing booking source, we are replacing it
                        }
                    }
                    
                    $newCtxParts = [$roomNumber, $guestName, $ktpPhotoPath, $nights];
                    if ($customPrice !== null) {
                        $newCtxParts[] = $customPrice;
                    }
                    if ($bankId !== null) {
                        $newCtxParts[] = "splitbank";
                        $newCtxParts[] = $bankId;
                    }
                    $newCtxParts[] = $bookingSource;
                    
                    $newCtx = implode(":", $newCtxParts);
                    
                    $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_payment_status', telegram_context = ? WHERE id = ?");
                    $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);
                    
                    $stmtStaff = $pdo->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
                    $stmtStaff->execute([$loggedInStaff['id']]);
                    $updatedStaff = $stmtStaff->fetch();
                    
                    $conf = renderBookingConfirmation($pdo, $updatedStaff);
                    $replyText = $conf['text'];
                    $replyMarkup = $conf['markup'];
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_charge_payment') {
                    try{$ctx=tamasyaTelegramChargeContext($loggedInStaff,(string)(json_decode((string)$currentCtx,true)['nonce']??''));$replyText='💳 Gunakan tombol untuk memilih Tunai, Transfer, atau QRIS dan konfirmasi pembayaran.';$replyMarkup=tamasyaTelegramChargeMarkup($ctx);}
                    catch(Throwable $e){$replyText=clientExceptionMessage('Sesi pembayaran tidak valid',$e);}
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_receipt_email') {
                    // Nota tamu atau laporan grup dikirim ke email yang diketik staf.
                    // Sesuai keputusan izin: penjualan ATAU rawat-inap; owner/finance tidak.
                    try {
                        $ctx = json_decode((string)$currentCtx, true);
                        if (!is_array($ctx) || (int)($ctx['expiresAt'] ?? 0) < time()) throw new RuntimeException('Sesi kirim email sudah kedaluwarsa. Mulai ulang dari tombol kirim nota.');
                        if (hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']) !== true
                            && hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']) !== true) {
                            throw new DomainException('Peran ini tidak berhak mengirim email resmi.',403);
                        }
                        $target = trim((string)$command);
                        if (($ctx['mode'] ?? '') === 'company') {
                            $result = tamasyaSendGroupReportEmail($pdo, $loggedInStaff, (string)($ctx['groupId'] ?? ''), $target, 'telegram', $telegramUpdateOperationId);
                            $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?")->execute([$loggedInStaff['id']]);
                            $replyText = "✅ *LAPORAN GRUP TERKIRIM*\n\nLaporan ".$result['reportReference']." sudah dikirim ke ".$result['masked']." (via ".$result['method'].").\n".($result['deliveryNote']??'');
                            $replyMarkup = ['inline_keyboard'=>[[['text'=>'🏨 Buka Detail Grup','callback_data'=>'mr_detail:'.(string)($ctx['groupId'] ?? '')]]]];
                        } else {
                            $bookingId = (string)($ctx['bookingId'] ?? '');
                            $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                            $stmtBooking->execute([$bookingId]);
                            $booking = $stmtBooking->fetch();
                            if (!$booking) throw new InvalidArgumentException('Data sewa tidak ditemukan.');
                            $result = tamasyaSendBookingReceiptEmail($pdo, $loggedInStaff, $booking, $target, 'telegram', $telegramUpdateOperationId);
                            $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?")->execute([$loggedInStaff['id']]);
                            $replyText = "✅ *NOTA TERKIRIM*\n\nNota ".$result['invoiceNumber']." sudah dikirim ke ".$result['masked']." (via ".$result['method'].").\n".($result['deliveryNote']??'');
                            $replyMarkup = ['inline_keyboard'=>[[['text'=>'🧾 Cetak Nota Thermal','callback_data'=>'print_receipt:'.$bookingId]]]];
                        }
                    } catch (Throwable $emailStateError) {
                        $replyText = "❌ " . clientExceptionMessage('Email belum terkirim', $emailStateError) . "\n\nKetik alamat email yang benar, atau /batal untuk membatalkan.";
                        $replyMarkup = ['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_extend_nights') {
                    // Kegagalan apa pun di sini tidak boleh membuat sesi beku:
                    // selalu balas pesan yang bisa ditindaklanjuti.
                    try {
                        $roomNumber = trim($currentCtx);
                        $nights = preg_match('/^[1-9][0-9]*$/',trim((string)$command)) ? (int)$command : 0;
                        if ($nights <= 0 || $nights > 3650) {
                            $replyText = "⚠️ Input tidak valid! Harap ketik jumlah malam perpanjangan dengan angka bulat positif (contoh: `1` atau `2`):\n\n" .
                                         "Kamar: *{$roomNumber}*";
                            $replyMarkup = [
                                "inline_keyboard" => [
                                    [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                                ]
                            ];
                        } else {
                            // Get active booking
                            $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                            $stmtBooking->execute([$roomNumber]);
                            $booking = $stmtBooking->fetch();

                            $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                            $stmtRoom->execute([$roomNumber]);
                            $room = $stmtRoom->fetch();

                            if ($booking && $room) {
                                $selection=tamasyaTelegramExtensionQuote($pdo,$loggedInStaff,$booking,$room,$nights,$telegramUpdateOperationId);
                                $replyText=$selection['text'];$replyMarkup=$selection['markup'];
                            } else {
                                $replyText = "❌ Booking atau Kamar {$roomNumber} tidak ditemukan. Proses dibatalkan.";
                                $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                                $stmtClear->execute([$loggedInStaff['id']]);
                            }
                        }
                    } catch (Throwable $extensionError) {
                        $replyText = "⚠️ ".clientExceptionMessage('Perpanjangan belum dapat diproses',$extensionError)."\n\nKetik ulang jumlah malam, atau batalkan.";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_extend_nego_amount' || $currentState === 'waiting_for_extend_nego_reason') {
                    try{
                        $raw=json_decode((string)$currentCtx,true);
                        $ctx=tamasyaTelegramExtensionContext($loggedInStaff,(string)($raw['nonce']??''),['waiting_for_extend_nego_amount','waiting_for_extend_nego_reason']);
                        if($currentState==='waiting_for_extend_nego_amount'){
                            $amount=tamasyaTelegramParseMoney((string)$command);
                            if($amount===null||$amount<=0||$amount>1000000000000)throw new InvalidArgumentException('Harga nego harus angka positif.');
                            $tax=calculateInclusiveTaxBreakdown($amount,(float)$ctx['quotedTaxRate']);
                            $ctx['amount']=$amount;$ctx['quotedBaseAmount']=$tax['baseAmount'];$ctx['priceMode']='negotiated';
                            $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_extend_nego_reason',telegram_context=? WHERE id=?")->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id']]);
                            $replyText='📝 Ketik alasan/kesepakatan harga nego minimal 5 karakter. Contoh: `Harga disepakati dengan tamu saat perpanjangan`.';
                        }else{
                            if(tamasyaStringLength(trim((string)$command))<5)throw new InvalidArgumentException('Alasan minimal 5 karakter.');
                            $ctx['negotiationReason']=trim((string)$command);
                            $result=tamasyaTelegramExtensionRender($pdo,$loggedInStaff,$ctx);$replyText=$result['text'];$replyMarkup=$result['markup'];
                        }
                    }catch(Throwable $e){$replyText=clientExceptionMessage('Harga nego belum dapat diproses',$e);}
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_extend_payment') {
                    try{
                        $raw=json_decode((string)$currentCtx,true);$ctx=tamasyaTelegramExtensionContext($loggedInStaff,(string)($raw['nonce']??''));
                        $value=strtolower(trim((string)$command));
                        $status=in_array($value,['lunas','paid','1','ya','sudah'],true)?'paid':(in_array($value,['belum','unpaid','2','tidak','belum lunas','belum bayar'],true)?'unpaid':'');
                        $result=tamasyaTelegramExtensionSubmit($pdo,$loggedInStaff,$ctx,$status,$telegramUpdateOperationId);$replyText=$result['text'];$replyMarkup=$result['markup'];
                        if(isset($result['broadcast']))try{broadcastTelegramNotification($pdo,$result['broadcast'][0],false);}catch(Throwable $deliveryError){error_log(clientExceptionMessage('[Telegram] Perpanjangan tersimpan; broadcast gagal',$deliveryError));}
                    }catch(Throwable $e){$replyText=clientExceptionMessage('Perpanjangan belum dapat diproses',$e);}
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_layanan_type') {
                    $replyText = "⚠️ Silakan pilih layanan aktif dari Master Data atau gunakan layanan kustom:";
                    $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',false);
                    $stmtExtraSubs=$pdo->prepare("SELECT id,name FROM subcategories WHERE category_id=? AND is_active=1 ORDER BY name,id");
                    $stmtExtraSubs->execute([(string)$extraRoot['id']]);$extraSubs=$stmtExtraSubs->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $serviceButtons=[];$serviceRow=[];
                    foreach($extraSubs as $service){
                        $serviceRow[]=['text'=>'🛎️ '.(string)$service['name'],'callback_data'=>'r_layanan_type:'.$currentCtx.':'.(string)$service['id']];
                        if(count($serviceRow)>=2){$serviceButtons[]=$serviceRow;$serviceRow=[];}
                    }
                    if($serviceRow)$serviceButtons[]=$serviceRow;
                    $serviceButtons[]=[['text'=>'✍️ Layanan Kustom','callback_data'=>'r_layanan_type:'.$currentCtx.':__custom__']];
                    $serviceButtons[]=[["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]];
                    $replyMarkup = ["inline_keyboard" => $serviceButtons];
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_layanan_custom_name') {
                    $roomNumber = trim($currentCtx);
                    $name = trim($command);
                    if (empty($name)) {
                        $replyText = "⚠️ Nama layanan kustom tidak boleh kosong! Silakan ketik nama layanannya:";
                    } else {
                        $newCtx = $roomNumber . ":" . rawurlencode($name);
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_custom_price', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $replyText = "🛠️ *HARGA LAYANAN KUSTOM*\n\n" .
                                     "Layanan: *{$name}*\n" .
                                     "Silakan ketik harga satuan layanan ini (angka saja, contoh: `20000` atau `150000`):";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_layanan_custom_price') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0];
                    $name = rawurldecode($parts[1]??'');

                    $price = tamasyaTelegramParseMoney((string)$command)??-1;
                    if ($price <= 0) {
                        $replyText = "⚠️ Harga tidak valid! Silakan ketik harga satuan dengan angka bulat positif (contoh: `25000`):";
                    } else {
                        $newCtx = $roomNumber . ":" . rawurlencode($name) . ":" . $price;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_qty', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $replyText = "🛠️ *JUMLAH/QTY TAMBAHAN*\n" .
                                     "Layanan: *{$name}* (Rp " . tamasyaTelegramFormatAmount($price) . "/satuan)\n\n" .
                                     "Berapa banyak item/layanan yang ingin dibeli?\n" .
                                     "*(Ketik angka saja, contoh: \`1\` atau \`2\`)*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_layanan_qty') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0];
                    $name = rawurldecode($parts[1]??'');
                    $price = (float)$parts[2];

                    $qty = preg_match('/^[1-9][0-9]*$/',trim((string)$command)) ? (int)$command : 0;
                    if ($qty <= 0) {
                        $replyText = "⚠️ Jumlah tidak valid! Harap ketik jumlah pembelian dengan angka bulat positif (contoh: `1` atau `2`):\n\n" .
                                     "Layanan: *{$name}*";
                    } else {
                        $baseCost = $price * $qty;
                        $stmtTaxBooking=$pdo->prepare("SELECT bookingSource FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");
                        $stmtTaxBooking->execute([$roomNumber]);
                        $taxBookingSource=(string)($stmtTaxBooking->fetchColumn()?:'Direct');
                        $extraRate=resolveConfiguredTaxRate($pdo,$taxBookingSource,'extra',0,date('Y-m-d'));
                        $extraTaxAmount=$extraRate>0?round($baseCost*($extraRate/100),2):0.0;
                        $totalCost=round($baseCost+$extraTaxAmount,2);
                        $newCtx = $roomNumber . ":" . rawurlencode($name) . ":" . $price . ":" . $qty;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_layanan_payment', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $replyText = "💰 *KONFIRMASI TAMBAHAN LAYANAN KAMAR {$roomNumber}*\n\n" .
                                     "• Layanan: *{$name}*\n" .
                                     "• Harga: Rp " . tamasyaTelegramFormatAmount($price) . "\n" .
                                     "• Jumlah/Qty: *{$qty}x*\n" .
                                     "• Dasar layanan: Rp " . tamasyaTelegramFormatAmount($baseCost) . "\n" .
                                     "• PBJT {$extraRate}%: Rp " . tamasyaTelegramFormatAmount($extraTaxAmount) . "\n" .
                                     "• *Total Tambahan: Rp " . tamasyaTelegramFormatAmount($totalCost) . "*\n\n" .
                                     "Silakan pilih status pembayaran di bawah ini atau ketik `lunas` / `belum lunas`:";

                        $replyMarkup = [
                            "inline_keyboard" => [
                                [
                                    ["text" => "🟢 Lunas", "callback_data" => "r_layanan_confirm:" . $roomNumber . ":" . rawurlencode($name) . ":" . $price . ":" . $qty . ":paid"]
                                ],
                                [
                                    ["text" => "🔴 Belum Lunas", "callback_data" => "r_layanan_confirm:" . $roomNumber . ":" . rawurlencode($name) . ":" . $price . ":" . $qty . ":unpaid"]
                                ],
                                [
                                    ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
                                ]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_layanan_payment') {
                    $parts = explode(":", $currentCtx);
                    $roomNumber = $parts[0];
                    $name = rawurldecode($parts[1]??'');
                    $price = (float)$parts[2];
                    $qty = (int)$parts[3];
                    $baseCost = $price * $qty;
                    $totalCost = $baseCost;

                    $paymentStatus = null;
                    $normalizedInput = strtolower($command);
                    if (in_array($normalizedInput, ["lunas", "paid", "1", "yes", "ya", "sudah"])) {
                        $paymentStatus = "paid";
                    } elseif (in_array($normalizedInput, ["belum", "unpaid", "2", "no", "tidak", "belum lunas"])) {
                        $paymentStatus = "unpaid";
                    }

                    if ($paymentStatus) {
                        $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                        $stmtBooking->execute([$roomNumber]);
                        $booking = $stmtBooking->fetch();

                        if ($booking) {
                            try {
                                $bookingSource=(string)($booking['bookingSource']??'Direct');
                                $extraRate=resolveConfiguredTaxRate($pdo,$bookingSource,'extra',0,date('Y-m-d'));
                                $extraTaxAmount=$extraRate>0?round($baseCost*($extraRate/100),2):0.0;
                                $totalCost=round($baseCost+$extraTaxAmount,2);
                                $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',false);
                                $category=(string)$extraRoot['name'];$subcategory=null;
                                $stmtExtraSub=$pdo->prepare("SELECT name FROM subcategories WHERE category_id=? AND is_active=1 AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1");
                                $stmtExtraSub->execute([(string)$extraRoot['id'],$name]);$matchedExtraSub=$stmtExtraSub->fetchColumn();
                                if($matchedExtraSub!==false)$subcategory=(string)$matchedExtraSub;
                                if($paymentStatus==='paid'){
                                    $selection=tamasyaTelegramChargeBegin($pdo,$loggedInStaff,$booking,['action'=>'extra','serviceName'=>$name,'unitPrice'=>$price,'qty'=>$qty,'amount'=>$totalCost,'quotedBaseAmount'=>$baseCost,'category'=>$category,'subcategory'=>$subcategory],$telegramUpdateOperationId);
                                    $replyText=$selection['text'];$replyMarkup=$selection['markup'];
                                }else{
                                $serviceOperationId=telegramScopedOperationId($telegramUpdateOperationId,'service',[(string)$booking['id'],$roomNumber,$name,$price,$qty,$paymentStatus]);
                                $chargeResult=applyCanonicalTelegramBookingChargeWorkflow($pdo,$loggedInStaff,[
                                    'action'=>'extra','bookingId'=>(string)$booking['id'],'roomNumber'=>$roomNumber,
                                    'serviceName'=>$name,'unitPrice'=>$price,'qty'=>$qty,'amount'=>$totalCost,
                                    'category'=>$category,'subcategory'=>$subcategory,'paymentStatus'=>$paymentStatus
                                ],$serviceOperationId);
                                $booking=$chargeResult['booking'];
                                $tgMsg="🛠️ *TAMBAHAN LAYANAN KAMAR* (Bot Telegram)\n\n" .
                                    "🚪 *Kamar*: {$roomNumber}\n" .
                                    "👤 *Tamu*: *" . tamasyaTelegramPlainText($booking['guestName'],120) . "*\n" .
                                    "🛠️ *Layanan*: {$name} (x{$qty})\n" .
                                    "💰 *Total tagihan*: Rp " . tamasyaTelegramFormatAmount($totalCost) . "\n" .
                                    "💳 *Status Pembayaran*: " . ($paymentStatus==='paid'?"✅ LUNAS":"⏳ BELUM LUNAS") . "\n" .
                                    "👤 *Oleh*: {$loggedInStaff['name']}";
                                broadcastTelegramNotification($pdo,$tgMsg);
                                $replyText="🎉 *TAMBAHAN LAYANAN BERHASIL*! 🎉\n\n" .
                                    "🔑 Kamar: *{$roomNumber}*\n👤 Tamu: *" . tamasyaTelegramPlainText($booking['guestName'],120) . "*\n" .
                                    "🛠️ Layanan: *{$name}* (x{$qty})\n💰 Total Tambahan: *Rp " . tamasyaTelegramFormatAmount($totalCost) . "*\n" .
                                    "💳 Status: *" . $telegramPaymentStatusLabel((string)$paymentStatus) . "*\n\nData otomatis tersinkron ke dasbor hotel!";
                            }
                            } catch (TelegramDuplicateOperationException $duplicate) {
                                $replyText='ℹ️ Tambahan layanan ini sudah diproses; booking dan transaksi tidak digandakan.';
                            } catch (Throwable $e) {
                                $replyText=clientExceptionMessage('❌ Gagal memproses',$e);
                            }
                        } else {
                            $replyText = "❌ Booking aktif Kamar {$roomNumber} tidak ditemukan!";
                        }

                        // Clear state
                        $stmtClear = $pdo->prepare("UPDATE staff SET telegram_state = NULL, telegram_context = NULL WHERE id = ?");
                        if($paymentStatus!=='paid')$stmtClear->execute([$loggedInStaff['id']]);
                    } else {
                        $replyText = "⚠️ Input tidak valid! Silakan pilih tombol atau ketik `lunas` / `belum lunas`.";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "🟢 Lunas", "callback_data" => "r_layanan_confirm:" . $roomNumber . ":" . rawurlencode($name) . ":" . $price . ":" . $qty . ":paid"]],
                                [["text" => "🔴 Belum Lunas", "callback_data" => "r_layanan_confirm:" . $roomNumber . ":" . rawurlencode($name) . ":" . $price . ":" . $qty . ":unpaid"]],
                                [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_transfer_target') {
                    $roomNumber = trim($currentCtx);
                    $targetRoomNumber = trim($command);

                    // Status tabel saja tidak cukup; validasi seluruh blocker operasional.
                    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                    $stmtRoom->execute([$targetRoomNumber]);
                    $room = $stmtRoom->fetch();
                    $roomBlockers=$room ? getRoomOperationalBlockers($pdo,$targetRoomNumber,false) : [];

                    if (!$room || tamasyaDeriveRoomOperationalStatus($roomBlockers)!=='available') {
                        $replyText = $roomBlockers
                            ? "⚠️ ".roomOperationalBlockerMessage($targetRoomNumber,$roomBlockers)."\n\nSilakan ketik nomor kamar lain:"
                            : "⚠️ Kamar nomor {$targetRoomNumber} tidak tersedia atau tidak ditemukan! Silakan ketik nomor kamar kosong lainnya:";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    } else {
                        $newCtx = $roomNumber . ":" . $targetRoomNumber;
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_transfer_cost', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$newCtx, $loggedInStaff['id']]);

                        $replyText = "💰 *BIAYA TAMBAHAN PINDAH KAMAR*\n\n" .
                                     "Apakah ada biaya tambahan (surcharge) untuk pemindahan Kamar *{$roomNumber}* ke Kamar *{$targetRoomNumber}*?\n\n" .
                                     "*(Ketik angka saja, contoh: `0` jika gratis, atau `50000` jika ada biaya)*";
                        $replyMarkup = [
                            "inline_keyboard" => [
                                [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]]
                            ]
                        ];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_transfer_cost') {
                    $parts = explode(":", (string)$currentCtx);
                    $roomNumber = trim((string)($parts[0] ?? ''));
                    $targetRoomNumber = trim((string)($parts[1] ?? ''));
                    $rawCost=trim((string)$command);
                    if (($cost=tamasyaTelegramParseMoney($rawCost))===null) {
                        $replyText = "⚠️ Biaya tidak valid! Ketik angka non-negatif, contoh `0` atau `50000`:";
                        $replyMarkup = ["inline_keyboard" => [[ ["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"] ]]];
                    } else {
                        // $cost was validated by the shared Indonesian money parser.
                        $ctxJson=json_encode([
                            'flow'=>'room_transfer_confirm','roomNumber'=>$roomNumber,'targetRoomNumber'=>$targetRoomNumber,
                            'cost'=>$cost,'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash'
                        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_transfer_confirmation', telegram_context = ? WHERE id = ?");
                        $stmtSetState->execute([$ctxJson, $loggedInStaff['id']]);

                        $replyText = "💰 *KONFIRMASI PINDAH KAMAR*\n\n" .
                                     "• Dari Kamar: *{$roomNumber}*\n" .
                                     "• Ke Kamar: *{$targetRoomNumber}*\n" .
                                     "• Biaya Tambahan Folio: *Rp " . tamasyaTelegramFormatAmount($cost) . "*\n\n" .
                                     "Biaya ini *tidak langsung dianggap pembayaran*. Setelah pindah kamar, settlement dilakukan lewat menu Pembayaran/Checkout agar Tunai, Transfer, QRIS, Split, atau OTA masuk akun yang benar.\n\n" .
                                     "Tekan tombol di bawah atau ketik `konfirmasi`.";
                        $replyMarkup = ["inline_keyboard" => [
                            [["text" => "✅ Tambahkan ke Folio & Pindahkan", "callback_data" => "r_transfer_confirm:" . $roomNumber . ":" . $targetRoomNumber . ":" . $cost . ":folio"]],
                            [["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]]
                        ]];
                    }
                    $stateProcessed = true;
                } elseif ($currentState === 'waiting_for_transfer_confirmation') {
                    $ctx=json_decode((string)$currentCtx,true);
                    $normalizedInput=strtolower(trim((string)$command));
                    if(!is_array($ctx)||($ctx['flow']??'')!=='room_transfer_confirm'){
                        $replyText='⚠️ Sesi pindah kamar sudah kedaluwarsa. Mulai kembali dari menu Pindah Kamar.';
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                    }elseif(!in_array($normalizedInput,['konfirmasi','lanjut','ya','yes','1','ok'],true)){
                        $roomNumber=trim((string)($ctx['roomNumber']??''));
                        $targetRoomNumber=trim((string)($ctx['targetRoomNumber']??''));
                        $cost=(float)($ctx['cost']??0);
                        $replyText="⚠️ Ketik `konfirmasi` atau tekan tombol untuk melanjutkan. Biaya akan masuk folio dan belum dianggap pembayaran.";
                        $replyMarkup=['inline_keyboard'=>[
                            [["text"=>"✅ Tambahkan ke Folio & Pindahkan","callback_data"=>"r_transfer_confirm:".$roomNumber.":".$targetRoomNumber.":".$cost.":folio"]],
                            [["text"=>"❌ Batalkan Proses","callback_data"=>"cancel_booking_process"]]
                        ]];
                    }else{
                        $roomNumber=trim((string)($ctx['roomNumber']??''));
                        $targetRoomNumber=trim((string)($ctx['targetRoomNumber']??''));
                        $cost=(float)($ctx['cost']??0);
                        try{
                            $bookingKeyStmt=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' ORDER BY actualCheckInAt DESC,checkIn DESC LIMIT 1");
                            $bookingKeyStmt->execute([$roomNumber]);
                            $transferBooking=$bookingKeyStmt->fetch(PDO::FETCH_ASSOC);
                            if(!$transferBooking)throw new RuntimeException("Tidak ada booking aktif di Kamar {$roomNumber}.");
                            $keyContext=checkoutAccessSnapshot($pdo,$transferBooking,false);
                            if(!empty($keyContext['physicalOutstanding'])){
                                $ctxJson=json_encode([
                                    'flow'=>'room_transfer_key','roomNumber'=>$roomNumber,'targetRoomNumber'=>$targetRoomNumber,
                                    'cost'=>$cost,'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash'
                                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                                $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_transfer_key',telegram_context=? WHERE id=?")->execute([$ctxJson,$loggedInStaff['id']]);
                                $replyText="🔑 *STATUS KUNCI KAMAR ASAL*\n\nSebelum memindahkan Kamar *{$roomNumber}* ke *{$targetRoomNumber}*, konfirmasi kondisi kunci fisik kamar asal.\n\nBiaya *Rp ".tamasyaTelegramFormatAmount($cost)."* tetap hanya masuk folio.";
                                $replyMarkup=['inline_keyboard'=>[
                                    [["text"=>"✅ Kunci kembali/ditemukan","callback_data"=>"r_transfer_key:returned"]],
                                    [["text"=>"⚠️ Kunci belum kembali/hilang","callback_data"=>"r_transfer_key:missing"]],
                                    [["text"=>"❌ Batalkan Proses","callback_data"=>"cancel_booking_process"]]
                                ]];
                            }else{
                                $transferOperationId=$telegramUpdateOperationId.'_room_transfer';
                                if(strlen($transferOperationId)>100)$transferOperationId='tg_transfer_'.substr(hash('sha256',$telegramUpdateOperationId.'|'.$roomNumber.'|'.$targetRoomNumber.'|'.$cost.'|folio'),0,64);
                                $result=finalizeTelegramRoomTransfer($pdo,$loggedInStaff,$roomNumber,$targetRoomNumber,$cost,'unpaid',$transferOperationId,'message','not_required','Kamar asal tidak memiliki kunci fisik yang masih outstanding.');
                                $guestName=tamasyaTelegramPlainText((string)($result['guestName']??'Tamu'),120);
                                if(empty($result['duplicate'])){
                                    broadcastTelegramNotification($pdo,"🔄 *PEMINDAHAN KAMAR*\n\n👤 *Tamu*: {$guestName}\n🚪 *{$roomNumber}* → *{$targetRoomNumber}*\n💰 *Biaya Tambahan Folio*: Rp ".tamasyaTelegramFormatAmount($cost)."\n💳 *Settlement*: BELUM DIBAYAR — melalui Pembayaran/Checkout\n👤 *Oleh*: {$loggedInStaff['name']}");
                                }
                                $replyText=!empty($result['duplicate'])
                                    ? 'ℹ️ Pindah kamar dari pesan ini sudah diproses sebelumnya. Data tidak digandakan.'
                                    : "🎉 *PINDAH KAMAR BERHASIL*\n\n👤 *{$guestName}*\n🚪 *{$roomNumber}* → *{$targetRoomNumber}*\n💰 Biaya tambahan folio: *Rp ".tamasyaTelegramFormatAmount($cost)."*\n💳 Settlement: *BELUM DIBAYAR*\n\nGunakan Pembayaran/Checkout untuk mencatat uang masuk sesuai metode sebenarnya.";
                                $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                            }
                        }catch(Throwable $e){
                            $replyText=clientExceptionMessage('❌ Gagal memproses',$e);
                            $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        }
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_transfer_key') {
                    $ctx=json_decode((string)$currentCtx,true);
                    $normalizedInput=strtolower(trim($command));
                    $keyDisposition=null;
                    if(in_array($normalizedInput,['kembali','returned','ditemukan','sudah kembali','1','ya'],true))$keyDisposition='returned';
                    elseif(in_array($normalizedInput,['hilang','missing','belum kembali','belum','2','tidak'],true))$keyDisposition='missing';
                    if(!is_array($ctx)||($ctx['flow']??'')!=='room_transfer_key'){
                        $replyText='⚠️ Sesi konfirmasi pindah kamar sudah kedaluwarsa. Mulai kembali dari menu Pindah Kamar.';
                        $stmtClear=$pdo->prepare("UPDATE staff SET telegram_state=NULL, telegram_context=NULL WHERE id=?");
                        $stmtClear->execute([$loggedInStaff['id']]);
                    }elseif(!$keyDisposition){
                        $replyText="⚠️ Status kunci belum dikenali. Pilih tombol atau ketik `kembali` / `hilang`.";
                        $replyMarkup=['inline_keyboard'=>[
                            [["text"=>"✅ Kunci kembali/ditemukan","callback_data"=>"r_transfer_key:returned"]],
                            [["text"=>"⚠️ Kunci belum kembali/hilang","callback_data"=>"r_transfer_key:missing"]],
                            [["text"=>"❌ Batalkan Proses","callback_data"=>"cancel_booking_process"]]
                        ]];
                    }else{
                        $roomNumber=trim((string)($ctx['roomNumber']??''));
                        $targetRoomNumber=trim((string)($ctx['targetRoomNumber']??''));
                        $cost=(float)($ctx['cost']??0);
                        $paymentStatus='unpaid';
                        try{
                            $transferOperationId=$telegramUpdateOperationId.'_room_transfer_key';
                            if(strlen($transferOperationId)>100)$transferOperationId='tg_transfer_'.substr(hash('sha256',$telegramUpdateOperationId.'|'.$roomNumber.'|'.$targetRoomNumber.'|'.$cost.'|'.$paymentStatus.'|'.$keyDisposition),0,64);
                            $keyReason=$keyDisposition==='returned'
                                ? 'Petugas mengonfirmasi via Telegram bahwa kunci kamar asal sudah kembali/ditemukan saat pindah kamar.'
                                : 'Petugas mengonfirmasi via Telegram bahwa kunci kamar asal belum kembali/hilang saat pindah kamar.';
                            $result=finalizeTelegramRoomTransfer($pdo,$loggedInStaff,$roomNumber,$targetRoomNumber,$cost,$paymentStatus,$transferOperationId,'message',$keyDisposition,$keyReason);
                            $guestName=tamasyaTelegramPlainText((string)($result['guestName']??'Tamu'),120);
                            if(empty($result['duplicate'])){
                                $tgMsg="🔄 *PEMINDAHAN KAMAR (TRANSFER ROOM)*\n\n👤 *Tamu*: {$guestName}\n🚪 *Kamar Asal*: {$roomNumber}\n🚪 *Kamar Tujuan*: {$targetRoomNumber}\n🔑 *Kunci Asal*: ".($keyDisposition==='returned'?'✅ KEMBALI/DITEMUKAN':'⚠️ BELUM KEMBALI/HILANG')."\n💰 *Biaya Tambahan*: Rp ".tamasyaTelegramFormatAmount($cost)."\n💳 *Settlement*: ⏳ BELUM DIBAYAR — melalui Pembayaran/Checkout\n👤 *Oleh*: {$loggedInStaff['name']}";
                                broadcastTelegramNotification($pdo,$tgMsg);
                            }
                            $replyText=!empty($result['duplicate'])
                                ? 'ℹ️ Pindah kamar dari pesan ini sudah diproses sebelumnya. Data tidak digandakan.'
                                : "🎉 *PROSES PINDAH KAMAR BERHASIL*! 🎉\n\n👤 Tamu: *{$guestName}*\n🚪 Kamar Asal: *{$roomNumber}* (Maintenance/Housekeeping)\n🚪 Kamar Tujuan: *{$targetRoomNumber}* (Terisi)\n🔑 Kunci Asal: *".($keyDisposition==='returned'?'KEMBALI/DITEMUKAN':'BELUM KEMBALI/HILANG')."*\n💰 Biaya Tambahan: *Rp ".tamasyaTelegramFormatAmount($cost)."*\n💳 Settlement: *BELUM DIBAYAR* — melalui Pembayaran/Checkout\n\nReservasi, folio, pajak, kunci, Housekeeping, dan audit telah disinkronkan tanpa transaksi kas palsu.";
                        }catch(Throwable $e){
                            $replyText=clientExceptionMessage('❌ Gagal memproses',$e);
                        }
                        $stmtClear=$pdo->prepare("UPDATE staff SET telegram_state=NULL, telegram_context=NULL WHERE id=?");
                        $stmtClear->execute([$loggedInStaff['id']]);
                    }
                    $stateProcessed=true;
                } elseif ($currentState === 'waiting_for_expense_input') {
                    $ctx=json_decode((string)$currentCtx,true);if(!is_array($ctx))$ctx=[];
                    $expenseCatId=trim((string)($ctx['catId']??''));
                    $expenseSubId=trim((string)($ctx['subId']??''));
                    $expensePhotoFileId=trim((string)($ctx['photoFileId']??''));
                    $batalMarkup=['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'pengeluaran_menu']]]];

                    if ($command === '/batal' || strtolower($command)==='batal') {
                        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                        $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                        $replyText="❌ Input pengeluaran dibatalkan.";
                        $replyMarkup=$batalMarkup;
                        $stateProcessed=true;
                    } elseif (is_array($photoMsg)) {
                        $largest=end($photoMsg);
                        $fid=trim((string)($largest['file_id']??''));
                        if ($fid !== '') {
                            $ctx['photoFileId']=$fid;
                            $encoded=json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            $pdo->prepare("UPDATE staff SET telegram_context=? WHERE id=?")->execute([$encoded,$loggedInStaff['id']]);
                            $loggedInStaff['telegram_context']=$encoded;
                            $replyText="📷 *FOTO NOTA DITERIMA*\n\nSekarang ketik nominal pengeluaran.\nContoh: `125000 beli sapu ijuk`\nℹ️ Kirim foto nota sebelum nominal agar terlampir. Referensi foto Telegram disimpan; nota diambil saat dibuka.";
                        } else {
                            $replyText="⚠️ Foto tidak valid. Kirim ulang foto nota atau ketik nominal.";
                        }
                        $replyMarkup=$batalMarkup;
                        $stateProcessed=true;
                    } else {
                        if (preg_match('/^(\d[\d\.,]*)\s*(.*)$/u', $command, $expenseMatch)) {
                            $amount=tamasyaTelegramParseMoney((string)$expenseMatch[1]);
                            $description=trim((string)$expenseMatch[2]);
                            if ($amount === null || $amount <= 0) {
                                $replyText="⚠️ Nominal harus berupa angka lebih dari nol.";
                                $replyMarkup=$batalMarkup;
                            } else {
                                try {
                                    if ($expenseSubId !== '') {
                                        $pair=requireTelegramFinancialCategoryPair($pdo,$expenseCatId,$expenseSubId,'expense');
                                        $categoryName=$pair['category_name'];
                                        $subcategoryName=$pair['subcategory_name'];
                                    } else {
                                        $stmtCat=$pdo->prepare("SELECT * FROM categories WHERE id=? AND type='expense' AND is_active=1 LIMIT 1");
                                        $stmtCat->execute([$expenseCatId]);
                                        $catRow=$stmtCat->fetch(PDO::FETCH_ASSOC);
                                        if (!$catRow) throw new RuntimeException('Kategori pengeluaran tidak ditemukan atau sudah tidak aktif.');
                                        $categoryName=(string)$catRow['name'];
                                        $subcategoryName=null;
                                    }
                                    if ($description === '') $description='Pengeluaran '.$categoryName.' via Telegram';
                                    $today=date('Y-m-d');
                                    $finalDesc=$description.' (Telegram Bot oleh '.$loggedInStaff['name'].')';
                                    $notifMsg='Transaksi Keluar oleh '.$loggedInStaff['name'].' via Telegram Bot: Pengeluaran '.$categoryName.($subcategoryName?' ('.$subcategoryName.')':'').' senilai Rp '.tamasyaTelegramFormatAmount($amount);
                                    $proofUrl = $expensePhotoFileId !== '' ? 'telegram_file_id:'.$expensePhotoFileId : null;
                                    $tgTx=createTelegramCashTransaction($pdo,$telegramUpdateOperationId,$loggedInStaff,[
                                        'type'=>'expense',
                                        'category'=>$categoryName,
                                        'categoryId'=>$expenseCatId,
                                        'subcategory'=>$subcategoryName,
                                        'subcategoryId'=>$expenseSubId !== '' ? $expenseSubId : null,
                                        'amount'=>$amount,
                                        'date'=>$today,
                                        'description'=>$finalDesc,
                                        'baseAmount'=>$amount,
                                        'taxAmount'=>0,
                                        'taxRate'=>0,
                                        'proofUrl'=>$proofUrl,
                                        'bankAccountId'=>null
                                    ],$notifMsg,'message');
                                    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                                    $loggedInStaff['telegram_state']=null;$loggedInStaff['telegram_context']=null;
                                    $replyText="✅ *SUKSES INPUT PENGELUARAN*!\n\nTransaksi Pengeluaran Disimpan:\n- Kategori: ".$categoryName."\n".($subcategoryName?"- Subkategori: ".$subcategoryName."\n":"")."- Nominal: Rp ".tamasyaTelegramFormatAmount($amount)."\n- Petugas: ".$loggedInStaff['name']."\n- Deskripsi: ".$description." (Telegram Bot)".($proofUrl?"\n- 📷 Foto nota tersimpan":"");
                                    $replyMarkup=['inline_keyboard'=>[
                                        [['text'=>'📤 Input Pengeluaran Lainnya','callback_data'=>'pengeluaran_menu']],
                                        [['text'=>'📊 Cek Laporan Kas','callback_data'=>'laporan_kas_refresh']]
                                    ]];
                                } catch (Throwable $expenseInputError) {
                                    $replyText="❌ ".clientExceptionMessage('Pengeluaran ditolak',$expenseInputError);
                                    $replyMarkup=$batalMarkup;
                                }
                            }
                        } else {
                            $replyText="⚠️ Format salah. Ketik nominal (angka) lalu keterangan.\nContoh: `125000 beli sapu ijuk`\n\nAnda juga bisa kirim foto nota sebelum atau sesudah nominal.";
                            $replyMarkup=$batalMarkup;
                        }
                        $stateProcessed=true;
                    }
                }
            }

            // Otorisasi command langsung harus sama dengan callback. Mengetik
            // command secara manual tidak boleh menjadi bypass tombol/role.
            if (!$stateProcessed) {
                // Role revalidation chain: peran selalu dibaca ulang dari binding aktif
                // server sebelum otorisasi, agar perubahan role dashboard langsung berlaku.
                if ($loggedInStaff) {
                    $revalidatedStaff = findActiveStaffByTelegramUserId($pdo, $fromId);
                    if (is_array($revalidatedStaff)) {
                        $loggedInStaff = $revalidatedStaff;
                    }
                }
                $directRole = strtolower((string)($loggedInStaff['role'] ?? ''));
                $requiredDirectRoles = null;
                $requiredDirectCap = null; // [capability, fallbackRoles]: lolos via role baseline ATAU centangan.
                if ($command === '🔎 Diagnosa Kamar' || $command === '🔧 Update Kamar') {
                    $requiredDirectRoles = ['admin','manager','receptionist'];
                } elseif ($command === '/reservasi' || $command === '/reservasi_grup' || $command === '/checkin_grup' || $command === '/jual_kamar' || str_starts_with($command,'/jual ')) {
                    $requiredDirectCap = ['sell_room_access',['admin','manager','receptionist']];
                } elseif ($command === '/perpanjang' || $command === '/layanan' || $command === '/pindah' ||
                    str_starts_with($command,'/checkout') || str_starts_with($command,'/update_status')) {
                    $requiredDirectCap = ['manage_stay_access',['admin','manager','receptionist']];
                } elseif (str_starts_with($command,'/panjar')) {
                    $requiredDirectCap = ['receive_payment_access',['admin','manager','receptionist','finance']];
                } elseif (str_starts_with($command,'/ktp')) {
                    $requiredDirectCap = ['view_guest_identity',['admin','manager','receptionist','keamanan']];
                } elseif (str_starts_with($command,'/cetak_nota') || str_starts_with($command,'/nota') || str_starts_with($command,'/invoice')) {
                    $requiredDirectRoles = ['admin','manager','receptionist','finance','owner'];
                } elseif ($command === '/kamar_kosong' || str_starts_with($command,'/kamar_kosong ')) {
                    $requiredDirectRoles = ['admin','manager','receptionist','finance','cleaning_service','keamanan','koki','tukang_kebun','lain_lain','owner'];
                } elseif ($command === '/housekeeping' || str_starts_with($command,'/hk_set')) {
                    $requiredDirectCap = ['perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']];
                } elseif ($command === '/patroli_laporan' || str_starts_with($command,'/patrol') || str_starts_with($command,'/lapor_insiden')) {
                    $requiredDirectRoles = ['admin','manager','receptionist','keamanan'];
                } elseif ($command === '/buka_shift' || $command === '/tutup_shift') {
                    $requiredDirectCap = ['receive_payment_access', tamasyaShiftOperatorRoles()];
                } elseif ($command === '📥 Input Pemasukan' || str_starts_with($command,'/pemasukan')) {
                    $requiredDirectRoles = ['admin','manager','finance'];
                } elseif ($command === '📤 Input Pengeluaran' || str_starts_with($command,'/pengeluaran')) {
                    $requiredDirectRoles = ['admin','manager','finance'];
                } elseif (str_starts_with($command,'/laporan') || $command === '/riwayat_shift' || str_starts_with($command,'/riwayat_shift ') || str_starts_with($command,'/riwayat_shift:')) {
                    $requiredDirectRoles = function_exists('tamasyaShiftHistoryReaderRoles') ? tamasyaShiftHistoryReaderRoles() : ['admin','owner','manager','finance'];
                } elseif ($command==='/integritas') {
                    $requiredDirectRoles = ['admin','manager','finance'];
                }
                if ($requiredDirectRoles !== null && (!$loggedInStaff || !in_array($directRole,$requiredDirectRoles,true))) {
                    $replyText = "🔒 *AKSES DITOLAK*

Command ini tidak sesuai dengan peran akun Telegram Anda. Tidak ada perubahan yang dilakukan pada database.";
                    if ($loggedInStaff && $directRole==='owner') {
                        $replyText = "🔒 *OWNER READ-ONLY*

Akun Owner boleh melihat semua menu, tetapi tidak boleh mengubah data lewat Telegram. Minta Admin/Manager untuk tindakan ini.";
                    }
                    $stateProcessed = true;
                } elseif ($requiredDirectCap !== null && (!$loggedInStaff || !hasCapability($loggedInStaff,$requiredDirectCap[0],$requiredDirectCap[1]))) {
                    $replyText = "🔒 *AKSES DITOLAK*

Akun Telegram Anda belum memiliki akses ini. Minta Admin mencentang izin yang sesuai di menu Karyawan. Tidak ada perubahan yang dilakukan pada database.";
                    if ($loggedInStaff && $directRole==='owner') {
                        $replyText = "🔒 *OWNER READ-ONLY*

Akun Owner boleh melihat semua menu, tetapi tidak boleh mengubah data lewat Telegram. Minta Admin/Manager untuk tindakan ini.";
                    }
                    $stateProcessed = true;
                }
            }

            if ($stateProcessed) {
                // Selesai diproses oleh state machine, skip block command
            } elseif ($command === "🔎 Diagnosa Kamar" || $command === "🔧 Update Kamar") {
                $pageSize=30;$countRooms=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();$totalPages=max(1,(int)ceil(max(1,$countRooms)/$pageSize));
                $stmtRooms=$pdo->prepare("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC, number ASC LIMIT ? OFFSET 0");$stmtRooms->bindValue(1,$pageSize,PDO::PARAM_INT);$stmtRooms->execute();$rooms=$stmtRooms->fetchAll(PDO::FETCH_ASSOC)?:[];
                $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));
                $inlineKeyboardButtons=[];$currentRow=[];
                foreach($rooms as $room){$number=(string)$room['number'];$canonical=$roomStatusMap[$number]??'maintenance';$statusIcon=$canonical==='booked'?'🔴':($canonical==='dirty'?'🧹':($canonical==='maintenance'?'🛠️':'🟢'));$currentRow[]=['text'=>$statusIcon.' '.$number,'callback_data'=>'room_select:'.$number];if(count($currentRow)>=3){$inlineKeyboardButtons[]=$currentRow;$currentRow=[];}}
                if($currentRow)$inlineKeyboardButtons[]=$currentRow;if($totalPages>1)$inlineKeyboardButtons[]=$buildTelegramPagerRow(1,$totalPages,'room_list:p:');
                $inlineKeyboardButtons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];
                $replyText="🔎 *DIAGNOSA OPERASIONAL KAMAR*

Pilih nomor kamar untuk melihat status canonical dan blocker pemilik workflow. Status kamar tidak dapat diubah manual. Ditampilkan maksimal *{$pageSize} kamar per halaman*.";$replyMarkup=['inline_keyboard'=>$inlineKeyboardButtons];
            } elseif ($command === '/reservasi_grup' || $command === '/checkin_grup') {
                try{$menu=tamasyaMultiRoomTelegramCallback($pdo,$loggedInStaff,$command==='/reservasi_grup'?'mr_start:reserve':'mr_start:check_in_now',$telegramUpdateOperationId);$replyText=$menu['text'];$replyMarkup=$menu['markup'];}
                catch(Throwable $e){$replyText=clientExceptionMessage('Reservasi beberapa kamar belum dapat dimulai',$e);}
            } elseif ($command === "/reservasi") {
                $menu=$buildTelegramReservedBookingPicker($pdo,1,20);$replyText=$menu['text'];$replyMarkup=$menu['markup'];
            } elseif ($command === "/jual_kamar") {
                $menu=$buildTelegramSellableRoomPicker(
                    $pdo,'r_sell:','sell_room_list:p:',"🛒 *MENU PENJUALAN KAMAR*",
                    'Pilih kamar kosong yang ingin dijual/sewakan sekarang.',
                    '❌ *MAAF, SEMUA KAMAR PENUH!*

Tidak ada kamar yang benar-benar tersedia secara operasional untuk dijual saat ini.',
                    'guest_ops_menu',1
                );
                $replyText=$menu['text'];$replyMarkup=$menu['markup'];
            } elseif ($command === "📥 Input Pemasukan") {
                if(!$loggedInStaff || !in_array($loggedInStaff['role'],['admin','manager','finance'],true)){$replyText="🔒 *AKSES DITOLAK*!
Maaf, penginputan transaksi pemasukan hanya diizinkan untuk Admin, Manajer, atau divisi Keuangan.";}
                else{
                    $pageSize=30;$count=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();$totalPages=max(1,(int)ceil(max(1,$count)/$pageSize));
                    $stmt=$pdo->prepare("SELECT number FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC,number ASC LIMIT ? OFFSET 0");$stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->execute();$rooms=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $buttons=[];$row=[];foreach($rooms as $room){$row[]=['text'=>'🚪 Kamar '.$room['number'],'callback_data'=>'p_room:'.$room['number']];if(count($row)>=3){$buttons[]=$row;$row=[];}}if($row)$buttons[]=$row;if($totalPages>1)$buttons[]=$buildTelegramPagerRow(1,$totalPages,'pemasukan_menu:p:');$buttons[]=[['text'=>'⬅️ Kas & Shift','callback_data'=>'cash_shift_menu']];
                    $replyText="📥 *INPUT PEMASUKAN CEPAT*

Pilih kamar yang menerima transaksi keuangan.
📄 Halaman *1/{$totalPages}* · menampilkan maksimal *{$pageSize} kamar*.";$replyMarkup=['inline_keyboard'=>$buttons];
                }
            } elseif ($command === "📤 Input Pengeluaran") {
                if (!$loggedInStaff || !in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, penginputan pengeluaran kas hanya diizinkan untuk Admin, Manajer, atau divisi Keuangan.";
                } else {
                    $replyText = "📤 *INPUT PENGELUARAN CEPAT*\n\nSilakan pilih kategori pengeluaran kas:";
                    
                    // Fetch all categories of type 'expense' dynamically from database
                    $stmtExCats = $pdo->query("SELECT * FROM categories WHERE type='expense' AND is_active=1 AND COALESCE(is_system,0)=0 AND COALESCE(system_key,'') NOT IN ('payroll_expense','maintenance_expense','pos_refund','pos_cogs') ORDER BY name ASC");
                    $dbCats = $stmtExCats->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    
                    $inlineKeyboard = [];
                    foreach ($dbCats as $cat) {
                        $inlineKeyboard[] = [
                            ["text" => "📂 " . $cat['name'], "callback_data" => "p_exp_cat:" . $cat['id']]
                        ];
                    }
                    
                    if (empty($dbCats)) {
                        $replyText .= "\n\n⚠️ Belum ada kategori pengeluaran aktif di Master Data. Telegram tidak membuat kategori sintetis.";
                        $inlineKeyboard = [[['text'=>'⬅️ Kas & Shift','callback_data'=>'cash_shift_menu']]];
                    }
                    
                    $replyMarkup = ["inline_keyboard" => $inlineKeyboard];
                }
            } elseif ($command === "/perpanjang") {
                if(!$loggedInStaff){$replyText="🔒 *AKSES DITOLAK*!
Telegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";}
                else{$menu=$buildTelegramActiveBookingPicker($pdo,'r_extend:','booking_extend_menu:p:',"⏳ *PERPANJANG MASA SEWA KAMAR*",'Pilih kamar aktif yang ingin diperpanjang.','⚠️ Tidak ada sewa aktif yang dapat diperpanjang.',1);$replyText=$menu['text'];$replyMarkup=$menu['markup'];}
            } elseif ($command === "/layanan") {
                if(!$loggedInStaff){$replyText="🔒 *AKSES DITOLAK*!
Telegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";}
                else{$menu=$buildTelegramActiveBookingPicker($pdo,'r_layanan:','booking_service_menu:p:',"🛠️ *TAMBAH LAYANAN KAMAR*",'Pilih kamar aktif yang akan menerima layanan tambahan.','⚠️ Tidak ada sewa aktif yang dapat menerima layanan tambahan.',1);$replyText=$menu['text'];$replyMarkup=$menu['markup'];}
            } elseif ($command === "/pindah") {
                if(!$loggedInStaff){$replyText="🔒 *AKSES DITOLAK*!
Telegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";}
                else{$menu=$buildTelegramActiveBookingPicker($pdo,'r_transfer:','booking_transfer_menu:p:',"🔄 *PINDAH KAMAR (TRANSFER ROOM)*",'Pilih kamar aktif tamu yang ingin dipindahkan.','⚠️ Tidak ada sewa aktif yang dapat dipindahkan.',1);$replyText=$menu['text'];$replyMarkup=$menu['markup'];}
            } elseif (strpos($command, '/kamar_kosong') === 0) {
                if(!$loggedInStaff){$replyText="🔒 Telegram ID belum terhubung ke akun staf aktif.";}
                else{
                    $arg=trim(substr($command,strlen('/kamar_kosong')));
                    if($arg!==''){
                        $stmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmt->execute([$arg]);
                        if(!$stmt->fetchColumn()){$replyText="⚠️ Kamar {$arg} tidak memiliki booking aktif.";}
                        else{$replyText="🚪 *LAPORKAN KAMAR {$arg} KOSONG*

Pilih untuk memulai pemeriksaan singkat.";$replyMarkup=['inline_keyboard'=>[[['text'=>'Mulai laporan Kamar '.$arg,'callback_data'=>'vacancy_report_room:'.$arg]]]];}
                    }else{
                        $pageSize=30;$count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
                        if($count<=0)$replyText="ℹ️ Tidak ada kamar dengan booking aktif.";
                        else{
                            $stmt=$pdo->prepare("SELECT roomNumber FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber LIMIT ? OFFSET 0");$stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
                            $buttons=[];$row=[];foreach($rows as $number){$row[]=['text'=>'🚪 '.$number,'callback_data'=>'vacancy_report_room:'.$number];if(count($row)>=3){$buttons[]=$row;$row=[];}}if($row)$buttons[]=$row;
                            $totalPages=max(1,(int)ceil($count/$pageSize));if($totalPages>1)$buttons[]=$buildTelegramPagerRow(1,$totalPages,'vacancy_menu:p:');$buttons[]=[['text'=>'⬅️ Operasional','callback_data'=>'field_ops_menu']];
                            $replyText="🚪 *LAPORKAN KAMAR KOSONG*

Pilih kamar yang benar-benar sudah kosong. Laporan ini tidak langsung menjual kembali kamar; Resepsionis/Manajer harus menyelesaikan checkout dan Housekeeping harus menandai kamar siap.
📄 Halaman *1/{$totalPages}* · menampilkan maksimal *{$pageSize} booking aktif*.";$replyMarkup=['inline_keyboard'=>$buttons];
                        }
                    }
                }
            } elseif (strpos($command, '/checkout') === 0) {
                if(!$loggedInStaff){$replyText="🔒 *AKSES DITOLAK*!
Telegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";}
                else{
                    $rawArgs=trim(substr($command,strlen('/checkout')));$roomNum=trim((string)(preg_split('/\s+/',$rawArgs)[0]??''));
                    if($roomNum===''){
                        $menu=$buildTelegramActiveBookingPicker($pdo,'r_checkout:','booking_checkout_menu:p:',"🛎️ *PROSES CHECK-OUT KAMAR*",'Pilih kamar aktif yang ingin diproses check-out.','⚠️ Tidak ada sewa aktif yang dapat diproses check-out.',1);$replyText=$menu['text'];$replyMarkup=$menu['markup'];
                    }else{
                        $stmt=$pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1");$stmt->execute([$roomNum]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
                        if(!$booking){$replyText="⚠️ Kamar nomor {$roomNum} tidak sedang disewa (aktif)!";}
                        else{
                            $replyText="🛎️ *KONFIRMASI CHECK-OUT KAMAR {$roomNum}*

👤 Tamu: *" . tamasyaTelegramPlainText($booking['guestName'],120) . "*
📅 Tanggal Masuk: *{$booking['checkIn']}*
📅 Tanggal Keluar: *{$booking['checkOut']}*
💰 Total Biaya: *Rp ".tamasyaTelegramFormatAmount($booking['totalAmount'])."*
💳 Status Bayar: *".strtoupper((string)$booking['paymentStatus'])."*

Apakah Anda yakin ingin memproses check-out kamar ini?";
                            $replyMarkup=['inline_keyboard'=>[[['text'=>'✅ Lanjut Verifikasi Check-Out','callback_data'=>'r_checkout:'.$roomNum],['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]];
                        }
                    }
                }
            } elseif (strpos($command, '/ktp') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*\nTelegram ID belum terhubung ke akun staf aktif.";
                } elseif (!hasCapability($loggedInStaff, 'view_guest_identity', ['admin','manager','receptionist','keamanan'])) {
                    writeEnterpriseAudit($pdo,$loggedInStaff,'Akses KTP Telegram ditolak','booking',null,null,['reason'=>'missing_capability'],'telegram');
                    $replyText = "🔒 *AKSES KTP DITOLAK*\nJabatan/izin akun Anda tidak mengizinkan melihat identitas tamu.";
                } else {
                    $ktpRate = consumeRateLimit($pdo,'telegram-ktp:'.($loggedInStaff['id'] ?? $fromId),20,60,300);
                    if (empty($ktpRate['allowed'])) {
                        $replyText = "⚠️ Terlalu banyak permintaan KTP. Coba lagi dalam *".(int)$ktpRate['retryAfter']." detik*.";
                    } else {
                    $arg = trim(substr($command, 4));
                    if ($arg === '') {
                        $stmtKtpList = $pdo->query("SELECT id,roomNumber,status FROM bookings WHERE ktpPhoto IS NOT NULL AND ktpPhoto<>'' ORDER BY createdAt DESC LIMIT 12");
                        $rowsKtp = $stmtKtpList->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        if (!$rowsKtp) {
                            $replyText = "🪪 Belum ada foto KTP tersimpan di database.";
                        } else {
                            $lines = array_map(static fn($b) => "• Kamar *".$b['roomNumber']."* · `".$b['id']."` · ".$b['status'], $rowsKtp);
                            $replyText = "🪪 *KTP TAMU TERSEDIA*\n\n" . implode("\n", $lines) . "\n\nGunakan `/ktp NOMOR_KAMAR` atau `/ktp ID_BOOKING`. Setiap akses dicatat audit.";
                        }
                    } else {
                        if (strlen($arg) > 190) {
                            $replyText = "⚠️ ID booking/nomor kamar tidak valid.";
                            $bookingKtp = null;
                        } else {
                        $stmtKtp = $pdo->prepare("SELECT id,guestName,roomNumber,status,ktpPhoto FROM bookings WHERE id=? OR roomNumber=? ORDER BY (id=?) DESC,(status='active') DESC,createdAt DESC LIMIT 1");
                        $stmtKtp->execute([$arg,$arg,$arg]);
                        $bookingKtp = $stmtKtp->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$bookingKtp || trim((string)($bookingKtp['ktpPhoto'] ?? '')) === '') {
                            $replyText = "⚠️ KTP untuk booking/kamar `{$arg}` tidak ditemukan.";
                        } else {
                            $caption = "🪪 *KTP TAMU*\nKamar: *".$bookingKtp['roomNumber']."*\nBooking: `".$bookingKtp['id']."`\nStatus: *".$bookingKtp['status']."*";
                            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengotorisasi akses KTP tamu melalui Telegram','booking',$bookingKtp['id'],null,['roomNumber'=>$bookingKtp['roomNumber'],'bookingStatus'=>$bookingKtp['status'],'delivery'=>'telegram_pending'],'telegram');
                            $sentIdentity = telegramSendBookingIdentityPhoto($pdo,$token,$chatId,(string)$bookingKtp['ktpPhoto'],$caption);
                            writeEnterpriseAudit($pdo,$loggedInStaff,'Hasil pengiriman KTP tamu melalui Telegram','booking',$bookingKtp['id'],null,['roomNumber'=>$bookingKtp['roomNumber'],'sent'=>!empty($sentIdentity['ok'])],'telegram');
                            logActivity($pdo,'VIEW_GUEST_IDENTITY_TELEGRAM','Melihat KTP booking '.$bookingKtp['id'].' kamar '.$bookingKtp['roomNumber'],$loggedInStaff['id'] ?? null,$loggedInStaff['name'] ?? null);
                            $replyText = !empty($sentIdentity['ok'])
                                ? "✅ KTP Kamar *".$bookingKtp['roomNumber']."* berhasil dikirim melalui chat privat dan akses telah dicatat."
                                : "⚠️ Referensi KTP tersedia, tetapi foto gagal dikirim: " . ($sentIdentity['error'] ?? 'kesalahan server') . ". Gunakan menu Aktivitas / Fitur Khusus di aplikasi.";
                        }
                    }
                    }
                }
            } elseif (strpos($command, '/cetak_nota') === 0 || strpos($command, '/nota') === 0 || strpos($command, '/invoice') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    $roomNum = isset($parts[1]) ? trim($parts[1]) : "";

                    if (empty($roomNum)) {
                        // Ambil daftar booking terbaru (aktif & selesai) untuk dipilih
                        $stmtRecent = $pdo->query("SELECT * FROM bookings ORDER BY createdAt DESC LIMIT 10");
                        $recentBookings = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

                        if (empty($recentBookings)) {
                            $replyText = "⚠️ *TIDAK ADA DATA SEWA*!\n\nSaat ini tidak ada data sewa kamar di database untuk dicetak notanya.";
                        } else {
                            $inlineKeyboardButtons = [];
                            $currentRow = [];
                            foreach ($recentBookings as $b) {
                                $currentRow[] = [
                                    "text" => "🚪 Rm " . $b['roomNumber'] . " (" . $b['guestName'] . ")",
                                    "callback_data" => "print_receipt:" . $b['id']
                                ];
                                if (count($currentRow) >= 2) {
                                    $inlineKeyboardButtons[] = $currentRow;
                                    $currentRow = [];
                                }
                            }
                            if (!empty($currentRow)) {
                                $inlineKeyboardButtons[] = $currentRow;
                            }
                            $replyText = "🧾 *CETAK NOTA / INVOICE KAMAR*\n\nSilakan pilih salah satu sewa kamar terbaru untuk mencetak nota thermal:\n\n_(Atau ketik langsung `/cetak_nota <nomor_kamar>` untuk cetak instan)_";
                            $replyMarkup = ["inline_keyboard" => $inlineKeyboardButtons];
                        }
                    } else {
                        // Cari booking terbaru untuk nomor kamar tersebut
                        $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? ORDER BY createdAt DESC LIMIT 1");
                        $stmtBooking->execute([$roomNum]);
                        $booking = $stmtBooking->fetch(PDO::FETCH_ASSOC);

                        if (!$booking) {
                            $replyText = "⚠️ Kamar nomor {$roomNum} tidak memiliki riwayat data sewa!";
                        } else {
                            // Generate nota thermal
                            $replyText = generateBotThermalReceipt($booking, $loggedInStaff['name']);
                            if (hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist']) || hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist'])) {
                                $replyMarkup = ['inline_keyboard'=>[[['text'=>'✉️ Kirim nota ke email','callback_data'=>'email_receipt:'.$booking['id']]]]];
                            }
                        }
                    }
                }
            } elseif (strpos($command, '/cuti') === 0) {
                if(!$loggedInStaff){
                    $replyText="🔒 Telegram ID ini belum terhubung ke akun staf. Minta Admin/Manajer membuat kode binding, lalu kirim `/bind KODE`.";$replyMarkup=$userKeyboard;
                }else{
                    $menu=$buildTelegramLeaveMenu($pdo,$loggedInStaff);$replyText=$menu['text'];$replyMarkup=$menu['markup'];
                }
            } elseif (strpos($command, '/batal') === 0) {
                if($loggedInStaff)$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);
                $replyText="✅ Proses Telegram dibatalkan. Tidak ada transaksi baru yang disimpan.";$replyMarkup=$loggedInStaff?$keyboardForTelegramRole($loggedInStaff):$userKeyboard;
            } elseif (strpos($command, '/menu') === 0) {
                if($loggedInStaff){$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);}
                $replyText="🤖 *{$propertyName} Bot*\n\nPilih menu dengan tombol. Perintah `/...` tetap aktif sebagai shortcut/fallback.";
                $replyMarkup=$buildTelegramMainMenuMarkup($loggedInStaff ?: null);
            } elseif (strpos($command, '/ai_help') === 0) {
                if($loggedInStaff){$pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$loggedInStaff['id']]);}
                $replyText="🤖 *TANYA AI TAMASYA*\n\nKetik pertanyaan Anda sebagai pesan biasa, tanpa `/command`. Asisten menggunakan Gemini bila API key aktif dan membatasi konteks sesuai peran akun Anda.\n\nContoh: `berapa kamar tersedia?` atau `ringkas kondisi hotel hari ini`.";
                $replyMarkup=$loggedInStaff?$keyboardForTelegramRole($loggedInStaff):$userKeyboard;
            } elseif (strpos($command, '/start') === 0) {
                if ($loggedInStaff) {
                    $replyText = "🤖 *{$propertyName} Bot*\n\nHalo, *{$loggedInStaff['name']}*! Akun Anda terhubung dan aktif.\n\nGunakan tombol keyboard atau tekan *🏠 Menu Utama* untuk menjalankan fungsi hotel. Perintah `/...` lama tetap aktif sebagai shortcut/fallback bila diperlukan.\n\nUntuk AI, tekan *🤖 Tanya AI* lalu kirim pertanyaan sebagai teks biasa.";
                    $replyMarkup = $keyboardForTelegramRole($loggedInStaff);
                } else {
                    $replyText = "🤖 *{$propertyName} Bot*\n\n" .
                                 "Telegram ID ini belum terhubung ke akun staf.\n\n" .
                                 "Telegram User ID Anda: `{$fromId}`\n" .
                                 "Minta Admin/Manajer membuat kode binding sekali pakai dari menu Karyawan, lalu kirim `/bind KODE` melalui chat privat bot. Jangan pernah mengirim password melalui Telegram.\n\n" .
                                 "Setelah binding berhasil, kirim `/start` kembali.";
                }
            } elseif (strpos($command, '/login') === 0 || strpos($command, '/bind') === 0) {
                $replyText = "🔒 *LOGIN PASSWORD MELALUI TELEGRAM DINONAKTIFKAN*\n\n" .
                             "Telegram ID Anda: `{$fromId}`\n\n" .
                             "Minta Admin/Manajer membuat kode binding sekali pakai dari menu Karyawan, lalu kirim `/bind KODE` dari akun Telegram ini melalui chat privat. " .
                             "Setelah binding berhasil, kirim `/start` kembali. Binding lama yang ambigu atau berkonflik tidak dapat dipakai.";
            } elseif (strpos($command, '/logout') === 0) {
                if ($loggedInStaff) {
                    if ($loggedInStaff['role'] !== 'admin') {
                        $replyText = "⚠️ *AKSES LOGOUT DIKUNCI*!\n\nUntuk menghindari ketidaksengajaan keluar dari sesi bot, fitur logout langsung dinonaktifkan.\n\nSilakan **hubungi Administrator Utama (Admin)** untuk melepaskan atau mereset sesi Telegram Anda dari dasbor sistem.";
                    } else {
                        unbindTelegramIdentity($pdo, $loggedInStaff['id'], $fromId);
                        $replyText = "🔒 *LOGOUT BERHASIL*!\n\nSesi administrator Anda (*{$loggedInStaff['name']}*) telah dikeluarkan dari Telegram ID Anda.";
                    }
                } else {
                    $replyText = "Anda belum terhubung. Minta Admin/Manajer membuat kode binding sekali pakai, lalu kirim `/bind KODE`.";
                }
            } elseif (strpos($command, '/me') === 0 || strpos($command, '/profile') === 0) {
                if ($loggedInStaff) {
                    $replyText = "👤 *PROFIL STAF AKTIF*:\n\n" .
                                 "• Nama: *{$loggedInStaff['name']}*\n" .
                                 "• Nama pengguna: `{$loggedInStaff['username']}`\n" .
                                 "• Peran: *" . $telegramRoleLabel((string)$loggedInStaff['role']) . "*\n" .
                                 "• Status Sesi: *Terhubung dan Aktif*\n" .
                                 "• ID Chat Telegram: `{$chatId}`";
                } else {
                    $replyText = "🔒 Anda belum terhubung. Minta Admin/Manajer membuat kode binding sekali pakai, lalu kirim `/bind KODE`.";
                }
            } elseif (strpos($command, '/status_kamar') === 0) {
                $stmtRooms=$pdo->query("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED),number");$rooms=$stmtRooms->fetchAll(PDO::FETCH_ASSOC)?:[];
                $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));
                $available=[];$booked=[];$dirty=[];$maintenance=[];foreach($rooms as $r){$number=(string)($r['number']??'');$status=$roomStatusMap[$number]??'maintenance';if($status==='booked')$booked[]=$number;elseif($status==='available')$available[]=$number;elseif($status==='dirty')$dirty[]=$number;else$maintenance[]=$number;}
                if(!$loggedInStaff){
                    $replyText="📊 *KETERSEDIAAN KAMAR*:

🟢 Tersedia: *".count($available)." kamar*
🔴 Terisi: *".count($booked)." kamar*
🧹 Housekeeping/QC: *".count($dirty)." kamar*
🛠️ Servis/Blocker: *".count($maintenance)." kamar*

Hubungi petugas hotel untuk informasi nomor kamar yang tersedia.";
                }else{
                    $pageSize=30;$count=count($rooms);$totalPages=max(1,(int)ceil(max(1,$count)/$pageSize));$pageRows=array_slice($rooms,0,$pageSize);$detail=[];
                    foreach($pageRows as $room){$number=(string)($room['number']??'');$status=$roomStatusMap[$number]??'maintenance';$detail[]=($status==='booked'?'🔴':($status==='available'?'🟢':($status==='dirty'?'🧹':'🛠️'))).' '.$number;}
                    $detailLines=[];foreach(array_chunk($detail,3) as $chunk)$detailLines[]=implode(' · ',$chunk);
                    $replyText="📊 *KETERSEDIAAN KAMAR TERKINI*

🟢 Tersedia: *".count($available)."* · 🔴 Terisi: *".count($booked)."* · 🧹 HK/QC: *".count($dirty)."* · 🛠️ Servis: *".count($maintenance)."*

".($detailLines?implode("
",$detailLines):'Belum ada kamar.')."

📄 Halaman *1/{$totalPages}* · menampilkan maksimal *{$pageSize} kamar*.";
                    $buttons=[[['text'=>'🔄 Muat Ulang','callback_data'=>'room_status_menu']]];if($totalPages>1)$buttons[]=$buildTelegramPagerRow(1,$totalPages,'room_status_menu:p:');if(in_array(strtolower((string)$loggedInStaff['role']),['admin','manager','receptionist'],true))$buttons[]=[['text'=>'🔎 Detail Operasional Kamar','callback_data'=>'room_list']];$replyMarkup=['inline_keyboard'=>$buttons];
                }
            } elseif (strpos($command, '/update_status') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!hasCapability($loggedInStaff,'manage_room_access',['admin','manager','receptionist'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nDiagnosa detail status kamar hanya diizinkan untuk Admin, Manajer, atau Resepsionis.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    if (count($parts) < 2 || trim((string)($parts[1]??''))==='') {
                        $replyText = "⚠️ Format diagnosa: `/update_status <nomor_kamar>`\nContoh: `/update_status 101`\n\nAlias lama ini sekarang *read-only*; status kamar diturunkan otomatis dari lifecycle operasional.";
                    } else {
                        $roomNum = trim((string)$parts[1]);
                        $diagStmt=$pdo->prepare("SELECT number,status FROM rooms WHERE number=? LIMIT 1");$diagStmt->execute([$roomNum]);
                        $stored=$diagStmt->fetch(PDO::FETCH_ASSOC);
                        if(!$stored){
                            $replyText="⚠️ Kamar {$roomNum} tidak ditemukan.";
                        }else{
                            $roomBlockers=getRoomOperationalBlockers($pdo,$roomNum,false);
                            $derived=tamasyaDeriveRoomOperationalStatus($roomBlockers);
                            $storedStatus=strtolower(trim((string)($stored['status']??'')));
                            $mismatch=$storedStatus!==$derived?"\n⚠️ Cache DB: *".strtoupper($storedStatus?:'-')."* berbeda dari projection; gunakan Refresh Proyeksi Operasional.":'';
                            $replyText="🔎 *DIAGNOSA KAMAR {$roomNum}*\n\nStatus canonical: *".strtoupper($derived)."*{$mismatch}\n\n".($roomBlockers?roomOperationalBlockerMessage($roomNum,$roomBlockers):'Tidak ada blocker operasional. Kamar secara domain siap tersedia.')."\n\n`/update_status` dipertahankan sebagai alias diagnosa read-only; status tidak dapat diset manual.";
                        }
                    }
                }
            } elseif (strpos($command, '/jual') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!hasCapability($loggedInStaff,'sell_room_access',['admin','manager','receptionist'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, penjualan kamar hanya diizinkan untuk akun dengan akses penjualan (Admin, Manajer, Resepsionis, atau centangan Jual Kamar & Reservasi).";
                } else {
                    $rawArgs = trim(substr($command, 5));
                    $parts = preg_split('/\s+/', $rawArgs);
                    if (count($parts) < 2) {
                        $replyText = "⚠️ Format salah! Gunakan:\n`/jual <nomor_kamar> <Nama Tamu>`\n\n*Contoh*:\n`/jual 101 Budi Santoso` (1 Malam, Lunas)\n`/jual 101 Ani Rahma 2 belum` (2 malam, belum lunas)\n`/jual 101 Adi terbuka 250000 tunai` (durasi terbuka, DP Rp 250rb tunai)";
                    } else {
                        $roomNum = $parts[0];
                        $nights = 1;
                        $paymentStatus = "paid";
                        
                        $nameParts = array_slice($parts, 1);
                        
                        // Check if it's an open-ended stay
                        $isOpenEnded = 0;
                        $dpAmount = 0.0;
                        $dpMethod = 'cash';
                        
                        // Check if 'open' is specified in $nameParts
                        $openIndex = -1;
                        for ($idx = 0; $idx < count($nameParts); $idx++) {
                            if (in_array(strtolower($nameParts[$idx]), ['open','terbuka'], true)) {
                                $openIndex = $idx;
                                break;
                            }
                        }
                        
                        if ($openIndex !== -1) {
                            $isOpenEnded = 1;
                            // Extract guest name parts before 'open'
                            $guestNameParts = array_slice($nameParts, 0, $openIndex);
                            $guestName = implode(" ", $guestNameParts);
                            
                            // Extract DP details if any
                            if (isset($nameParts[$openIndex + 1])) {
                                $dpAmount = tamasyaTelegramParseMoney((string)$nameParts[$openIndex + 1])??-1;
                            }
                            if (isset($nameParts[$openIndex + 2])) {
                                $methodVal = strtolower($nameParts[$openIndex + 2]);
                                if (in_array($methodVal, ['cash', 'tunai', 'cash/tunai'])) {
                                    $dpMethod = 'cash';
                                } elseif (in_array($methodVal, ['qris', 'edc', 'edc_qris'])) {
                                    $dpMethod = 'qris';
                                } elseif (in_array($methodVal, ['transfer', 'bank', 'bca', 'mandiri'])) {
                                    $dpMethod = 'transfer';
                                }
                            }
                        } else {
                            // Standard night-based logic
                            $lastPart = strtolower($nameParts[count($nameParts) - 1]);
                            if (in_array($lastPart, ['paid','lunas'], true)) {
                                $paymentStatus = 'paid';
                                array_pop($nameParts);
                            } elseif (in_array($lastPart, ['unpaid','belum','belum_lunas'], true)) {
                                $paymentStatus = 'unpaid';
                                array_pop($nameParts);
                            }
                            
                            if (count($nameParts) > 0) {
                                $newLastPart = $nameParts[count($nameParts) - 1];
                                if (preg_match('/^\d+$/', $newLastPart)) {
                                    $nights = (int)$newLastPart;
                                    array_pop($nameParts);
                                }
                            }
                            $guestName = implode(" ", $nameParts);
                        }
                        
                        if (empty($guestName)) {
                            $replyText = "⚠️ Nama tamu tidak boleh kosong!";
                        } else {
                            $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
                            $stmtRoom->execute([$roomNum]);
                            $room = $stmtRoom->fetch();
                            
                            if (!$room) {
                                $replyText = "⚠️ Kamar nomor {$roomNum} tidak ditemukan!";
                            } elseif (tamasyaDeriveRoomOperationalStatus(getRoomOperationalBlockers($pdo,$roomNum,false)) !== 'available') {
                                $roomBlockers=getRoomOperationalBlockers($pdo,$roomNum,false);
                                $replyText = $roomBlockers ? "⚠️ ".roomOperationalBlockerMessage($roomNum,$roomBlockers) : "⚠️ Kamar nomor {$roomNum} belum tersedia secara operasional.";
                            } else {
                                if ($isOpenEnded) {
                                    try {
                                        $checkIn = date("Y-m-d");
                                        $checkOut = $checkIn;
                                        if ($dpAmount > 0 && $dpMethod !== 'cash') {
                                            throw new InvalidArgumentException('Panjar transfer/QRIS melalui perintah teks wajib memakai menu tombol agar rekening tujuan dipilih dengan jelas.');
                                        }
                                        $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                            'guestName' => $guestName,
                                            'roomNumber' => $roomNum,
                                            'roomType' => $room['type'],
                                            'checkIn' => $checkIn,
                                            'checkOut' => $checkOut,
                                            'totalAmount' => 0,
                                            'paymentStatus' => 'unpaid',
                                            'bookingSource' => 'Direct',
                                            'lifecycleIntent' => 'check_in_now',
                                            'isOpenEnded' => 1,
                                            'downPaymentAmount' => $dpAmount,
                                            'downPaymentMethod' => $dpAmount > 0 ? 'cash' : null,
                                            'downPaymentDate' => $checkIn,
                                            'paymentDate' => $checkIn
                                        ], 'telegram', $telegramUpdateOperationId . ':booking');
                                        $bookingId = (string)$bookingResult['bookingId'];
                                        
                                        $replyText = "🎉 *PROSES SEWA DURASI TERBUKA BERHASIL (Melalui Chat)*! 🎉\n\n" .
                                                     "🔑 Kamar: *{$roomNum}* (*{$room['type']}*)\n" .
                                                     "👤 Tamu: *{$guestName}*\n" .
                                                     "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                     "📅 Durasi: *Durasi Terbuka* (Tanpa tanggal checkout pasti)\n" .
                                                     "💵 Uang Panjar (DP): *Rp " . tamasyaTelegramFormatAmount($dpAmount) . "*\n" .
                                                     "💳 Metode DP: *" . $telegramPaymentMethodLabel((string)$dpMethod) . "*\n" .
                                                     "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                     "Data otomatis tersinkron ke dasbor hotel!";
                                                     
                                    } catch (Exception $e) {
                                        if ($pdo->inTransaction()) $pdo->rollBack();
                                        $replyText = clientExceptionMessage("❌ Gagal memproses sewa durasi terbuka", $e);
                                    }
                                } else {
                                    try {
                                        $checkIn = date("Y-m-d");
                                        $checkOut = date("Y-m-d", strtotime("+$nights day"));
                                        $vatRate = resolveConfiguredTaxRate($pdo, 'Direct', 'room', 0, $checkIn);
                                        $totalAmount = tamasyaPublishedRoomGrossTotal((float)$room['price'], (int)$nights, (float)$vatRate);
                                        $inclusive = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
                                        $baseAmount = $inclusive['baseAmount'];
                                        $vatAmount = $inclusive['taxAmount'];
                                        $bookingResult = createCanonicalBookingWorkflow($pdo, $loggedInStaff, [
                                            'guestName' => $guestName,
                                            'roomNumber' => $roomNum,
                                            'roomType' => $room['type'],
                                            'checkIn' => $checkIn,
                                            'checkOut' => $checkOut,
                                            'totalAmount' => $totalAmount,
                                            'paymentStatus' => $paymentStatus,
                                            'paymentMethod' => $paymentStatus === 'paid' ? 'cash' : null,
                                            'paymentDate' => $checkIn,
                                            'bookingSource' => 'Direct',
                                            'lifecycleIntent' => 'check_in_now'
                                        ], 'telegram', $telegramUpdateOperationId . ':booking');
                                        $bookingId = (string)$bookingResult['bookingId'];
                                        $vatRate = (float)$bookingResult['vatRate'];
                                        $vatAmount = (float)$bookingResult['vatAmount'];
                                        $totalAmount = (float)$bookingResult['totalAmount'];
                                        $baseAmount = max(0, $totalAmount - $vatAmount);
                                        
                                        $replyText = "🎉 *PROSES SEWA KAMAR BERHASIL (Melalui Chat)*! 🎉\n\n" .
                                                     "🔑 Kamar: *{$roomNum}* (*{$room['type']}*)\n" .
                                                     "👤 Tamu: *{$guestName}*\n" .
                                                     "📅 Tanggal Masuk: *{$checkIn}*\n" .
                                                     "📅 Tanggal Keluar: *{$checkOut}* (*{$nights} Malam*)\n" .
                                                     "💵 Tarif Kamar: *Rp " . tamasyaTelegramFormatAmount($baseAmount) . "*\n" .
                                                     "🧾 Pajak PBJT (" . $vatRate . "%): *Rp " . tamasyaTelegramFormatAmount($vatAmount) . "*\n" .
                                                     "💰 Total: *Rp " . tamasyaTelegramFormatAmount($totalAmount) . "*\n" .
                                                     "💳 Status: *" . $telegramPaymentStatusLabel((string)$paymentStatus) . "*\n" .
                                                     "👤 Resepsionis: *{$loggedInStaff['name']}*\n\n" .
                                                     "Data otomatis tersinkron ke dasbor hotel!";
                                                     
                                    } catch (Exception $e) {
                                        if ($pdo->inTransaction()) $pdo->rollBack();
                                        $replyText = clientExceptionMessage("❌ Gagal memproses penjualan kamar", $e);
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif (strpos($command, '/pemasukan') === 0 && strpos($command, '/pemasukan_sub') !== 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'], true)) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nPemasukan manual hanya diizinkan untuk Admin, Manajer, atau Finance. Resepsionis wajib memakai alur booking/checkout agar ledger tidak ganda.";
                } else {
                    $replyText = "⚠️ *FORMAT /pemasukan LAMA DINONAKTIFKAN*\n\nPerintah `/pemasukan <kamar> <nominal>` tidak mempunyai identitas kategori yang cukup dan dulu dapat membuat pendapatan kamar manual di luar folio.\n\nGunakan menu *📥 Input Pemasukan* lalu pilih kategori pemasukan umum, atau gunakan workflow *Booking/Checkout/Layanan Tamu* untuk pembayaran kamar/add-on agar saldo folio, PBJT, kas/bank, dan jurnal tetap sinkron.";
                }
            } elseif (strpos($command, '/pemasukan_sub') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'], true)) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nPemasukan manual berkategori hanya diizinkan untuk Admin, Manajer, atau Finance.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    if (count($parts) < 5) {
                        $replyText = "⚠️ Format salah! Gunakan: `/pemasukan_sub <kamar> <catId> <subId> <nominal>`\n(contoh: `/pemasukan_sub 101 cat_tambahan sub_laundry 45000`)";
                    } else {
                        $roomNum = trim($parts[1]);
                        $catId = trim($parts[2]);
                        $subId = trim($parts[3]);
                        $amount = tamasyaTelegramParseMoney(trim($parts[4]));
                        
                        if ($amount === null || $amount <= 0) {
                            $replyText = "⚠️ Nominal harus berupa angka valid (contoh: 150000 atau 150.000).";
                        } else {
                            try {
                                $pair = requireTelegramFinancialCategoryPair($pdo,(string)$catId,(string)$subId,'income');
                                $room = requireTelegramRoom($pdo,(string)$roomNum,false);
                                $categoryName = $pair['category_name'];
                                $subcategoryName = $pair['subcategory_name'];
                            } catch (Throwable $pairError) {
                                $replyText = "❌ " . clientExceptionMessage('Pemasukan ditolak',$pairError);
                                $room = null;
                            }
                            
                            if ($room) {
                                
                                // Booking aktif hanya memberi konteks sumber/pajak untuk tax_rules.
                                // /pemasukan_sub tetap transaksi kas manual, bukan pembayaran folio.
                                $bookingId = null;
                                $bookingForTax = null;
                                if (!empty($roomNum)) {
                                    $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1");
                                    $stmtB->execute([$roomNum]);
                                    $bRow = $stmtB->fetch();
                                    if ($bRow) {
                                        $bookingId = $bRow['id'];
                                        $bookingForTax = $bRow;
                                    }
                                }

                                $today = date("Y-m-d");
                                $desc = "Pemasukan " . $categoryName . " (" . $subcategoryName . ") Kamar " . $roomNum . " via Telegram oleh " . $loggedInStaff['name'];
                                $bookingSource = $bookingForTax['bookingSource'] ?? 'Direct';
                                $bankAccountId = null; // /pemasukan adalah kas manual; rekening booking tidak boleh diwarisi.
                                $notifMsg = "Transaksi Masuk oleh {$loggedInStaff['name']} via Telegram Bot: Pemasukan {$categoryName} ({$subcategoryName}) Kamar {$roomNum} senilai Rp " . tamasyaTelegramFormatAmount($amount);
                                                                try {
$tgTx=createTelegramCashTransaction($pdo, $telegramUpdateOperationId, $loggedInStaff, [
                                    'type'=>'income','category'=>$categoryName,'categoryId'=>$catId,'subcategory'=>$subcategoryName,'subcategoryId'=>$subId,'roomNumber'=>$roomNum,'amount'=>$amount,
                                    'date'=>$today,'description'=>$desc,'bookingId'=>null,'bookingSource'=>$bookingSource,'bankAccountId'=>$bankAccountId
                                ], $notifMsg, 'message');
                                $taxRate=(float)($tgTx['taxRate']??0);
                                $baseAmount = array_key_exists('baseAmount', $tgTx) && $tgTx['baseAmount'] !== null ? (float) $tgTx['baseAmount'] : null;
                                $baseAmountDisplay = $baseAmount === null ? 'unresolved' : tamasyaTelegramFormatAmount($baseAmount);
                                $taxAmount=(float)($tgTx['taxAmount']??0);

                                $replyText = "✅ *SUKSES INPUT PEMASUKAN DENGAN KATEGORI*!\n\n" .
                                             "Transaksi Berhasil Disimpan:\n" .
                                             "- Kamar: " . $roomNum . "\n" .
                                             "- Kategori: " . $categoryName . "\n" .
                                             "- Subkategori: " . $subcategoryName . "\n" .
                                             "- Harga Dasar: " . $baseAmountDisplay . "\n" .
                                             ($taxRate > 0
                                                ? "- Pajak PBJT ({$taxRate}%): Rp " . tamasyaTelegramFormatAmount($taxAmount) . "\n"
                                                : "- Pajak PBJT: Rp 0 (sesuai tax_rules aktif)\n") .
                                             "- Total Nominal: Rp " . tamasyaTelegramFormatAmount($amount) . "\n" .
                                             "- Petugas: " . $loggedInStaff['name'] . "\n" .
                                             "- Deskripsi: " . $desc;
                                } catch (Throwable $incomeSubError) {
                                    $replyText = "❌ " . clientExceptionMessage('Pemasukan ditolak', $incomeSubError);
                                }
                            }
                        }
                    }
                }
            } elseif (strpos($command, '/pengeluaran_sub') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, penginputan pengeluaran kas hanya diizinkan untuk Admin, Manajer, atau divisi Keuangan.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    if (count($parts) < 5) {
                        $replyText = "⚠️ Format salah! Gunakan: `/pengeluaran_sub <catId> <subId> <nominal> <deskripsi>`\n(contoh: `/pengeluaran_sub <categoryId> <subcategoryId> 150000 Token listrik darurat`)";
                    } else {
                        $catId = trim($parts[1]);
                        $subId = trim($parts[2]);
                        $amount = tamasyaTelegramParseMoney(trim($parts[3]));
                        $description = implode(" ", array_slice($parts, 4));
                        
                        if ($amount === null || $amount <= 0) {
                            $replyText = "⚠️ Nominal harus berupa angka valid (contoh: 150000 atau 150.000).";
                        } else {
                            try {
                                $pair = requireTelegramFinancialCategoryPair($pdo,(string)$catId,(string)$subId,'expense');
                                $categoryName = $pair['category_name'];
                                $subcategoryName = $pair['subcategory_name'];
                            } catch (Throwable $pairError) {
                                $replyText = "❌ " . clientExceptionMessage('Pengeluaran ditolak',$pairError);
                                $categoryName = $subcategoryName = null;
                            }
                            
                            if ($categoryName !== null && $subcategoryName !== null) {
                                
                                $today = date("Y-m-d");
                                $finalDesc = $description . " (Telegram Bot oleh " . $loggedInStaff['name'] . ")";
                                $notifMsg = "Transaksi Keluar oleh {$loggedInStaff['name']} via Telegram Bot: Pengeluaran {$categoryName} ({$subcategoryName}) senilai Rp " . tamasyaTelegramFormatAmount($amount);
                                try {
                                    createTelegramCashTransaction($pdo, $telegramUpdateOperationId, $loggedInStaff, [
                                        'type'=>'expense','category'=>$categoryName,'categoryId'=>$catId,'subcategory'=>$subcategoryName,'subcategoryId'=>$subId,'amount'=>$amount,'date'=>$today,
                                        'description'=>$finalDesc,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
                                        'bankAccountId'=>null
                                    ], $notifMsg, 'message');

                                    $replyText = "✅ *SUKSES INPUT PENGELUARAN DENGAN KATEGORI*!\n\n" .
                                                 "Transaksi Pengeluaran Disimpan:\n" .
                                                 "- Kategori: " . $categoryName . "\n" .
                                                 "- Subkategori: " . $subcategoryName . "\n" .
                                                 "- Nominal: Rp " . tamasyaTelegramFormatAmount($amount) . "\n" .
                                                 "- Petugas: " . $loggedInStaff['name'] . "\n" .
                                                 "- Deskripsi: " . $description . " (Telegram Bot)";
                                } catch (Throwable $expenseSubError) {
                                    $replyText = "❌ " . clientExceptionMessage('Pengeluaran ditolak', $expenseSubError);
                                }
                            }
                        }
                    }
                }
            } elseif (strpos($command, '/pengeluaran') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array($loggedInStaff['role'], ['admin', 'manager', 'finance'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, penginputan pengeluaran kas hanya diizinkan untuk Admin, Manajer, atau divisi Keuangan.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    if (count($parts) < 4) {
                        $replyText = "⚠️ Format salah! Gunakan: `/pengeluaran <categoryId_atau_nama> <nominal> <deskripsi>`\n(contoh: `/pengeluaran cat_xxx 150000 Token listrik`)";
                    } else {
                        $categoryInput = trim($parts[1]);
                        $amount = tamasyaTelegramParseMoney(trim($parts[2]));
                        $description = implode(" ", array_slice($parts, 3));
                        
                        if ($amount === null || $amount <= 0) {
                            $replyText = "⚠️ Nominal harus berupa angka valid (contoh: 150000 atau 150.000).";
                        } else {
                            // 1. Try to find dynamic category in database by ID or by name
                            $stmtCat = $pdo->prepare("SELECT * FROM categories WHERE type='expense' AND is_active=1 AND COALESCE(is_system,0)=0 AND COALESCE(system_key,'') NOT IN ('payroll_expense','maintenance_expense','pos_refund','pos_cogs') AND (id=? OR LOWER(name)=?)");
                            $stmtCat->execute([$categoryInput, strtolower($categoryInput)]);
                            $catRow = $stmtCat->fetch();
                            
                            $finalCategory = null;
                            $finalCategoryId = null;
                            $subcat = null;
                            if ($catRow) {
                                if ((int)($catRow['is_system'] ?? 0) === 1 || in_array((string)($catRow['system_key'] ?? ''), ['payroll_expense','maintenance_expense','pos_refund','pos_cogs'], true)) {
                                    $replyText = "❌ Kategori sistem tidak dapat dipakai untuk input manual.";
                                    $catRow = null;
                                }
                            }
                            if ($catRow) {
                                $finalCategory = $catRow['name'];
                                $finalCategoryId = $catRow['id'];
                            }
                            
                            if (!$finalCategory) {
                                $replyText = "⚠️ Kategori pengeluaran tidak valid atau tidak ditemukan di database!";
                            } else {
                                $today = date("Y-m-d");
                                $finalDesc = $description . " (Telegram Bot oleh " . $loggedInStaff['name'] . ")";
                                $notifMsg = "Transaksi Keluar oleh {$loggedInStaff['name']} via Telegram Bot: Pengeluaran {$finalCategory} senilai Rp " . tamasyaTelegramFormatAmount($amount);
                                try {
                                    createTelegramCashTransaction($pdo, $telegramUpdateOperationId, $loggedInStaff, [
                                        'type'=>'expense','category'=>$finalCategory,'categoryId'=>$finalCategoryId,'categorySystemKey'=>$catRow['system_key']??null,'subcategory'=>$subcat,'amount'=>$amount,'date'=>$today,
                                        'description'=>$finalDesc,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
                                        'bankAccountId'=>null
                                    ], $notifMsg, 'message');

                                    $replyText = "✅ *SUKSES INPUT PENGELUARAN*!\n\n" .
                                                 "Transaksi Pengeluaran Disimpan:\n" .
                                                 "- Kategori: " . $finalCategory . "\n" .
                                                 ($subcat ? "- Subkategori: " . $subcat . "\n" : "") .
                                                 "- Nominal: Rp " . tamasyaTelegramFormatAmount($amount) . "\n" .
                                                 "- Petugas: " . $loggedInStaff['name'] . "\n" .
                                                 "- Deskripsi: " . $description . " (Telegram Bot)";
                                } catch (Throwable $expenseCommandError) {
                                    $replyText = "❌ " . clientExceptionMessage('Pengeluaran ditolak', $expenseCommandError);
                                }
                            }
                        }
                    }
                }
            } elseif ($command === '/integritas') {
                if(!$loggedInStaff || !in_array(strtolower((string)$loggedInStaff['role']),['admin','manager','finance'],true)){
                    $replyText="🔒 *AKSES DITOLAK*\nConsistency Guard hanya untuk Admin, Manajer, atau Keuangan.";
                }else{
                    try{
                        $guard=tamasyaConsistencyGuardTelegramSummary($pdo,3);
                        $replyText=(string)$guard['text'];
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'🔄 Periksa Lagi','callback_data'=>'consistency_guard']],[['text'=>'👤 Akun & Diagnosa','callback_data'=>'account_menu']]]];
                    }catch(Throwable $e){$replyText=clientExceptionMessage('❌ Consistency Guard gagal membaca status',$e)."\nTidak ada data bisnis yang diubah.";}
                }
            } elseif (strpos($command, '/laporan') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array($loggedInStaff['role'], ['admin', 'owner', 'manager', 'finance'])) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, laporan keuangan hanya diizinkan untuk Admin, Owner, Manajer, atau divisi Keuangan.";
                } else {
                    $saldoAwal = isset($confRow['initial_balance']) ? (float)$confRow['initial_balance'] : 0;
                    $cashSummary = tamasyaCanonicalCashSummary($pdo,$saldoAwal);
                    $income = (float)$cashSummary['liquidIncome'];
                    $expense = (float)$cashSummary['liquidExpense'];
                    $cashflow = (float)$cashSummary['cashFlow'];
                    $cashOnlyIncome=(float)$cashSummary['cashOnlyIncome'];
                    $digitalIncome=(float)$cashSummary['digitalIncome'];
                    $finalBalance = (float)$cashSummary['liquidBalance'];

                    $replyText = "📈 *LAPORAN ARUS KAS {$propertyName}*\n\n" .
                                 "👤 Diakses oleh: *{$loggedInStaff['name']}* (*" . $telegramRoleLabel((string)$loggedInStaff['role']) . "*)\n\n" .
                                 "💰 Saldo Awal: Rp " . tamasyaTelegramFormatAmount($saldoAwal) . "\n" .
                                 "📥 Total Pemasukan Likuid: Rp " . tamasyaTelegramFormatAmount($income) . "\n" .
                                 "   ├ Tunai: Rp " . tamasyaTelegramFormatAmount($cashOnlyIncome) . "\n" .
                                 "   └ Transfer/QRIS: Rp " . tamasyaTelegramFormatAmount($digitalIncome) . "\n" .
                                 "📤 Total Pengeluaran Likuid: Rp " . tamasyaTelegramFormatAmount($expense) . "\n" .
                                 "------------------------------------\n" .
                                 "💵 *Arus Likuid Bersih: Rp " . tamasyaTelegramFormatAmount($cashflow) . "*\n" .
                                 "💰 *Saldo Likuid Akhir: Rp " . tamasyaTelegramFormatAmount($finalBalance) . "*\n\n" .
                                 "Laporan langsung tersinkron ke semua perangkat terhubung.";
                }
            } elseif (strpos($command, '/riwayat_shift') === 0) {
                $histReaderRoles = function_exists('tamasyaShiftHistoryReaderRoles') ? tamasyaShiftHistoryReaderRoles() : ['admin','owner','manager','finance'];
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!in_array(strtolower((string)$loggedInStaff['role']), array_map('strtolower', $histReaderRoles), true)) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nMaaf, riwayat tutup shift hanya diizinkan untuk Admin, Owner, Manajer, atau divisi Keuangan.";
                } else {
                    $histArg = trim(substr($command, strlen('/riwayat_shift')));
                    $histArg = ltrim($histArg, " :=");
                    $histPage = 1;
                    if ($histArg !== '' && preg_match('/^p?(\d{1,3})$/', $histArg, $histPageMatch)) {
                        $histPage = max(1, (int)$histPageMatch[1]);
                    }
                    $histData = tamasyaClosedShiftHistory($pdo, $histPage, 5);
                    if ($histData['count'] <= 0) {
                        $replyText = "📋 *RIWAYAT TUTUP SHIFT*\n\nBelum ada shift yang ditutup.";
                        $replyMarkup = ['inline_keyboard' => [[['text' => '⬅️ Kas & Shift', 'callback_data' => 'cash_shift_menu']]]];
                    } else {
                        $histLines = [];
                        foreach ($histData['rows'] as $histRow) {
                            $histVar = (float)($histRow['variance'] ?? 0);
                            $histLines[] = '• ' . substr((string)($histRow['shift_date'] ?? '-'), 0, 10) . ' ' . tamasyaTelegramPlainText((string)($histRow['shift_time'] ?? ''), 12) . ' — ' . tamasyaTelegramPlainText((string)($histRow['staff_name'] ?? '-'), 30) . ' — Rp ' . tamasyaTelegramFormatAmount((float)($histRow['actual_cash'] ?? 0)) . ($histVar != 0 ? ' (selisih Rp ' . tamasyaTelegramFormatAmount($histVar) . ')' : '');
                        }
                        $replyText = "📋 *RIWAYAT TUTUP SHIFT*\n\n" . implode("\n", $histLines) . "\n\nHalaman *{$histData['page']}/{$histData['totalPages']}* · {$histData['count']} shift. Ketuk tombol untuk rincian.";
                        $histButtons = [];
                        foreach ($histData['rows'] as $histRow) $histButtons[] = [['text' => '🧾 ' . substr((string)($histRow['shift_date'] ?? '?'), 5, 5) . ' ' . tamasyaTelegramPlainText((string)($histRow['shift_time'] ?? ''), 12), 'callback_data' => 'riwayat_shift_detail:' . (string)$histRow['id']]];
                        if ($histData['totalPages'] > 1) $histButtons[] = $buildTelegramPagerRow($histData['page'], $histData['totalPages'], 'riwayat_shift_menu:p:');
                        $histButtons[] = [['text' => '⬅️ Kas & Shift', 'callback_data' => 'cash_shift_menu']];
                        $replyMarkup = ['inline_keyboard' => $histButtons];
                    }
                }
            } elseif ($command === "/housekeeping") {
                $pageSize=30;$countRooms=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();$totalPages=max(1,(int)ceil(max(1,$countRooms)/$pageSize));
                $stmtRooms=$pdo->prepare("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED) ASC, number ASC LIMIT ? OFFSET 0");$stmtRooms->bindValue(1,$pageSize,PDO::PARAM_INT);$stmtRooms->execute();$rooms=$stmtRooms->fetchAll(PDO::FETCH_ASSOC)?:[];
                $roomStatusMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rooms,'number'));
                $inlineKeyboardButtons=[];$currentRow=[];foreach($rooms as $room){$number=(string)$room['number'];$canonical=$roomStatusMap[$number]??'maintenance';$statusIcon=$canonical==='booked'?'🔴':($canonical==='dirty'?'🧹':($canonical==='maintenance'?'🛠️':'🟢'));$currentRow[]=['text'=>$statusIcon.' Kamar '.$number,'callback_data'=>'hk_room:'.$number];if(count($currentRow)>=3){$inlineKeyboardButtons[]=$currentRow;$currentRow=[];}}if($currentRow)$inlineKeyboardButtons[]=$currentRow;
                if($totalPages>1)$inlineKeyboardButtons[]=$buildTelegramPagerRow(1,$totalPages,'housekeeping_menu:p:');$inlineKeyboardButtons[]=[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']];
                $replyText="🧹 *HOUSEKEEPING KAMAR*

Pilih nomor kamar untuk mengelola status Housekeeping.
📄 Halaman *1/{$totalPages}* · menampilkan maksimal *{$pageSize} kamar*.";$replyMarkup=['inline_keyboard'=>$inlineKeyboardButtons];
            } elseif ($command === "/patroli_laporan") {
                $replyText = "🚨 *PATROLI & LAPORAN LAPANGAN*\n\nSelamat datang di konsol petugas lapangan harian. Silakan pilih tindakan keamanan atau buat laporan insiden:";
                $replyMarkup = [
                    "inline_keyboard" => [
                        [
                            ["text" => "🏃‍♂️ Mulai Patroli Keliling", "callback_data" => "patrol_action:start"]
                        ],
                        [
                            ["text" => "🛡️ Patroli Selesai (Area Aman)", "callback_data" => "patrol_action:finish_safe"]
                        ],
                        [
                            ["text" => "📢 Buat Laporan Insiden Baru", "callback_data" => "incident_report_init"]
                        ],
                        [
                            ["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]
                        ]
                    ]
                ];
            } elseif (strpos($command, '/panjar') === 0) {
                if(!$loggedInStaff){$replyText='🔒 Telegram ID belum terhubung ke akun staf.';}
                elseif(!hasCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance'])){$replyText='🔒 Akun ini tidak memiliki izin menerima panjar.';}
                else{
                    $pageSize=30;$count=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
                    if($count<=0){$replyText='ℹ️ Tidak ada booking aktif yang dapat menerima panjar.';}
                    else{
                        $stmt=$pdo->prepare("SELECT id,roomNumber,guestName,isOpenEnded,totalAmount,amountPaid,downPaymentAmount FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber LIMIT ? OFFSET 0");$stmt->bindValue(1,$pageSize,PDO::PARAM_INT);$stmt->execute();$active=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                        $buttons=[];foreach($active as $booking){$paid=(float)($booking['amountPaid']??$booking['downPaymentAmount']??0);$buttons[]=[["text"=>'🚪 '.$booking['roomNumber'].' · Rp '.tamasyaTelegramFormatAmount($paid),"callback_data"=>'dp_room:'.$booking['id']]];}
                        $totalPages=max(1,(int)ceil($count/$pageSize));if($totalPages>1)$buttons[]=$buildTelegramPagerRow(1,$totalPages,'panjar_menu:p:');$buttons[]=[["text"=>'⬅️ Menu Utama',"callback_data"=>'main_menu']];$replyMarkup=['inline_keyboard'=>$buttons];
                        $replyText="💵 *TERIMA PANJAR BERULANG*

Pilih kamar. Setiap pembayaran disimpan sebagai ledger terpisah dan tidak menimpa pembayaran sebelumnya.
📄 Halaman *1/{$totalPages}* · menampilkan maksimal *{$pageSize} booking aktif*.";
                    }
                }
            } elseif ($command === "/diagnosa_shift") {
                $timezone=date_default_timezone_get();
                $patchLevel=defined('TAMASYA_PATCH_LEVEL') ? TAMASYA_PATCH_LEVEL : 'UNKNOWN';
                $dbClock=['db_timezone'=>'UNKNOWN','db_now'=>'UNKNOWN','db_date'=>'UNKNOWN'];
                try {
                    $dbClockRow=$pdo->query("SELECT @@session.time_zone AS db_timezone, NOW() AS db_now, CURDATE() AS db_date")->fetch(PDO::FETCH_ASSOC)?:[];
                    $dbClock=array_merge($dbClock,$dbClockRow);
                } catch (Throwable $dbClockError) {
                    $dbClock['db_timezone']='GAGAL: '.substr($dbClockError->getMessage(),0,80);
                }
                foreach (['db_timezone','db_now','db_date'] as $clockKey) {
                    $dbClock[$clockKey]=str_replace(['*','_','`','[',']','(',')'],'',(string)$dbClock[$clockKey]);
                }
                $expectedWebhook=buildPublicWebhookUrl();
                $remote=telegramApiCall($token,'getWebhookInfo');
                $remoteUrl=!empty($remote['ok']) ? trim((string)($remote['data']['result']['url']??'')) : '';
                $pendingUpdates=!empty($remote['ok']) ? (int)($remote['data']['result']['pending_update_count']??0) : -1;
                $remoteError=!empty($remote['ok']) ? trim((string)($remote['data']['result']['last_error_message']??'')) : telegramApiErrorMessage($remote);
                $remoteError=str_replace(['*','_','`','[',']','(',')'],'',$remoteError);
                $webhookMatch=$remoteUrl!=='' && hash_equals($expectedWebhook,$remoteUrl);
                if(!$loggedInStaff){
                    $replyText="🩺 *DIAGNOSA SHIFT TELEGRAM*\n\n❌ Koneksi akun staf: *BELUM TERHUBUNG*\n🆔 Telegram User ID: `{$fromId}`\n🌐 Status webhook: *".($webhookMatch?'SINKRON':'TIDAK SINKRON / BELUM AKTIF')."*\n🏷️ Versi aktif: *{$patchLevel}*\n🕒 Zona waktu aplikasi: *{$timezone}*\n🗄️ Zona waktu basis data: *{$dbClock['db_timezone']}*\n📅 Waktu basis data: *{$dbClock['db_now']}*\n\nBuat kode binding satu kali dari aplikasi, lalu kirim `/bind KODE` dalam 10 menit. Setelah berhasil, jalankan `/diagnosa_shift` kembali.";
                } else {
                    $role=strtolower((string)($loggedInStaff['role']??''));
                    $allowed=tamasyaCanOperateShift($loggedInStaff)||hasCapability($loggedInStaff,'receive_payment_access',[]);
                    $openShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id']);
                    $shiftStatus=$openShift ? ('TERBUKA · '.strtoupper((string)$openShift['shift_time']).' · '.(string)$openShift['shift_date']) : 'TIDAK ADA SHIFT TERBUKA';
                    $webhookStatus=$webhookMatch?'SINKRON':($remoteUrl===''?'BELUM AKTIF':'SALAH TUJUAN');
                    $errorText=$remoteError!=='' ? "\n⚠️ Telegram: *".substr($remoteError,0,180)."*" : '';
                    $replyText="🩺 *DIAGNOSA SHIFT TELEGRAM*\n\n✅ Koneksi akun staf: *TERHUBUNG*\n👤 Staf: *{$loggedInStaff['name']}*\n🔐 Peran server: *".$telegramRoleLabel((string)$role)."*\n🔑 Izin shift: *".($allowed?'YA':'TIDAK')."*\n🧾 Sesi shift: *{$shiftStatus}*\n🌐 Status webhook: *{$webhookStatus}*\n📬 Pembaruan tertunda: *".($pendingUpdates>=0?$pendingUpdates:'GAGAL DIBACA')."*\n🏷️ Versi aktif: *{$patchLevel}*\n🕒 Zona waktu aplikasi: *{$timezone}*\n🗄️ Zona waktu basis data: *{$dbClock['db_timezone']}*\n📅 Waktu basis data: *{$dbClock['db_now']}*{$errorText}";
                }
                $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
            } elseif ($command === "/buka_shift") {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])) {
                    $replyText = "🔒 Hanya Admin, Manajer, Finance, atau Resepsionis yang dapat membuka shift kas.";
                } elseif ($currentOpenShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id'])) {
                    $replyText = "ℹ️ Anda sudah terikat pada shift terbuka *".getShiftDisplayName($currentOpenShift)."*. Tutup shift tersebut sebelum membuka yang baru.";
                } else {
                    $replyText = "🔑 *BUKA SHIFT & PENYIAPAN KAS AWAL*\n\n" .
                                 "Selamat bertugas, *{$loggedInStaff['name']}*.\n" .
                                 "Silakan pilih jadwal shift Anda hari ini untuk memulai penyiapan kas laci:";
                    $replyMarkup = [
                        "inline_keyboard" => [
                            [
                                ["text" => "🌅 Shift Pagi (07:00 - 15:00)", "callback_data" => "buka_shift_time:pagi"]
                            ],
                            [
                                ["text" => "☀️ Shift Siang (15:00 - 23:00)", "callback_data" => "buka_shift_time:siang"]
                            ],
                            [
                                ["text" => "🌙 Shift Malam (23:00 - 07:00)", "callback_data" => "buka_shift_time:malam"]
                            ],
                            [
                                ["text" => "📅 Satu Hari Penuh (24 Jam)", "callback_data" => "buka_shift_time:all"]
                            ],
                            [
                                ["text" => "⬅️ Menu Utama", "callback_data" => "main_menu"]
                            ]
                        ]
                    ];
                }
            } elseif ($command === "/tutup_shift") {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!
Telegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } elseif (!tamasyaCanOperateShift($loggedInStaff)&&!hasCapability($loggedInStaff,'receive_payment_access',[])) {
                    $replyText = "🔒 Hanya Admin, Manajer, Finance, atau Resepsionis yang dapat menutup shift kas.";
                } else {
                    $currentOpenShift=getOpenShiftForStaff($pdo,(string)$loggedInStaff['id']);
                    if(!$currentOpenShift){
                        $replyText="⚠️ Tidak ada shift terbuka yang terikat pada akun *{$loggedInStaff['name']}*.";
                        $replyMarkup=['inline_keyboard'=>[[['text'=>'⬅️ Menu Utama','callback_data'=>'main_menu']]]];
                    } else {
                        $actualShiftTime=(string)($currentOpenShift['shift_time']??'all');
                        $replyText="📝 *TUTUP SHIFT & REKONSILIASI KAS*

Shift aktif diambil langsung dari database:
👥 Petugas: *".getShiftDisplayName($currentOpenShift)."*
📅 Tanggal: *".($currentOpenShift['shift_date']??date('Y-m-d'))."*
⏰ Jadwal: *".strtoupper($actualShiftTime)."*";
                        $replyMarkup=['inline_keyboard'=>[
                            [["text"=>"💵 Lanjut Rekonsiliasi","callback_data"=>"tutup_shift_time:".$actualShiftTime]],
                            [["text"=>"⬅️ Menu Utama","callback_data"=>"main_menu"]]
                        ]];
                    }
                }
            } elseif (strpos($command, '/hk_set') === 0) {
                $parts = preg_split('/\s+/', $command);
                $roomNumber = trim((string)($parts[1] ?? ''));
                $action = strtolower(trim((string)($parts[2] ?? '')));
                $actionAliases = [
                    'bersihkan'=>'cleaning',
                    'pembersihan'=>'cleaning',
                    'siap'=>'clean_available',
                    'tersedia'=>'clean_available',
                    'perbaikan'=>'need_maintenance',
                    'butuh_perbaikan'=>'need_maintenance'
                ];
                $action = $actionAliases[$action] ?? $action;
                $statusMap = ['cleaning'=>'maintenance','clean_available'=>'available','need_maintenance'=>'maintenance'];
                $damageDetail=trim(implode(' ',array_slice($parts,3)));
                if (!$loggedInStaff || !hasCapability($loggedInStaff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']) || !isset($statusMap[$action]) || $roomNumber === '') {
                    $replyText = "⚠️ Format salah atau akses ditolak. Perintah Housekeeping hanya untuk Admin, Manajer, Resepsionis, Housekeeping, atau Keuangan. Gunakan: `/hk_set <nomor_kamar> <bersihkan | siap | perbaikan>`.";
                } elseif($action==='need_maintenance'&&$damageDetail==='') {
                    $ctx=json_encode(['roomNumber'=>$roomNumber],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_housekeeping_damage_detail',telegram_context=? WHERE id=?")->execute([$ctx,$loggedInStaff['id']]);
                    $replyText="🛠 *DETAIL KERUSAKAN WAJIB*\n\nKetik apa yang rusak di Kamar *{$roomNumber}*. Contoh: `Lampu kamar mandi mati walau sakelar sudah dinyalakan`.\n\nAtau gunakan `/hk_set {$roomNumber} perbaikan <detail>` dalam satu pesan.";
                } else {
                    try {
                        $operationId = telegramScopedOperationId($telegramUpdateOperationId,'housekeeping',[$roomNumber,$action,$damageDetail]);
                        $pdo->beginTransaction();
                        claimTelegramMutation($pdo,$operationId,$loggedInStaff['id'],'rooms',$roomNumber,['action'=>$action,'room'=>$roomNumber],'message');
                        $hkResult=applyHousekeepingAction($pdo,$loggedInStaff,$roomNumber,$action,'telegram_housekeeping_command','',$damageDetail);
                        completeTelegramMutation($pdo,$operationId,['room'=>$roomNumber,'status'=>$hkResult['roomStatus'],'taskId'=>$hkResult['taskId']]);
                        tamasyaFinancialCommit($pdo);
                        broadcastTelegramNotification($pdo,"🧹 *PEMBARUAN HOUSEKEEPING*

🚪 Kamar: *{$roomNumber}*
👤 Petugas: *{$loggedInStaff['name']}*
📌 Status: *".$telegramRoomStatusLabel((string)$hkResult['roomStatus'])."*.",false);
                        $replyText = "✅ Tindakan Housekeeping kamar *{$roomNumber}* tersimpan. Status: *".$telegramRoomStatusLabel((string)$hkResult['roomStatus'])."*.";
                    } catch (TelegramDuplicateOperationException $duplicate) {
                        if($pdo->inTransaction())$pdo->rollBack();
                        $replyText = "ℹ️ Perintah Housekeeping ini sudah diproses; database tidak digandakan.";
                    } catch (Throwable $housekeepingError) {
                        if($pdo->inTransaction())$pdo->rollBack();
                        if (isset($operationId)) failTelegramMutation($pdo,$operationId,$housekeepingError);
                        $replyText = "⚠️ *STATUS KAMAR TIDAK DIUBAH*

" . clientExceptionMessage('Sinkronisasi booking dan kamar menolak perubahan', $housekeepingError);
                    }
                }
            } elseif (strpos($command, '/patrol') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } else {
                    $parts = preg_split('/\s+/', $command);
                    if (count($parts) < 2) {
                        $replyText = "⚠️ Format salah! Gunakan: `/patrol <start | finish_safe>`";
                    } else {
                        $action = trim($parts[1]);
                        $staffName = $loggedInStaff['name'];
                        $now = date("Y-m-d H:i:s");
                        
                        if ($action === "start") {
                            $notifMsg = "🚨 [PATROLI] Petugas {$staffName} memulai patroli keamanan keliling area hotel.";
                            $stmtNotif = $pdo->prepare("INSERT INTO notifications (id, type, message, timestamp, `read`) VALUES (?, 'system', ?, ?, 0)");
                            $stmtNotif->execute([generateServerId('pt_notif'), $notifMsg, $now]);
                            
                            $replyText = "🏃‍♂️ *PATROLI DIMULAI*!\n\nPetugas *{$staffName}* sedang memantau keamanan area {$propertyName}.";
                        } elseif ($action === "finish_safe") {
                            $notifMsg = "🚨 [PATROLI] Petugas {$staffName} menyelesaikan patroli. Kondisi area hotel AMAN terkendali.";
                            $stmtNotif = $pdo->prepare("INSERT INTO notifications (id, type, message, timestamp, `read`) VALUES (?, 'system', ?, ?, 0)");
                            $stmtNotif->execute([generateServerId('pt_notif'), $notifMsg, $now]);
                            
                            $replyText = "🛡️ *PATROLI SELESAI*!\n\nPetugas *{$staffName}* menyelesaikan tugas patroli. Kondisi hotel dinyatakan aman, damai, dan kondusif.";
                        } else {
                            $replyText = "⚠️ Tindakan tidak valid! Gunakan: `start` atau `finish_safe`.";
                        }
                    }
                }
            } elseif (strpos($command, '/lapor_insiden') === 0) {
                if (!$loggedInStaff) {
                    $replyText = "🔒 *AKSES DITOLAK*!\nTelegram ID belum terhubung. Hubungi Admin untuk binding akun staf.";
                } else {
                    $stmtSetState = $pdo->prepare("UPDATE staff SET telegram_state = 'waiting_for_incident_text', telegram_context = 'none' WHERE id = ?");
                    $stmtSetState->execute([$loggedInStaff['id']]);
                    
                    $replyText = "📢 *LAPORAN INSIDEN / KERUSAKAN LAPANGAN*\n\n" .
                                 "Silakan **ketik laporan kejadian atau kerusakan** Anda langsung di chat ini (tanpa perintah / command):\n\n" .
                                 "*Contoh*: 'Ditemukan lampu koridor lantai 2 pecah'.";
                }
            } else {
                // Obrolan AI asisten aseli asisten {$propertyName} aseli menggunakan Gemini API
                if (!empty($geminiKey)) {
                    $isAi = 1;
                    
                    // Grounding AI dibatasi berdasarkan role SEBELUM data dikirim ke
                    // penyedia eksternal. Instruksi prompt bukan pengganti kontrol akses.
                    $staffName = $loggedInStaff ? (string)$loggedInStaff['name'] : "Guest";
                    $staffRole = strtolower((string)($loggedInStaff['role'] ?? 'guest'));
                    $stmt_tot = $pdo->query("SELECT COUNT(*) FROM rooms");
                    $totalRooms = (int)$stmt_tot->fetchColumn();
                    $stmt_bk = $pdo->query("SELECT COUNT(DISTINCT roomNumber) FROM bookings WHERE status='active'");
                    $activeRooms = (int)$stmt_bk->fetchColumn();
                    $operationallyAvailableRooms=getOperationallySellableRooms($pdo);
                    $availableRooms = count($operationallyAvailableRooms);
                    $occRate = $totalRooms > 0 ? round(($activeRooms / $totalRooms) * 100) : 0;
                    $hotelContextLines = [
                        "- Total kamar: {$totalRooms}",
                        "- Kamar tersedia: {$availableRooms}",
                        "- Kamar terisi: {$activeRooms}",
                        "- Okupansi: {$occRate}%"
                    ];
                    if (in_array($staffRole,['admin','manager','receptionist'],true)) {
                        $stmt_bkn = $pdo->query("SELECT DISTINCT roomNumber FROM bookings WHERE status='active' ORDER BY CAST(roomNumber AS UNSIGNED),roomNumber");
                        $hotelContextLines[] = "- Nomor kamar terisi: " . (implode(', ',$stmt_bkn->fetchAll(PDO::FETCH_COLUMN)) ?: '-');
                        $hotelContextLines[] = "- Nomor kamar tersedia: " . (implode(', ',array_map(static fn(array $room): string => (string)($room['number']??''),$operationallyAvailableRooms)) ?: '-');
                    }
                    if (in_array($staffRole,['admin','manager','finance'],true)) {
                        $saldoAwal = isset($confRow['initial_balance']) ? (float)$confRow['initial_balance'] : 0;
                        $cashSummary = tamasyaCanonicalCashSummary($pdo,$saldoAwal);
                        $income = (float)$cashSummary['cashOnlyIncome'];
                        $expense = (float)$cashSummary['cashOnlyExpense'];
                        $hotelContextLines[] = "- Saldo awal: Rp " . tamasyaTelegramFormatAmount($saldoAwal);
                        $hotelContextLines[] = "- Pemasukan kas operasional: Rp " . tamasyaTelegramFormatAmount($income);
                        $hotelContextLines[] = "- Pengeluaran kas operasional: Rp " . tamasyaTelegramFormatAmount($expense);
                        $hotelContextLines[] = "- Saldo likuid: Rp " . tamasyaTelegramFormatAmount((float)$cashSummary['liquidBalance']);
                    }
                    if ($staffRole === 'guest') {
                        // Guest hanya memperoleh konteks agregat publik; tidak ada nomor
                        // kamar, data tamu, transaksi, saldo, atau identitas staf lain.
                        $hotelContextLines = [
                            "- Total kamar: {$totalRooms}",
                            "- Kamar tersedia: {$availableRooms}",
                            "- Kamar terisi: {$activeRooms}"
                        ];
                    }
                    $systemPrompt = "Anda adalah {$propertyName} Bot, asisten hotel berbahasa Indonesia yang ramah dan profesional.
" .
                                    "Identitas pengguna terverifikasi: {$staffName}; jabatan: {$staffRole}.
" .
                                    "Gunakan hanya data yang disediakan berikut dan jangan menebak data lain:
" .
                                    implode("
",$hotelContextLines) . "

" .
                                    "Jangan mengungkap data tamu, nomor kamar operasional, transaksi, saldo, gaji, atau data staf jika data tersebut tidak tersedia dalam konteks peran ini. " .
                                    "Jangan meminta username, password, OTP, bot token, atau kode binding.";

                    // Call Gemini API natively using safe helper with a standard stable model
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
                    $payload = [
                        "contents" => [
                            [
                                "role" => "user",
                                "parts" => [
                                    ["text" => $systemPrompt . "\n\nPesan User: " . $text]
                                ]
                            ]
                        ],
                        "generationConfig" => [
                            "temperature" => 0.7
                        ]
                    ];

                    $geminiApiKeyHeader = preg_replace('/[\r\n]+/', '', $geminiKey);
                    $geminiResRaw = sendHttpPost($url, $payload, ['x-goog-api-key: '.$geminiApiKeyHeader]);
                    $geminiRes = json_decode($geminiResRaw, true);
                    if ($geminiRes) {
                        if (isset($geminiRes['candidates'][0]['content']['parts'][0]['text'])) {
                            $replyText = trim($geminiRes['candidates'][0]['content']['parts'][0]['text']);
                        } elseif (isset($geminiRes['error']['message'])) {
                            $replyText = "⚠️ *Error Gemini API*:\n" . $geminiRes['error']['message'] . "\n\nSilakan periksa kembali kecocokan API Key Anda di Pengaturan.";
                        }
                    }
                }

                if (empty($replyText)) {
                    $replyText = "Halo! Saya adalah {$propertyName} Bot. Saat ini asisten AI berjalan dalam mode dasar offline. Anda dapat menggunakan command instan berikut:\n- `/status_kamar` untuk status kamar\n- binding Telegram ID oleh Admin untuk otentikasi staf\n- Hubungkan GEMINI_API_KEY di pengaturan Secrets untuk obrolan AI interaktif!";
                }
            }

            // 4. Determine which keyboard markup to send back based on current login status
            $loggedInStaffForMarkup = $loggedInStaff ?: findActiveStaffByTelegramUserId($pdo, $fromId);
            if (empty($replyMarkup)) {
                $replyMarkup = $keyboardForTelegramRole($loggedInStaffForMarkup ?: null);
            }
            $replyMarkup = tamasyaTelegramCompactReplyMarkup($pdo, $loggedInStaffForMarkup ?: null, $replyMarkup);

            // Telegram selalu beroperasi terhadap database server. Naikkan revisi agar
            // dashboard/perangkat lain segera menarik state server terbaru.
            bumpServerRevision($pdo);

            // 5. Log reply message
            $botMsgId = generateServerId('tg_real_reply');
            $stmtBotLog = $pdo->prepare("INSERT INTO telegram_messages (id, sender, `text`, timestamp, isAi, telegram_user_id, staff_id, command) VALUES (?, 'bot', ?, ?, ?, ?, ?, ?)");
            $stmtBotLog->execute([$botMsgId, $replyText, date("Y-m-d H:i:s"), $isAi, (string)$fromId, $loggedInStaff['id'] ?? null, substr($command,0,100)]);

            if (!empty($GLOBALS['is_telegram_simulation'])) {
                echo json_encode([
                    "success" => true,
                    "message" => [
                        "id" => $botMsgId,
                        "sender" => "bot",
                        "text" => $replyText,
                        "timestamp" => date("c"),
                        "isAi" => (bool)$isAi,
                        "replyMarkup" => $replyMarkup
                    ],
                    "simulationIdentity" => [
                        "telegramUserId" => (string)$fromId,
                        "bound" => (bool)$loggedInStaff,
                        "staffId" => $loggedInStaff['id'] ?? null,
                        "staffName" => $loggedInStaff['name'] ?? null,
                        "role" => $loggedInStaff['role'] ?? null
                    ],
                    "db" => tamasyaTelegramSimulationHotelData($pdo, $loggedInStaff)
                ]);
                break;
            }

            // 6. Respons ke Telegram wajib terkonfirmasi. Setelah mutasi database
            // ter-commit, kegagalan transport sendMessage tidak boleh memicu retry
            // Telegram yang dapat menduplikasi transaksi. Bungkus dalam try/catch:
            // catat peringatan, balas HTTP 200, dan jangan lempar 500.
            $telegramResponseCommitted = true;
            try {
                telegramApiCallRequired($token,'sendMessage', [
                    "chat_id" => $chatId,
                    "text" => $replyText,
                    "parse_mode" => "Markdown",
                    "reply_markup" => $replyMarkup
                ]);
            } catch (Throwable $telegramSendError) {
                error_log('[Telegram Webhook] sendMessage transport failed after commit: ' . $telegramSendError->getMessage());
                http_response_code(200);
            }

            echo json_encode(["success" => true]);
        } catch (Throwable $e) {
            // Telegram menganggap HTTP 2xx sebagai update selesai. Gunakan 500
            // agar kegagalan database/PHP dapat dikirim ulang dan tidak tercatat
            // sebagai sukses palsu oleh jurnal update.
            // Propagate safe stage/file/line/class metadata to the normal request
            // shutdown telemetry. Previously handled webhook exceptions were
            // counted as HTTP 500 with every diagnostic field NULL.
            tamasyaRuntimeCaptureThrowable($e, 'telegram:webhook_processing');
            http_response_code(500);
            error_log('[Telegram Webhook] ' . $e->getMessage());
            if (!empty($GLOBALS['is_telegram_simulation'])) {
                $errMsg = 'Operasi Telegram gagal: ' . $e->getMessage()
                    . ' in ' . basename((string)$e->getFile()) . ':' . $e->getLine();
                echo json_encode(["success" => false, "error" => $errMsg]);
            } else {
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Operasi Telegram gagal", $e)]);
            }
        }
        } catch (Throwable $e) {
            tamasyaRuntimeCaptureThrowable($e, 'telegram:webhook_processing');
            http_response_code(500);
            error_log('[Telegram Webhook] ' . $e->getMessage());
            if (!empty($GLOBALS['is_telegram_simulation'])) {
                $errMsg = 'Operasi Telegram gagal: ' . $e->getMessage()
                    . ' in ' . basename((string)$e->getFile()) . ':' . $e->getLine();
                echo json_encode(["success" => false, "error" => $errMsg]);
            } else {
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Operasi Telegram gagal", $e)]);
            }
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/chat-messages ATAU api.php?action=chat-messages
    // Kirim Pesan Layanan Bantuan (Help Desk Support)
    // ----------------------------------------------------------------
    // ----------------------------------------------------------------
    // GET/POST /api/operations-center
    // Pusat tata kelola: audit, shift, approval, session, sync, pajak,
    // rekonsiliasi, housekeeping, maintenance, tamu, monitoring, dan backup.
    // ----------------------------------------------------------------
}
