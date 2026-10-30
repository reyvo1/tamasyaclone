<?php
/** Auto-extracted from RC4.10.15 api.php. Keep this file internal. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 6004: runRc3CompatibilityMigration */
function runRc3CompatibilityMigration($pdo): void {
    if (!$pdo || schemaMigrationApplied($pdo, '2026.07.rc3.performance-telegram.1')) return;
    try {
        // Salin nama kolom Telegram versi lama bila database existing pernah
        // menggunakan telegram_id, telegram_user_id, atau chat_id.
        foreach (['telegram_id', 'telegram_user_id', 'chat_id'] as $legacyColumn) {
            $stmtLegacyColumn = $pdo->query("SHOW COLUMNS FROM staff LIKE '" . str_replace("'", "''", $legacyColumn) . "'");
            if ($stmtLegacyColumn && $stmtLegacyColumn->rowCount() > 0) {
                $pdo->exec("UPDATE staff SET telegram_chat_id=CAST(`{$legacyColumn}` AS CHAR)
                    WHERE (telegram_chat_id IS NULL OR TRIM(telegram_chat_id)='')
                      AND `{$legacyColumn}` IS NOT NULL
                      AND TRIM(CAST(`{$legacyColumn}` AS CHAR))<>''");
            }
        }

        // Sinkronkan binding yang sudah ada ke kolom staff lama agar seluruh jalur
        // Telegram, 2FA, dan tampilan aplikasi membaca identitas yang sama.
        $pdo->exec("UPDATE staff s
            SET s.telegram_chat_id = (
                SELECT tb.telegram_user_id
                FROM telegram_bindings tb
                WHERE tb.staff_id=s.id AND tb.status='active'
                ORDER BY tb.verified_at DESC
                LIMIT 1
            )
            WHERE (s.telegram_chat_id IS NULL OR TRIM(s.telegram_chat_id)='')
              AND EXISTS (
                SELECT 1 FROM telegram_bindings tb2
                WHERE tb2.staff_id=s.id AND tb2.status='active'
              )");
        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
            VALUES ('2026.07.rc3.performance-telegram.1','One-time schema guards, Telegram compatibility backfill, and shared-hosting performance fix',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC3 compatibility migration failed', $e));
    }
}

/** Source line 6048: runRc4FinalAuditMigration */
function runRc4FinalAuditMigration($pdo): void {
    if (!$pdo || schemaMigrationApplied($pdo, '2026.07.rc4.final-audit.1')) return;
    try {
        ensureColumnExists($pdo, 'bookings', 'isOpenEnded', 'TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo, 'user_sessions', 'refresh_token_hash', 'VARCHAR(64) NULL');
        ensureColumnExists($pdo, 'user_sessions', 'refresh_expires', 'DATETIME NULL');

        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_update_log (
            update_id VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'processing',
            attempts INT NOT NULL DEFAULT 1,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_tg_update_status(status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $safeIndexes = [
            "CREATE INDEX idx_bookings_room_status ON bookings (roomNumber,status)",
            "CREATE INDEX idx_transactions_kind ON transactions (transactionKind)",
            "CREATE INDEX idx_telegram_binding_staff ON telegram_bindings (staff_id)",
            "CREATE INDEX idx_activity_staff ON activity_logs (staff_id)",
            "CREATE INDEX idx_salary_staff_period ON salary_slips (staff_id,period)",
            "CREATE INDEX idx_maintenance_inventory ON inventory_maintenance (inventory_id)",
            "CREATE INDEX idx_shift_staff_status ON shift_sessions (staff_id,status)",
            "CREATE INDEX idx_shift_date_time ON shift_sessions (shift_date,shift_time)",
            "CREATE INDEX idx_shift_opened ON shift_sessions (opened_at)",
            "CREATE INDEX idx_sync_staff ON sync_operations (staff_id)",
            "CREATE INDEX idx_sync_entity ON sync_operations (entity_type,entity_id)"
        ];
        foreach ($safeIndexes as $indexSql) {
            try { $pdo->exec($indexSql); } catch (Throwable $ignored) {}
        }

        // Migrasikan secret plaintext dari database lama hanya bila encryption key
        // sudah tersedia. Password DB selalu dikosongkan dari tabel config karena
        // koneksi runtime sudah berasal dari file/env credentials.
        $stmtConfig = $pdo->query("SELECT gemini_api_key, telegram_bot_token, smtp_password FROM config WHERE id='system_default' LIMIT 1");
        $secretRow = $stmtConfig ? ($stmtConfig->fetch() ?: []) : [];
        $updates = ['db_password' => ''];
        if (appSecretEncryptionKey() !== null) {
            foreach ([
                'gemini_api_key' => 'gemini_api_key',
                'telegram_bot_token' => 'telegram_bot_token',
                'smtp_password' => 'smtp_password'
            ] as $column => $key) {
                $value = trim((string)($secretRow[$key] ?? ''));
                if ($value !== '' && !str_starts_with($value, 'enc:v1:')) {
                    $updates[$column] = encryptStoredSecret($value);
                }
            }
        }
        if ($updates) {
            $setParts = [];
            $values = [];
            foreach ($updates as $column => $value) {
                $setParts[] = "`{$column}`=?";
                $values[] = $value;
            }
            $values[] = 'system_default';
            $pdo->prepare("UPDATE config SET " . implode(',', $setParts) . " WHERE id=?")->execute($values);
        }

        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
            VALUES ('2026.07.rc4.final-audit.1','RC4 live database alignment, secret migration, indexes, and Telegram update idempotency',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4 final audit migration failed', $e));
    }
}

/** Source line 6123: runRc4SecretMigration */
function runRc4SecretMigration($pdo): void {
    if (!$pdo || schemaMigrationApplied($pdo, '2026.07.rc4.secrets-encrypted.1')) return;
    if (appSecretEncryptionKey() === null) return;
    try {
        $stmt = $pdo->query("SELECT gemini_api_key,telegram_bot_token,smtp_password FROM config WHERE id='system_default' LIMIT 1");
        $row = $stmt ? ($stmt->fetch() ?: []) : [];
        $gemini = trim((string)($row['gemini_api_key'] ?? ''));
        $telegram = trim((string)($row['telegram_bot_token'] ?? ''));
        $smtp = trim((string)($row['smtp_password'] ?? ''));
        $pdo->prepare("UPDATE config SET gemini_api_key=?,telegram_bot_token=?,smtp_password=?,db_password='' WHERE id='system_default'")->execute([
            $gemini === '' ? '' : encryptStoredSecret($gemini),
            $telegram === '' ? '' : encryptStoredSecret($telegram),
            $smtp === '' ? '' : encryptStoredSecret($smtp)
        ]);
        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
            VALUES ('2026.07.rc4.secrets-encrypted.1','Legacy config secrets encrypted with AES-256-GCM and DB password removed from config',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4 secret migration failed', $e));
    }
}

/** Source line 6152: runRc43RoomSecurityMigration */
function runRc43RoomSecurityMigration($pdo): void {
    if (!$pdo || schemaMigrationApplied($pdo, '2026.07.rc4.3-room-security.1')) return;
    try {
        $bookingColumns = [
            'actualCheckInAt' => 'DATETIME NULL',
            'actualCheckOutAt' => 'DATETIME NULL',
            'checkoutDueAt' => 'DATETIME NULL',
            'lateCheckoutStatus' => "VARCHAR(30) NOT NULL DEFAULT 'none'",
            'lateCheckoutReason' => 'TEXT NULL',
            'lateCheckoutFee' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'lateCheckoutApprovedBy' => 'VARCHAR(50) NULL',
            'keyControlStatus' => "VARCHAR(30) NOT NULL DEFAULT 'not_issued'",
            'accessMode' => "VARCHAR(20) NOT NULL DEFAULT 'physical'",
            'keyIssuedAt' => 'DATETIME NULL',
            'keyIssuedBy' => 'VARCHAR(50) NULL',
            'keyReturnedAt' => 'DATETIME NULL',
            'keyReturnedBy' => 'VARCHAR(50) NULL',
            'smartLockCodeHash' => 'VARCHAR(64) NULL',
            'smartLockCodeLast4' => 'VARCHAR(4) NULL',
            'smartLockValidFrom' => 'DATETIME NULL',
            'smartLockValidUntil' => 'DATETIME NULL'
        ];
        foreach ($bookingColumns as $column => $definition) ensureColumnExists($pdo, 'bookings', $column, $definition);
        foreach ([
            'night_audit_id' => 'VARCHAR(80) NULL',
            'key_discrepancy_count' => 'INT NOT NULL DEFAULT 0',
            'occupancy_discrepancy_count' => 'INT NOT NULL DEFAULT 0',
            'close_override_reason' => 'TEXT NULL'
        ] as $column => $definition) ensureColumnExists($pdo, 'shift_sessions', $column, $definition);

        $pdo->exec("CREATE TABLE IF NOT EXISTS hotel_operational_settings (
            id VARCHAR(40) PRIMARY KEY,
            checkout_time TIME NOT NULL DEFAULT '12:00:00',
            checkout_reminder_minutes INT NOT NULL DEFAULT 30,
            late_grace_minutes INT NOT NULL DEFAULT 60,
            allow_early_checkin TINYINT(1) NOT NULL DEFAULT 1,
            require_open_shift_for_sale TINYINT(1) NOT NULL DEFAULT 1,
            require_key_control TINYINT(1) NOT NULL DEFAULT 1,
            require_night_audit_for_night_shift TINYINT(1) NOT NULL DEFAULT 1,
            cash_variance_tolerance DECIMAL(15,2) NOT NULL DEFAULT 0,
            smart_lock_bridge_url VARCHAR(500) NULL,
            smart_lock_bridge_token TEXT NULL,
            smart_lock_bridge_timeout INT NOT NULL DEFAULT 8,
            updated_by VARCHAR(50) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO hotel_operational_settings (id) VALUES ('system_default') ON DUPLICATE KEY UPDATE id=id");

        $pdo->exec("CREATE TABLE IF NOT EXISTS room_access_control (
            room_number VARCHAR(20) PRIMARY KEY,
            access_mode VARCHAR(20) NOT NULL DEFAULT 'physical',
            physical_key_ref VARCHAR(100) NULL,
            physical_key_status VARCHAR(30) NOT NULL DEFAULT 'secured',
            current_booking_id VARCHAR(80) NULL,
            smart_lock_provider VARCHAR(100) NULL,
            smart_lock_device_id VARCHAR(190) NULL,
            smart_lock_enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_event_at DATETIME NULL,
            updated_by VARCHAR(50) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_room_access_booking(current_booking_id),
            INDEX idx_room_access_status(physical_key_status,access_mode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT IGNORE INTO room_access_control (room_number,physical_key_ref) SELECT number,CONCAT('KEY-',number) FROM rooms");

        $pdo->exec("CREATE TABLE IF NOT EXISTS room_key_events (
            id VARCHAR(80) PRIMARY KEY,
            room_number VARCHAR(20) NOT NULL,
            booking_id VARCHAR(80) NULL,
            event_type VARCHAR(40) NOT NULL,
            access_mode VARCHAR(20) NOT NULL DEFAULT 'physical',
            physical_key_ref VARCHAR(100) NULL,
            smart_code_last4 VARCHAR(4) NULL,
            credential_status VARCHAR(30) NULL,
            staff_id VARCHAR(50) NULL,
            staff_name VARCHAR(150) NULL,
            reason TEXT NULL,
            device_id VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_key_event_room(room_number,created_at),
            INDEX idx_key_event_booking(booking_id,created_at),
            INDEX idx_key_event_staff(staff_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS smart_lock_jobs (
            id VARCHAR(80) PRIMARY KEY,
            room_number VARCHAR(20) NOT NULL,
            booking_id VARCHAR(80) NULL,
            action VARCHAR(30) NOT NULL,
            provider VARCHAR(100) NULL,
            device_id VARCHAR(190) NULL,
            payload LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_smart_job_status(status,created_at),
            INDEX idx_smart_job_booking(booking_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS night_audit_runs (
            id VARCHAR(80) PRIMARY KEY,
            audit_date DATE NOT NULL,
            shift_session_id VARCHAR(100) NULL,
            staff_id VARCHAR(50) NOT NULL,
            staff_name VARCHAR(150) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            total_rooms INT NOT NULL DEFAULT 0,
            checked_rooms INT NOT NULL DEFAULT 0,
            occupancy_discrepancies INT NOT NULL DEFAULT 0,
            key_discrepancies INT NOT NULL DEFAULT 0,
            cash_discrepancies INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_night_audit_date(audit_date,status),
            INDEX idx_night_audit_shift(shift_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS night_audit_items (
            id VARCHAR(90) PRIMARY KEY,
            audit_id VARCHAR(80) NOT NULL,
            room_number VARCHAR(20) NOT NULL,
            system_room_status VARCHAR(30) NULL,
            active_booking_id VARCHAR(80) NULL,
            guest_name VARCHAR(150) NULL,
            physical_occupancy VARCHAR(20) NOT NULL DEFAULT 'unchecked',
            observed_key_status VARCHAR(30) NOT NULL DEFAULT 'unchecked',
            discrepancy_type VARCHAR(100) NULL,
            resolution_status VARCHAR(30) NOT NULL DEFAULT 'open',
            notes TEXT NULL,
            checked_by VARCHAR(50) NULL,
            checked_at DATETIME NULL,
            resolved_by VARCHAR(50) NULL,
            resolved_at DATETIME NULL,
            INDEX idx_night_item_audit(audit_id,room_number),
            INDEX idx_night_item_discrepancy(discrepancy_type,resolution_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Backfill active bookings. Existing flow creates a booking at real check-in.
        $pdo->exec("UPDATE bookings SET actualCheckInAt=COALESCE(actualCheckInAt,createdAt), checkoutDueAt=CASE WHEN COALESCE(isOpenEnded,0)=1 THEN NULL ELSE COALESCE(checkoutDueAt,CONCAT(checkOut,' 12:00:00')) END WHERE status='active' AND checkIn<=CURRENT_DATE");
        $pdo->exec("UPDATE bookings b LEFT JOIN room_access_control rac ON rac.room_number=b.roomNumber SET b.accessMode=COALESCE(NULLIF(rac.access_mode,''),'physical') WHERE b.status='active'");

        foreach ([
            "CREATE INDEX idx_bookings_due_status ON bookings (status,checkoutDueAt)",
            "CREATE INDEX idx_bookings_key_status ON bookings (status,keyControlStatus)",
            "CREATE INDEX idx_bookings_actual_checkout ON bookings (actualCheckOutAt)"
        ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $ignored) {} }

        // SOP operasional tersedia otomatis di Pusat Operasional. Dokumen ini
        // tidak mengganti alur booking lama; ia menjelaskan kontrol wajib yang
        // membuktikan kesesuaian kamar fisik, booking, kunci, dan kas shift.
        $roomSecuritySop = <<<'SOP'
PRINSIP WAJIB
1. Tidak ada booking aktif dan pembayaran/panjar tercatat: kunci fisik atau PIN tidak boleh diserahkan.
2. Kamar yang belum checkout atau belum dinyatakan ready oleh Housekeeping tidak boleh dijual.
3. Setiap petugas memakai akun dan shift sendiri; dilarang memakai akun bersama.
4. Perubahan harga, late checkout, override kunci, koreksi transaksi, dan selisih kas wajib mempunyai alasan serta jejak audit.

CHECK-OUT DAN EARLY CHECK-IN
- Pengingat checkout berjalan sebelum pukul 12.00 sesuai konfigurasi.
- Setelah batas checkout, kamar tetap terisi sampai tamu benar-benar keluar.
- Early check-in hanya boleh ketika tidak ada booking aktif, housekeeping selesai, kamar ready, dan tidak ada benturan booking.
- Check-out kunci fisik/hybrid wajib konfirmasi kunci kembali. Override hanya untuk petugas berizin dan wajib alasan.
- Check-out smart lock wajib online agar PIN dapat dicabut.

KUNCI FISIK
- Beri label unik, contoh KEY-101, dan daftarkan pada Kontrol Kunci.
- Penyerahan dan pengembalian wajib dicatat dari booking aktif.
- Pergantian shift mencocokkan kunci di lemari, kunci pada tamu, booking aktif, dan status kamar.
- Kunci keluar tanpa booking atau kamar fisik terisi tanpa booking menjadi temuan kritis Night Audit.

SMART LOCK
- Daftarkan provider dan device ID per kamar.
- PIN dibuat satu kali setelah booking dan pembayaran/panjar valid; sistem hanya menyimpan hash serta empat digit terakhir.
- Jika bridge belum terhubung, PIN dapat dimasukkan manual ke perangkat; status job tetap harus diperiksa.
- Saat checkout, pembatalan, atau koreksi akses, PIN wajib dicabut.

NIGHT AUDIT DAN TUTUP SHIFT
- Semua kamar diperiksa secara fisik: terisi/kosong serta status kunci.
- Temuan harus dijelaskan dan diselesaikan oleh petugas berizin.
- Shift malam tidak dapat ditutup sebelum audit selesai dan semua temuan diselesaikan.
- Kas aktual dibandingkan dengan saldo sistem. Selisih di atas toleransi memerlukan Manager dan alasan.

KONTROL MANAJEMEN
- Manager memeriksa booking malam, pembayaran tunai, kunci/PIN yang diterbitkan, late checkout, perubahan harga, koreksi audit, selisih kas, dan temuan kamar.
- Untuk kunci logam, aplikasi membuat penyalahgunaan terdeteksi tetapi tidak dapat menghentikan tangan petugas menyerahkan kunci. Gunakan lemari kunci terkunci, serah-terima fisik, CCTV, atau petugas keamanan independen untuk kontrol paling kuat.
SOP;
        $pdo->prepare("INSERT INTO app_documents (id,title,category,content,is_active,created_by,created_at,updated_at)
            VALUES ('doc_room_security_night_audit','SOP Kontrol Kunci, Smart Lock, Check-out dan Night Audit','Keamanan Kamar',?,1,'system',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE title=VALUES(title),category=VALUES(category),content=VALUES(content),is_active=1,updated_at=CURRENT_TIMESTAMP")
            ->execute([$roomSecuritySop]);

        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
            VALUES ('2026.07.rc4.3-room-security.1','Physical key, smart lock bridge, checkout control, night audit, and anti-fraud room controls',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.3 room security migration failed', $e));
    }
}

/** Source line 6363: runRc44TaxAuditMigration */
function runRc44TaxAuditMigration($pdo): void {
    $version = '2026.07.rc4.4-tax-audit.1';
    if (!$pdo || schemaMigrationApplied($pdo, $version)) return;
    // This legacy in-process migrator is retained only as historical compatibility
    // code. Never let it partially mutate a database when its old reconciliation
    // helpers are absent. Production upgrades must use the versioned SQL migration path.
    if (!function_exists('reconcileRoomTaxMetadata') || !function_exists('reconcileBookingTaxMetadataBatch')) {
        throw new RuntimeException('Legacy RC4.4 tax migrator is disabled/fail-closed. Apply the official versioned database migration on a backed-up staging copy.');
    }
    $lockAcquired = false;
    try {
        $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tamasya_rc4_4_tax_migration',0)")->fetchColumn() === 1;
        if (!$lockAcquired) return;

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migration_progress (
            version VARCHAR(100) PRIMARY KEY,
            transaction_cursor VARCHAR(100) NOT NULL DEFAULT '',
            booking_cursor VARCHAR(100) NOT NULL DEFAULT '',
            transactions_complete TINYINT(1) NOT NULL DEFAULT 0,
            bookings_complete TINYINT(1) NOT NULL DEFAULT 0,
            transactions_updated INT NOT NULL DEFAULT 0,
            transactions_processed INT NOT NULL DEFAULT 0,
            bookings_updated INT NOT NULL DEFAULT 0,
            bookings_processed INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->beginTransaction();
        $pdo->prepare("INSERT IGNORE INTO schema_migration_progress (version) VALUES (?)")->execute([$version]);
        $progressStmt = $pdo->prepare("SELECT * FROM schema_migration_progress WHERE version=? FOR UPDATE");
        $progressStmt->execute([$version]);
        $progress = $progressStmt->fetch() ?: [];

        $txCursor = (string)($progress['transaction_cursor'] ?? '');
        $bookingCursor = (string)($progress['booking_cursor'] ?? '');
        $txComplete = !empty($progress['transactions_complete']);
        $bookingComplete = !empty($progress['bookings_complete']);
        $batchSize = 300;

        $txBatch = $txComplete
            ? ['transactionsUpdated'=>0,'transactionsProcessed'=>0,'lastTransactionId'=>$txCursor,'transactionsComplete'=>true]
            : reconcileRoomTaxMetadata($pdo, null, $batchSize, false, $txCursor);
        $bookingBatch = $bookingComplete
            ? ['bookingsUpdated'=>0,'bookingsProcessed'=>0,'lastBookingId'=>$bookingCursor,'bookingsComplete'=>true]
            : reconcileBookingTaxMetadataBatch($pdo, $bookingCursor, $batchSize, null);

        $newTxCursor = (string)($txBatch['lastTransactionId'] ?? $txCursor);
        $newBookingCursor = (string)($bookingBatch['lastBookingId'] ?? $bookingCursor);
        $newTxComplete = !empty($txBatch['transactionsComplete']);
        $newBookingComplete = !empty($bookingBatch['bookingsComplete']);
        $transactionsUpdated = (int)($progress['transactions_updated'] ?? 0) + (int)($txBatch['transactionsUpdated'] ?? 0);
        $transactionsProcessed = (int)($progress['transactions_processed'] ?? 0) + (int)($txBatch['transactionsProcessed'] ?? 0);
        $bookingsUpdated = (int)($progress['bookings_updated'] ?? 0) + (int)($bookingBatch['bookingsUpdated'] ?? 0);
        $bookingsProcessed = (int)($progress['bookings_processed'] ?? 0) + (int)($bookingBatch['bookingsProcessed'] ?? 0);

        $pdo->prepare("UPDATE schema_migration_progress SET
                transaction_cursor=?,booking_cursor=?,transactions_complete=?,bookings_complete=?,
                transactions_updated=?,transactions_processed=?,bookings_updated=?,bookings_processed=?,updated_at=CURRENT_TIMESTAMP
            WHERE version=?")
            ->execute([
                $newTxCursor,$newBookingCursor,$newTxComplete?1:0,$newBookingComplete?1:0,
                $transactionsUpdated,$transactionsProcessed,$bookingsUpdated,$bookingsProcessed,$version
            ]);

        if ($newTxComplete && $newBookingComplete) {
            $result = [
                'transactionsUpdated'=>$transactionsUpdated,
                'transactionsProcessed'=>$transactionsProcessed,
                'bookingsUpdated'=>$bookingsUpdated,
                'bookingsProcessed'=>$bookingsProcessed,
                'batchSize'=>$batchSize
            ];
            writeEnterpriseAudit(
                $pdo,
                ['id'=>'system','name'=>'System Tax Audit'],
                'rc4_4_tax_metadata_reconciled',
                'tax',
                'system_default',
                null,
                $result,
                'migration'
            );
            $message = 'RC4.4 selesai melengkapi metadata PBJT yang hilang tanpa mengubah kas atau histori pajak valid: '
                . $transactionsProcessed . ' transaksi dan ' . $bookingsProcessed . ' reservasi diproses.';
            $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type)
                VALUES (?,?,CURRENT_TIMESTAMP,0,'system')
                ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP")
                ->execute(['notif_rc4_4_tax_audit',$message]);
            $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
                VALUES (?,'Dynamic PBJT rules, non-destructive legacy metadata completion, and cross-channel tax reporting',CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute([$version]);
            $pdo->prepare("DELETE FROM schema_migration_progress WHERE version=?")->execute([$version]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log(clientExceptionMessage('[api.php] RC4.4 tax audit migration failed', $e));
    } finally {
        if ($lockAcquired) {
            try { $pdo->query("SELECT RELEASE_LOCK('tamasya_rc4_4_tax_migration')"); } catch (Throwable $ignored) {}
        }
    }
}

/** Source line 6468: runRc47DynamicTaxMigration */
function runRc47DynamicTaxMigration($pdo): void {
    $version='2026.07.rc4.7-dynamic-tax.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lockAcquired=false;
    try{
        $lockAcquired=(int)$pdo->query("SELECT GET_LOCK('tamasya_rc4_7_dynamic_tax',0)")->fetchColumn()===1;
        if(!$lockAcquired)return;
        $pdo->beginTransaction();
        $reservedIds=['tax_extra_zero','tax_ota_zero','tax_direct_room','tax_direct_extension'];
        $placeholders=implode(',',array_fill(0,count($reservedIds),'?'));
        $select=$pdo->prepare("SELECT id,name,rate,taxable,created_by FROM tax_rules WHERE id IN ({$placeholders}) AND (created_by IS NULL OR created_by='' OR created_by='system') FOR UPDATE");
        $select->execute($reservedIds);
        $removedRules=$select->fetchAll()?:[];
        if($removedRules){
            $delete=$pdo->prepare("DELETE FROM tax_rules WHERE id IN ({$placeholders}) AND (created_by IS NULL OR created_by='' OR created_by='system')");
            $delete->execute($reservedIds);
        }
        writeEnterpriseAudit(
            $pdo,
            ['id'=>'system','name'=>'System Dynamic Tax Migration'],
            'Menghapus tarif pajak statis bawaan',
            'tax_rule',
            'legacy-system-defaults',
            $removedRules,
            ['removedCount'=>count($removedRules),'futureFallbackRate'=>0,'historicalMetadataPreserved'=>true],
            'migration'
        );
        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at) VALUES (?,'Remove legacy static tax defaults; tax_rules becomes the sole policy source; preserve historical metadata',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES ('notif_rc4_7_dynamic_tax','Tarif pajak statis bawaan telah dihapus. Atur tarif aktif melalui Aktivitas / Fitur Khusus > Aturan Pajak. Histori transaksi lama dipertahankan.',CURRENT_TIMESTAMP,0,'system') ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP")->execute();
        bumpServerRevision($pdo);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[api.php] RC4.7 dynamic tax migration failed',$e));
    }finally{
        if($lockAcquired){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_rc4_7_dynamic_tax')");}catch(Throwable $ignored){}}
    }
}

/** Source line 6514: rc410ColumnMeta */
function rc410ColumnMeta(PDO $pdo, string $table, string $column): ?array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $stmt->execute([$table,$column]);
    $row=$stmt->fetch();
    return $row ?: null;
}

/** Source line 6522: rc410TableExists */
function rc410TableExists(PDO $pdo, string $table): bool {
    $stmt=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

/** Source line 6527: rc410EnsureColumn */
function rc410EnsureColumn(PDO $pdo,string $table,string $column,string $definition): void {
    if(!rc410TableExists($pdo,$table)) throw new RuntimeException("Tabel wajib {$table} tidak tersedia");
    if(rc410ColumnMeta($pdo,$table,$column)===null){
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
    if(rc410ColumnMeta($pdo,$table,$column)===null) throw new RuntimeException("Kolom {$table}.{$column} gagal dibuat");
}

/** Source line 6534: rc410EnsureColumnType */
function rc410EnsureColumnType(PDO $pdo,string $table,string $column,string $definition,string $expectedTypePrefix): void {
    $meta=rc410ColumnMeta($pdo,$table,$column);
    if($meta===null) throw new RuntimeException("Kolom wajib {$table}.{$column} tidak tersedia");
    $actual=strtolower((string)($meta['COLUMN_TYPE']??''));
    if(!str_starts_with($actual,strtolower($expectedTypePrefix))){
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
        $meta=rc410ColumnMeta($pdo,$table,$column);
        $actual=strtolower((string)($meta['COLUMN_TYPE']??''));
    }
    if(!str_starts_with($actual,strtolower($expectedTypePrefix))){
        throw new RuntimeException("Tipe {$table}.{$column} masih {$actual}, seharusnya {$expectedTypePrefix}");
    }
}

/** Source line 6547: runRc410FullSystemAlignment */
function runRc410FullSystemAlignment($pdo): void {
    if (!$pdo) return;
    $version='2026.07.rc4.10.1-activity-audit.1';
    if(schemaMigrationApplied($pdo,$version)) return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_rc4_10_alignment',2)")->fetchColumn()===1;
        if(!$lock) return;
        rc410EnsureColumn($pdo,'config','telegram_webhook_secret','VARCHAR(255) NULL');
        rc410EnsureColumn($pdo,'config','telegram_webhook_url','VARCHAR(500) NULL');
        rc410EnsureColumn($pdo,'config','telegram_webhook_last_error','TEXT NULL');
        rc410EnsureColumn($pdo,'config','telegram_webhook_checked_at','DATETIME NULL');
        if(!rc410TableExists($pdo,'notification_reads')){
            $pdo->exec("CREATE TABLE notification_reads (
                notification_id VARCHAR(50) NOT NULL, staff_id VARCHAR(50) NOT NULL, read_at DATETIME NOT NULL,
                PRIMARY KEY(notification_id,staff_id), INDEX idx_notification_reads_staff(staff_id,read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if(!rc410TableExists($pdo,'notification_reads')) throw new RuntimeException('Tabel notification_reads gagal dibuat');
        rc410EnsureColumn($pdo,'salary_slips','payment_method','VARCHAR(20) NULL');
        rc410EnsureColumn($pdo,'salary_slips','bank_account_id','VARCHAR(100) NULL');
        rc410EnsureColumn($pdo,'salary_slips','paid_at','DATETIME NULL');
        rc410EnsureColumn($pdo,'salary_slips','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        rc410EnsureColumn($pdo,'telegram_messages','telegram_user_id','VARCHAR(100) NULL');
        rc410EnsureColumn($pdo,'telegram_messages','staff_id','VARCHAR(50) NULL');
        rc410EnsureColumn($pdo,'telegram_messages','command','VARCHAR(100) NULL');
        rc410EnsureColumnType($pdo,'bookings','vatRate','DECIMAL(7,3) NULL','decimal(7,3)');
        rc410EnsureColumnType($pdo,'transactions','taxRate','DECIMAL(7,3) NULL','decimal(7,3)');
        // Default lama "paid" dapat meluluskan booking yang dibuat tanpa field status.
        $bookingPayment=rc410ColumnMeta($pdo,'bookings','paymentStatus');
        if($bookingPayment && strtolower((string)($bookingPayment['COLUMN_DEFAULT']??''))!=='unpaid'){
            $pdo->exec("ALTER TABLE bookings MODIFY COLUMN paymentStatus VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
        }
        // Verify all requirements again before recording the marker.
        foreach([
            ['config','telegram_webhook_secret'],['config','telegram_webhook_url'],['config','telegram_webhook_last_error'],['config','telegram_webhook_checked_at'],
            ['salary_slips','payment_method'],['salary_slips','bank_account_id'],['salary_slips','paid_at'],['salary_slips','updated_at'],
            ['telegram_messages','telegram_user_id'],['telegram_messages','staff_id'],['telegram_messages','command']
        ] as $required){
            if(rc410ColumnMeta($pdo,$required[0],$required[1])===null) throw new RuntimeException("Verifikasi skema gagal: {$required[0]}.{$required[1]}");
        }
        rc410EnsureColumnType($pdo,'bookings','vatRate','DECIMAL(7,3) NULL','decimal(7,3)');
        rc410EnsureColumnType($pdo,'transactions','taxRate','DECIMAL(7,3) NULL','decimal(7,3)');
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at)
            VALUES (?,'Strict server/build alignment: salary, Telegram audit, decimal dynamic tax, and safe booking defaults',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        bumpServerRevision($pdo);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[api.php] RC4.10 strict alignment failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_rc4_10_alignment')");}catch(Throwable $ignored){}}
    }
}

/** Source line 6603: runRc4103TestDataPurgeMigration */
function runRc4103TestDataPurgeMigration($pdo): void {
    $version='2026.07.rc4.10.3-test-data-purge.1';
    if(!$pdo || schemaMigrationApplied($pdo,$version)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS test_data_purge_logs (
            id VARCHAR(80) PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL DEFAULT 'booking',
            entity_id VARCHAR(100) NOT NULL,
            room_number VARCHAR(20) DEFAULT NULL,
            transaction_count INT NOT NULL DEFAULT 0,
            gross_income DECIMAL(15,2) NOT NULL DEFAULT 0,
            gross_expense DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            snapshot_hash VARCHAR(64) NOT NULL,
            purged_by VARCHAR(50) NOT NULL,
            purged_by_name VARCHAR(150) NOT NULL,
            purged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_test_purge_entity(entity_type,entity_id),
            INDEX idx_test_purge_date(purged_at),
            INDEX idx_test_purge_staff(purged_by,purged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Controlled permanent purge for explicitly confirmed test bookings',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.3 purge migration failed',$e));
    }
}

/** Source line 6634: runRc4104ImplementationCleanupMigration */
function runRc4104ImplementationCleanupMigration($pdo): void {
    $version='2026.07.rc4.10.4-implementation-cleanup.1';
    if(!$pdo || schemaMigrationApplied($pdo,$version)) return;
    try {
        rc410EnsureColumn($pdo,'config','cleanup_mode_until','DATETIME NULL');
        rc410EnsureColumn($pdo,'config','cleanup_mode_enabled_by','VARCHAR(50) NULL');
        rc410EnsureColumn($pdo,'config','cleanup_mode_note','TEXT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_purge_batches (
            id VARCHAR(80) PRIMARY KEY,
            status VARCHAR(30) NOT NULL DEFAULT 'completed',
            reason_category VARCHAR(50) NOT NULL,
            reason TEXT NOT NULL,
            backup_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            force_mode TINYINT(1) NOT NULL DEFAULT 0,
            booking_count INT NOT NULL DEFAULT 0,
            standalone_transaction_count INT NOT NULL DEFAULT 0,
            transaction_count INT NOT NULL DEFAULT 0,
            gross_income DECIMAL(15,2) NOT NULL DEFAULT 0,
            gross_expense DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            affected_shift_count INT NOT NULL DEFAULT 0,
            affected_reconciliation_count INT NOT NULL DEFAULT 0,
            impact_json LONGTEXT NULL,
            snapshot_hash VARCHAR(64) NOT NULL,
            created_by VARCHAR(50) NOT NULL,
            created_by_name VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_data_purge_created(created_at),
            INDEX idx_data_purge_staff(created_by,created_at),
            INDEX idx_data_purge_status(status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_purge_batch_items (
            id VARCHAR(100) PRIMARY KEY,
            batch_id VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(100) NOT NULL,
            related_booking_id VARCHAR(100) NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            item_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_purge_batch_entity(batch_id,entity_type,entity_id),
            INDEX idx_purge_item_batch(batch_id),
            INDEX idx_purge_item_entity(entity_type,entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sync_tombstones (
            id VARCHAR(100) PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(100) NOT NULL,
            purge_batch_id VARCHAR(80) NOT NULL,
            deleted_by VARCHAR(50) NOT NULL,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            UNIQUE KEY uq_sync_tombstone_entity(entity_type,entity_id),
            INDEX idx_sync_tombstone_deleted(deleted_at),
            INDEX idx_sync_tombstone_batch(purge_batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach(['data_purge_batches','data_purge_batch_items','sync_tombstones'] as $table){
            if(!rc410TableExists($pdo,$table)) throw new RuntimeException('Tabel cleanup gagal dibuat: '.$table);
        }
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Batch cleanup for selected implementation-period bookings and standalone manual transactions',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.4 cleanup migration failed',$e));
    }
}

/** Source line 6703: runRc4105OtaAllocationMigration */
function runRc4105OtaAllocationMigration($pdo): void {
    $version='2026.07.rc4.10.5-ota-allocation.1';
    if(!$pdo || schemaMigrationApplied($pdo,$version)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ota_disbursements (
            id VARCHAR(80) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            disbursement_date DATE NOT NULL,
            bank_account_id VARCHAR(50) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            notes TEXT NULL,
            source_transaction_id VARCHAR(50) NOT NULL,
            destination_transaction_id VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'completed',
            created_by VARCHAR(50) NOT NULL,
            created_by_name VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ota_disbursement_operation(operation_id),
            INDEX idx_ota_disbursement_date(disbursement_date),
            INDEX idx_ota_disbursement_bank(bank_account_id),
            INDEX idx_ota_disbursement_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ota_disbursement_items (
            id VARCHAR(100) PRIMARY KEY,
            disbursement_id VARCHAR(80) NOT NULL,
            receivable_transaction_id VARCHAR(50) NOT NULL,
            booking_id VARCHAR(50) NULL,
            allocated_amount DECIMAL(15,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ota_disbursement_receivable(disbursement_id,receivable_transaction_id),
            INDEX idx_ota_item_disbursement(disbursement_id),
            INDEX idx_ota_item_receivable(receivable_transaction_id),
            INDEX idx_ota_item_booking(booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach(['ota_disbursements','ota_disbursement_items'] as $table){
            if(!rc410TableExists($pdo,$table)) throw new RuntimeException('Tabel OTA allocation gagal dibuat: '.$table);
        }
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Atomic OTA disbursement and booking allocation mapping',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.5 OTA allocation migration failed',$e));
    }
}

/** Source line 6750: runRc4106ShiftIdentityMigration */
function runRc4106ShiftIdentityMigration($pdo): void {
    $version='2026.07.rc4.10.6-shift-identity.1';
    if(!$pdo || schemaMigrationApplied($pdo,$version)) return;
    try {
        rc410EnsureColumn($pdo,'shift_reports','staffId','VARCHAR(50) NULL AFTER `id`');
        rc410EnsureColumn($pdo,'shift_reports','companionStaffId','VARCHAR(50) NULL AFTER `staffId`');
        rc410EnsureColumn($pdo,'shift_reports','shiftSessionId','VARCHAR(100) NULL AFTER `companionStaffId`');
        foreach([
            "CREATE UNIQUE INDEX uq_shift_reports_session ON shift_reports (shiftSessionId)",
            "CREATE INDEX idx_shift_reports_staff_id ON shift_reports (staffId,shiftDate)",
            "CREATE INDEX idx_shift_reports_companion ON shift_reports (companionStaffId,shiftDate)",
            "CREATE INDEX idx_shift_reports_staff_name ON shift_reports (staffName)"
        ] as $indexSql){
            try { $pdo->exec($indexSql); } catch(Throwable $ignored) {}
        }
        // Legacy reports are backfilled only when a name maps to exactly one staff row.
        // Ambiguous names stay NULL and are never exposed to a receptionist.
        $pdo->exec("UPDATE shift_reports sr
            JOIN (SELECT name,MIN(id) staff_id,COUNT(*) name_count FROM staff GROUP BY name) s ON s.name=sr.staffName AND s.name_count=1
            SET sr.staffId=s.staff_id
            WHERE sr.staffId IS NULL");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Shift reports linked to immutable staff/session IDs; safe legacy backfill',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.6 shift identity migration failed',$e));
    }
}

/** Source line 6782: runRc4107StaffSavingsMigration */
function runRc4107StaffSavingsMigration($pdo): void {
    $version='2026.07.rc4.10.7-staff-savings.1';
    if(!$pdo || schemaMigrationApplied($pdo,$version)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_savings_accounts (
            staff_id VARCHAR(50) PRIMARY KEY,
            balance DECIMAL(15,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_staff_savings_account_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_savings_requests (
            id VARCHAR(80) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            purpose TEXT NOT NULL,
            payout_method VARCHAR(30) NOT NULL DEFAULT 'cash',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_by VARCHAR(50) NOT NULL,
            requested_by_name VARCHAR(150) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by VARCHAR(50) NULL,
            approved_by_name VARCHAR(150) NULL,
            approved_at DATETIME NULL,
            decision_notes TEXT NULL,
            paid_by VARCHAR(50) NULL,
            paid_by_name VARCHAR(150) NULL,
            paid_at DATETIME NULL,
            cancelled_by VARCHAR(50) NULL,
            cancelled_by_name VARCHAR(150) NULL,
            cancelled_at DATETIME NULL,
            ledger_entry_id VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_savings_request_operation(operation_id),
            UNIQUE KEY uq_staff_savings_request_ledger(ledger_entry_id),
            INDEX idx_staff_savings_request_staff(staff_id,status,requested_at),
            INDEX idx_staff_savings_request_status(status,requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_savings_ledger (
            id VARCHAR(80) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            receipt_number VARCHAR(80) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            entry_type VARCHAR(30) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            balance_before DECIMAL(15,2) NOT NULL,
            balance_after DECIMAL(15,2) NOT NULL,
            source_type VARCHAR(50) NULL,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
            storage_reference VARCHAR(190) NULL,
            description TEXT NULL,
            request_id VARCHAR(80) NULL,
            reference_entry_id VARCHAR(80) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'posted',
            created_by VARCHAR(50) NOT NULL,
            created_by_name VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_savings_ledger_operation(operation_id),
            UNIQUE KEY uq_staff_savings_ledger_receipt(receipt_number),
            UNIQUE KEY uq_staff_savings_ledger_request(request_id),
            INDEX idx_staff_savings_ledger_staff(staff_id,created_at),
            INDEX idx_staff_savings_ledger_type(entry_type,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach(['staff_savings_accounts','staff_savings_requests','staff_savings_ledger'] as $table){
            if(!rc410TableExists($pdo,$table)) throw new RuntimeException('Tabel Simpanan Karyawan gagal dibuat: '.$table);
        }
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Isolated employee savings custody ledger and withdrawal workflow',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.7 staff savings migration failed',$e));
    }
}

/** Source line 6862: runRc4109DeepAuditMigration */
function runRc4109DeepAuditMigration($pdo): void {
    if (!$pdo) return;
    $version='2026.07.rc4.10.9.deep-audit.1';
    if (schemaMigrationApplied($pdo,$version)) return;
    try {
        ensureColumnExists($pdo,'chat_messages','staff_id','VARCHAR(50) NULL');
        try { $pdo->exec("CREATE INDEX idx_chat_staff_time ON chat_messages (staff_id,timestamp)"); } catch (Throwable $ignored) {}
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Scope Help Chat by staff and harden RC4.10.9 contracts',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] RC4.10.9 deep audit migration failed',$e));
    }
}

/** Source line 6878: runRc41010FieldCheckoutMigration */
function runRc41010FieldCheckoutMigration($pdo): void {
    if(!$pdo)return;
    $version='2026.07.rc4.10.10-field-checkout.1';
    if(schemaMigrationApplied($pdo,$version))return;
    try{
        $pdo->exec("CREATE TABLE IF NOT EXISTS room_vacancy_reports (
            id VARCHAR(80) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            booking_id VARCHAR(100) NOT NULL,
            room_number VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            key_observation VARCHAR(30) NOT NULL DEFAULT 'unknown',
            belongings_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            damage_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            notes TEXT NULL,
            photos LONGTEXT NULL,
            observed_at DATETIME NOT NULL,
            reported_by VARCHAR(50) NOT NULL,
            reported_by_name VARCHAR(150) NOT NULL,
            reported_role VARCHAR(50) NOT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'web',
            reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by VARCHAR(50) NULL,
            reviewed_by_name VARCHAR(150) NULL,
            reviewed_at DATETIME NULL,
            review_notes TEXT NULL,
            checkout_operation_id VARCHAR(100) NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_room_vacancy_operation(operation_id),
            INDEX idx_room_vacancy_status(status,reported_at),
            INDEX idx_room_vacancy_booking(booking_id,status),
            INDEX idx_room_vacancy_room(room_number,status),
            INDEX idx_room_vacancy_reporter(reported_by,reported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if(!rc410TableExists($pdo,'room_vacancy_reports'))throw new RuntimeException('Tabel laporan kamar kosong gagal dibuat.');
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Field vacancy reporting and unified checkout lifecycle',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){error_log(clientExceptionMessage('[api.php] RC4.10.10 field checkout migration failed',$e));}
}

/** Source line 6921: runRc41011ReceptionHousekeepingMigration */
function runRc41011ReceptionHousekeepingMigration($pdo): void {
    if(!$pdo)return;
    $version='2026.07.rc4.10.11-reception-housekeeping.1';
    if(schemaMigrationApplied($pdo,$version))return;
    try{
        ensureColumnExists($pdo,'housekeeping_tasks','assigned_staff_id','VARCHAR(50) NULL');
        ensureColumnExists($pdo,'housekeeping_tasks','completed_by_staff_id','VARCHAR(50) NULL');
        ensureColumnExists($pdo,'housekeeping_tasks','last_action_by_staff_id','VARCHAR(50) NULL');
        ensureColumnExists($pdo,'housekeeping_tasks','last_action_source','VARCHAR(30) NULL');
        try{$pdo->exec("CREATE INDEX idx_hk_assigned_staff ON housekeeping_tasks (assigned_staff_id,status)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE INDEX idx_hk_completed_staff ON housekeeping_tasks (completed_by_staff_id,completed_at)");}catch(Throwable $ignored){}
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Receptionist housekeeping capability, actor identity, and check-in modal stabilization',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){error_log(clientExceptionMessage('[api.php] RC4.10.11 reception housekeeping migration failed',$e));}
}

/** Source line 6939: runRc41012FlexibleShiftPaymentMigration */
function runRc41012FlexibleShiftPaymentMigration($pdo): void {
    if(!$pdo)return;
    $version='2026.07.rc4.10.12-flex-shift-payment.1';
    if(schemaMigrationApplied($pdo,$version))return;
    try{
        rc410EnsureColumn($pdo,'shift_sessions','companion_staff_id','VARCHAR(50) NULL AFTER `staff_name`');
        rc410EnsureColumn($pdo,'shift_sessions','companion_staff_name','VARCHAR(150) NULL AFTER `companion_staff_id`');
        foreach([
            "CREATE INDEX idx_shift_companion_status ON shift_sessions (companion_staff_id,status)",
            "CREATE INDEX idx_shift_participants_date ON shift_sessions (staff_id,companion_staff_id,shift_date,status)"
        ] as $sql){try{$pdo->exec($sql);}catch(Throwable $e){}}
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Flexible one/two receptionist shared shift and repeat booking deposits',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){error_log(clientExceptionMessage('[api.php] RC4.10.12 flexible shift/payment migration failed',$e));}
}



/** RC4.10.15.11: preserve manual cash receipts and add audited payroll correction. */
function runRc4101511RootWorkflowMigration($pdo): void {
    if(!$pdo)return;
    $version='2026.07.rc4.10.15.11-root-workflow.1';
    if(schemaMigrationApplied($pdo,$version))return;
    try{
        $pdo->exec("CREATE TABLE IF NOT EXISTS transaction_allocations (
            id VARCHAR(80) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            transaction_id VARCHAR(100) NOT NULL,
            booking_id VARCHAR(100) NOT NULL,
            allocation_type VARCHAR(30) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            base_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
            category VARCHAR(100) DEFAULT NULL,
            subcategory VARCHAR(100) DEFAULT NULL,
            booking_extra_id VARCHAR(100) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            booking_total_delta DECIMAL(15,2) NOT NULL DEFAULT 0,
            previous_check_out DATE DEFAULT NULL,
            new_check_out DATE DEFAULT NULL,
            extra_was_existing TINYINT(1) NOT NULL DEFAULT 0,
            transaction_booking_link_added TINYINT(1) NOT NULL DEFAULT 0,
            transaction_room_link_added TINYINT(1) NOT NULL DEFAULT 0,
            transaction_source_link_added TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_by VARCHAR(50) DEFAULT NULL,
            voided_at DATETIME DEFAULT NULL,
            void_reason TEXT DEFAULT NULL,
            UNIQUE KEY uq_transaction_allocation_operation(operation_id),
            INDEX idx_transaction_allocation_tx(transaction_id,status),
            INDEX idx_transaction_allocation_booking(booking_id,status),
            INDEX idx_transaction_allocation_extra(booking_extra_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach([
            ['operation_id','VARCHAR(100) NOT NULL'],
            ['transaction_id','VARCHAR(100) NOT NULL'],
            ['booking_id','VARCHAR(100) NOT NULL'],
            ['allocation_type','VARCHAR(30) NOT NULL'],
            ['amount','DECIMAL(15,2) NOT NULL'],
            ['base_amount','DECIMAL(15,2) NOT NULL DEFAULT 0'],
            ['tax_amount','DECIMAL(15,2) NOT NULL DEFAULT 0'],
            ['tax_rate','DECIMAL(7,3) NOT NULL DEFAULT 0'],
            ['category','VARCHAR(100) DEFAULT NULL'],
            ['subcategory','VARCHAR(100) DEFAULT NULL'],
            ['booking_extra_id','VARCHAR(100) DEFAULT NULL'],
            ['description','TEXT DEFAULT NULL'],
            ['booking_total_delta','DECIMAL(15,2) NOT NULL DEFAULT 0'],
            ['previous_check_out','DATE DEFAULT NULL'],
            ['new_check_out','DATE DEFAULT NULL'],
            ['extra_was_existing','TINYINT(1) NOT NULL DEFAULT 0'],
            ['transaction_booking_link_added','TINYINT(1) NOT NULL DEFAULT 0'],
            ['transaction_room_link_added','TINYINT(1) NOT NULL DEFAULT 0'],
            ['transaction_source_link_added','TINYINT(1) NOT NULL DEFAULT 0'],
            ['status',"VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['created_by','VARCHAR(50) DEFAULT NULL'],
            ['created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
            ['voided_by','VARCHAR(50) DEFAULT NULL'],
            ['voided_at','DATETIME DEFAULT NULL'],
            ['void_reason','TEXT DEFAULT NULL']
        ] as $column){ensureColumnExists($pdo,'transaction_allocations',$column[0],$column[1]);}
        foreach([
            "CREATE UNIQUE INDEX uq_transaction_allocation_operation ON transaction_allocations (operation_id)",
            "CREATE INDEX idx_transaction_allocation_tx ON transaction_allocations (transaction_id,status)",
            "CREATE INDEX idx_transaction_allocation_booking ON transaction_allocations (booking_id,status)",
            "CREATE INDEX idx_transaction_allocation_extra ON transaction_allocations (booking_extra_id,status)"
        ] as $sql){try{$pdo->exec($sql);}catch(Throwable $ignored){}}
        foreach([
            ['cancelled_at','DATETIME DEFAULT NULL'],
            ['cancelled_by','VARCHAR(50) DEFAULT NULL'],
            ['cancellation_reason','TEXT DEFAULT NULL'],
            ['reversal_transaction_id','VARCHAR(100) DEFAULT NULL'],
            ['correction_of_slip_id','VARCHAR(80) DEFAULT NULL'],
            ['corrected_by_slip_id','VARCHAR(80) DEFAULT NULL'],
            ['correction_reason','TEXT DEFAULT NULL']
        ] as $column){ensureColumnExists($pdo,'salary_slips',$column[0],$column[1]);}
        foreach([
            "CREATE INDEX idx_salary_status ON salary_slips (status,updated_at)",
            "CREATE INDEX idx_salary_correction ON salary_slips (correction_of_slip_id,corrected_by_slip_id)"
        ] as $sql){try{$pdo->exec($sql);}catch(Throwable $ignored){}}
        if(!rc410TableExists($pdo,'transaction_allocations'))throw new RuntimeException('Tabel transaction_allocations gagal dibuat.');
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Preserve manual cash receipts with booking allocations and support audited salary payment cancellation/correction',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){error_log(clientExceptionMessage('[api.php] RC4.10.15.11 root workflow migration failed',$e));}
}

/** RC4.10.15.13: provider-neutral communication channels, identities, events and delivery outbox. */
function runRc4101513CommunicationCoreMigration($pdo): void {
    $version='2026.07.rc4.10.15.13-dynamic-communication-core.1';
    if(!$pdo)return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_communication_core_migration',3)")->fetchColumn()===1;
        if(!$lock)return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_channels (
            id VARCHAR(80) PRIMARY KEY,
            provider_key VARCHAR(64) NOT NULL,
            display_name VARCHAR(150) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            inbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
            outbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
            managed_by VARCHAR(40) NOT NULL DEFAULT 'communication_core',
            config_json LONGTEXT NULL,
            credential_encrypted LONGTEXT NULL,
            webhook_secret_encrypted LONGTEXT NULL,
            health_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            last_checked_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_comm_channel_provider(provider_key,enabled),
            INDEX idx_comm_channel_health(health_status,last_checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_identities (
            id VARCHAR(100) PRIMARY KEY,
            channel_id VARCHAR(80) NOT NULL,
            provider_user_id VARCHAR(190) NOT NULL,
            provider_conversation_id VARCHAR(190) NULL,
            staff_id VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            verified_at DATETIME NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_identity_provider(channel_id,provider_user_id),
            UNIQUE KEY uq_comm_identity_staff(channel_id,staff_id),
            INDEX idx_comm_identity_staff(staff_id,status),
            INDEX idx_comm_identity_conversation(channel_id,provider_conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_binding_codes (
            id VARCHAR(100) PRIMARY KEY,
            channel_id VARCHAR(80) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            code_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            used_provider_user_id VARCHAR(190) NULL,
            created_by VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_binding_code(code_hash),
            INDEX idx_comm_binding_staff(channel_id,staff_id,expires_at,used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_sessions (
            id VARCHAR(100) PRIMARY KEY,
            channel_identity_id VARCHAR(100) NOT NULL,
            state VARCHAR(100) NULL,
            context_json LONGTEXT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_session_identity(channel_identity_id),
            INDEX idx_comm_session_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_webhook_events (
            id VARCHAR(100) PRIMARY KEY,
            channel_id VARCHAR(80) NOT NULL,
            provider_event_id VARCHAR(190) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'processing',
            attempts INT NOT NULL DEFAULT 1,
            last_error TEXT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_webhook_event(channel_id,provider_event_id),
            INDEX idx_comm_webhook_status(status,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_outbox (
            id VARCHAR(100) PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            recipient_staff_id VARCHAR(50) NULL,
            preferred_channel_id VARCHAR(80) NULL,
            fallback_channel_ids LONGTEXT NULL,
            message_json LONGTEXT NOT NULL,
            priority TINYINT NOT NULL DEFAULT 5,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_by VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_comm_outbox_ready(status,scheduled_at,priority,created_at),
            INDEX idx_comm_outbox_staff(recipient_staff_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_delivery_attempts (
            id VARCHAR(100) PRIMARY KEY,
            outbox_id VARCHAR(100) NOT NULL,
            channel_id VARCHAR(80) NOT NULL,
            provider_message_id VARCHAR(190) NULL,
            status VARCHAR(30) NOT NULL,
            attempt_number INT NOT NULL DEFAULT 1,
            error_message TEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_comm_delivery_outbox(outbox_id,attempt_number),
            INDEX idx_comm_delivery_channel(channel_id,status,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_inbox (
            id VARCHAR(100) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            channel_id VARCHAR(80) NOT NULL,
            title VARCHAR(200) NULL,
            message TEXT NOT NULL,
            action_json LONGTEXT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comm_inbox_staff(staff_id,read_at,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_preferences (
            staff_id VARCHAR(50) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            primary_channel_id VARCHAR(80) NULL,
            fallback_channel_ids LONGTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            quiet_hours_json LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(staff_id,event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_templates (
            id VARCHAR(100) PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            channel_id VARCHAR(80) NULL,
            language VARCHAR(20) NOT NULL DEFAULT 'id',
            subject_template VARCHAR(255) NULL,
            body_template TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_template(event_type,channel_id,language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if(!schemaMigrationApplied($pdo,$version)){
            $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Provider-neutral communication adapter registry, identity binding, webhook idempotency, inbox, templates and delivery outbox',CURRENT_TIMESTAMP)")->execute([$version]);
        }
        if(function_exists('tamasyaCommunicationSyncLegacyChannels'))tamasyaCommunicationSyncLegacyChannels($pdo);
    }catch(Throwable $e){error_log(clientExceptionMessage('[communication] migration failed',$e));}
    finally{if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_communication_core_migration')");}catch(Throwable $ignored){}}}
}


/** RC4.10.15.14.7: repair legacy future-active bookings and open-ended due dates. */
function runRc41015147BookingLifecycleMigration($pdo): void {
    $version='2026.07.rc4.10.15.14.7-booking-lifecycle.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_booking_lifecycle_147',3)")->fetchColumn()===1;
        if(!$lock)return;
        if(schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();
        // Versi lama menyimpan semua pemesanan sebagai active, termasuk tanggal
        // mendatang. Uang muka/ledger tetap dipertahankan; yang diperbaiki hanya
        // lifecycle okupansi, akses fisik, dan projection kamar.
        $futureStmt=$pdo->query("SELECT id,roomNumber FROM bookings WHERE status='active' AND checkIn>CURRENT_DATE FOR UPDATE");
        $futureRows=$futureStmt ? ($futureStmt->fetchAll(PDO::FETCH_ASSOC)?:[]) : [];
        if($futureRows){
            $futureIds=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['id']??'')),$futureRows)));
            if($futureIds){
                $ph=implode(',',array_fill(0,count($futureIds),'?'));
                $pdo->prepare("UPDATE bookings SET status='reserved',actualCheckInAt=NULL,checkoutDueAt=CASE WHEN COALESCE(isOpenEnded,0)=1 THEN NULL ELSE checkoutDueAt END,keyControlStatus='not_issued',keyIssuedAt=NULL,keyIssuedBy=NULL,keyReturnedAt=NULL,keyReturnedBy=NULL,smartLockCodeHash=NULL,smartLockCodeLast4=NULL,smartLockValidFrom=NULL,smartLockValidUntil=NULL,version=version+1,updatedSource='migration-14.7' WHERE id IN ($ph)")->execute($futureIds);
                $pdo->prepare("UPDATE room_access_control SET current_booking_id=NULL,physical_key_status=CASE WHEN physical_key_status='issued' THEN 'secured' ELSE physical_key_status END,last_event_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE current_booking_id IN ($ph)")->execute($futureIds);
            }
            $rooms=array_values(array_unique(array_filter(array_map(static fn($row)=>trim((string)($row['roomNumber']??'')),$futureRows))));
            foreach($rooms as $roomNumber){
                $active=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");
                $active->execute([$roomNumber]);
                if(!$active->fetchColumn()){
                    $roomState=$pdo->prepare("SELECT status FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
                    $roomState->execute([$roomNumber]);
                    if((string)$roomState->fetchColumn()==='booked'){
                        $blockers=getRoomOperationalBlockers($pdo,$roomNumber,true);
                        $pdo->prepare("UPDATE rooms SET status=?,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedSource='migration-14.7' WHERE number=?")
                            ->execute([$blockers?'maintenance':'available',$roomNumber]);
                    }
                }
            }
        }
        $pdo->exec("UPDATE bookings SET checkoutDueAt=NULL WHERE status='active' AND COALESCE(isOpenEnded,0)=1");
        $pdo->exec("UPDATE bookings SET actualCheckInAt=NULL,keyControlStatus='not_issued' WHERE status='reserved'");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Separate reserved and active lifecycle; repair future occupancy and open-ended checkout due dates without changing ledger',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[booking lifecycle 14.7] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_booking_lifecycle_147')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.14.8: repair duplicate housekeeping tasks and enforce one active task per room. */
function runRc41015148RoomOperationalReadinessMigration($pdo): void {
    $version='2026.07.rc4.10.15.14.8-room-operational-readiness.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_room_readiness_148',5)")->fetchColumn()===1;
        if(!$lock)return;
        if(schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();
        // Pertahankan blocker paling berat (blocked > inspection > cleaning > assigned > dirty),
        // lalu task terbaru. Duplikat lain ditutup sebagai rekonsiliasi data legacy.
        $pdo->exec("UPDATE housekeeping_tasks loser
            JOIN housekeeping_tasks winner ON winner.room_number=loser.room_number
             AND winner.status NOT IN ('ready','completed') AND loser.status NOT IN ('ready','completed')
             AND (
                FIELD(winner.status,'dirty','assigned','cleaning','inspection','blocked') > FIELD(loser.status,'dirty','assigned','cleaning','inspection','blocked')
                OR (FIELD(winner.status,'dirty','assigned','cleaning','inspection','blocked') = FIELD(loser.status,'dirty','assigned','cleaning','inspection','blocked') AND winner.created_at > loser.created_at)
                OR (FIELD(winner.status,'dirty','assigned','cleaning','inspection','blocked') = FIELD(loser.status,'dirty','assigned','cleaning','inspection','blocked') AND winner.created_at = loser.created_at AND winner.id > loser.id)
             )
            SET loser.status='completed',loser.completed_at=COALESCE(loser.completed_at,CURRENT_TIMESTAMP),
                loser.notes=CONCAT(COALESCE(loser.notes,''),CASE WHEN COALESCE(loser.notes,'')='' THEN '' ELSE '\n' END,'Migrasi 14.8: task aktif ganda ditutup; blocker prioritas tertinggi dipertahankan.'),
                loser.last_action_source='migration-14.8',loser.updated_at=CURRENT_TIMESTAMP");
        $pdo->commit();

        if(rc410ColumnMeta($pdo,'housekeeping_tasks','active_room_key')===null){
            $pdo->exec("ALTER TABLE housekeeping_tasks ADD COLUMN active_room_key VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status NOT IN ('ready','completed') THEN room_number ELSE NULL END) STORED");
        }
        $idx=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='housekeeping_tasks' AND INDEX_NAME='uq_hk_one_active_room'");
        $idx->execute();
        if((int)$idx->fetchColumn()===0)$pdo->exec("CREATE UNIQUE INDEX uq_hk_one_active_room ON housekeeping_tasks(active_room_key)");
        $verify=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='housekeeping_tasks' AND INDEX_NAME='uq_hk_one_active_room'");
        $verify->execute();
        if((int)$verify->fetchColumn()===0)throw new RuntimeException('Unique index task housekeeping aktif per kamar gagal dibuat.');
        $pdo->beginTransaction();
        // Rekonsiliasi projection rooms.status setelah task legacy dirapikan.
        // Jangan membuka maintenance manual yang memang sengaja dipasang tanpa tiket.
        $roomStmt=$pdo->query("SELECT number,status FROM rooms ORDER BY number FOR UPDATE");
        foreach($roomStmt ? ($roomStmt->fetchAll(PDO::FETCH_ASSOC)?:[]) : [] as $room){
            $roomNumber=trim((string)($room['number']??''));
            if($roomNumber==='')continue;
            $activeStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");
            $activeStmt->execute([$roomNumber]);
            $hasActive=(bool)$activeStmt->fetchColumn();
            $currentStatus=(string)($room['status']??'available');
            $desiredStatus='';
            if($hasActive && $currentStatus!=='booked'){
                $desiredStatus='booked';
            }elseif(!$hasActive){
                $blockers=getRoomOperationalBlockers($pdo,$roomNumber,true);
                if($blockers && $currentStatus!=='maintenance')$desiredStatus='maintenance';
                elseif(!$blockers && $currentStatus==='booked')$desiredStatus='available';
            }
            if($desiredStatus!==''){
                $pdo->prepare("UPDATE rooms SET status=?,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedSource='migration-14.8' WHERE number=?")
                    ->execute([$desiredStatus,$roomNumber]);
            }
        }
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Canonical room readiness, reconciled room projection, and one active housekeeping task per room',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[room readiness 14.8] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_room_readiness_148')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.14.9: normalize legacy booking-origin receipts and rebuild their journals. */
function runRc41015149BookingPaymentGuardMigration(PDO $pdo): void {
    $version='2026.07.rc4.10.15.14.9-booking-payment-guard.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_booking_payment_guard_149',8)")->fetchColumn()===1;
        if(!$lock)return;
        if(schemaMigrationApplied($pdo,$version))return;
        // Snapshot storage is additive. 15.0 reuses the same table for the wider repair.
        $pdo->exec("CREATE TABLE IF NOT EXISTS invariant_repair_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            repair_version VARCHAR(100) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(190) NOT NULL,
            before_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_invariant_snapshot(repair_version,entity_type,entity_id),
            KEY idx_invariant_snapshot_entity(entity_type,entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->beginTransaction();
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'transaction-classification',t.id,JSON_OBJECT(
                'transactionKind',t.transactionKind,'isSystemGenerated',t.isSystemGenerated,
                'sourceEntity',t.sourceEntity,'sourceEntityId',t.sourceEntityId,
                'bookingId',t.bookingId,'category',t.category,'subcategory',t.subcategory,
                'description',t.description,'version',t.version)
            FROM transactions t
            LEFT JOIN (
                SELECT transaction_id,MAX(transaction_booking_link_added) workflow_booking_link_added
                FROM transaction_allocations GROUP BY transaction_id
            ) a ON a.transaction_id=t.id
            WHERE t.type='income' AND t.bookingId IS NOT NULL AND t.bookingId<>''
              AND COALESCE(t.transactionKind,'manual')='manual'
              AND COALESCE(a.workflow_booking_link_added,0)=0")->execute([$version]);
        $pdo->exec("UPDATE transactions t
            LEFT JOIN (
                SELECT transaction_id,MAX(transaction_booking_link_added) workflow_booking_link_added
                FROM transaction_allocations GROUP BY transaction_id
            ) a ON a.transaction_id=t.id
            SET t.transactionKind=CASE
                    WHEN LOWER(CONCAT_WS(' ',t.category,t.subcategory,t.description)) REGEXP
                         'extra|layanan|perpanjang|tambahan[[:space:]]*bed|sarapan|laundry|mini[[:space:]]*bar|pindah[[:space:]]*kamar'
                        THEN 'booking_charge'
                    ELSE 'booking_payment'
                END,
                t.isSystemGenerated=1,t.sourceEntity='booking',t.sourceEntityId=t.bookingId,
                t.version=COALESCE(t.version,0)+1,t.updatedAt=CURRENT_TIMESTAMP,
                t.updatedBy='system-booking-payment-guard',t.updatedSource='migration-14.9'
            WHERE t.type='income' AND t.bookingId IS NOT NULL AND t.bookingId<>''
              AND COALESCE(t.transactionKind,'manual')='manual'
              AND COALESCE(a.workflow_booking_link_added,0)=0");
        // transactionKind is journal-significant. Rebuild inside the same transaction
        // so no request can observe a new classification with an old journal.
        syncJournalProjections($pdo,true);
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at)
            VALUES (?,'Normalize legacy booking-origin receipts and rebuild journal projection',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[booking payment guard 14.9] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_booking_payment_guard_149')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.0: reconcile finance/date/audit invariants discovered in the hosting dataset. */
function runRc41015150InvariantRepair(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.0-invariant-repair.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_invariant_repair_150',8)")->fetchColumn()===1;
        if(!$lock)return;
        if(schemaMigrationApplied($pdo,$version))return;

        // DDL is additive and intentionally outside the data transaction because
        // MySQL/MariaDB may auto-commit ALTER/CREATE statements.
        $pdo->exec("CREATE TABLE IF NOT EXISTS invariant_repair_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            repair_version VARCHAR(100) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(190) NOT NULL,
            before_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_invariant_snapshot(repair_version,entity_type,entity_id),
            KEY idx_invariant_snapshot_entity(entity_type,entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_integrity_issues (
            id VARCHAR(100) NOT NULL,
            issue_type VARCHAR(100) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(190) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            details LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            resolution_note TEXT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY uq_integrity_issue(issue_type,entity_type,entity_id),
            KEY idx_integrity_issue_status(status,severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $affectedBookingStmt=$pdo->query("SELECT DISTINCT booking_id FROM transaction_allocations
            WHERE status='active' AND allocation_type='extra' AND COALESCE(extra_was_existing,0)=0
              AND COALESCE(booking_total_delta,0)=0 AND amount>0");
        $affectedBookingIds=$affectedBookingStmt?array_values(array_filter(array_map('strval',$affectedBookingStmt->fetchAll(PDO::FETCH_COLUMN)?:[]))):[];

        $pdo->beginTransaction();

        // Legacy protected transactions created before operation-id enforcement need
        // a deterministic immutable key so future retry/audit logic can identify them.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'transaction',id,JSON_OBJECT('operationId',operationId,'transactionKind',transactionKind,
                'bookingId',bookingId,'amount',amount,'date',`date`,'version',version)
            FROM transactions WHERE operationId IS NULL OR TRIM(operationId)=''")->execute([$version]);
        $pdo->exec("UPDATE transactions
            SET operationId=CONCAT('legacy_tx_',SUBSTRING(SHA2(CONCAT(id,'|',COALESCE(createdAt,'')),256),1,64)),
                updatedAt=CURRENT_TIMESTAMP,updatedBy='system-invariant-repair',updatedSource='migration-15.0'
            WHERE operationId IS NULL OR TRIM(operationId)=''");

        // Expired sessions and sessions on disabled devices must be terminal even if
        // an older release forgot to persist revoked_at. Authentication already
        // rejects them; this closes the durable audit state as well.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'user_session',us.id,JSON_OBJECT('staff_id',us.staff_id,'device_id',us.device_id,
                'expires_at',us.expires_at,'refresh_expires',us.refresh_expires,'revoked_at',us.revoked_at)
            FROM user_sessions us LEFT JOIN sync_devices sd ON sd.device_id=us.device_id
            WHERE us.revoked_at IS NULL AND (us.expires_at<NOW() OR sd.status='disabled')")->execute([$version]);
        $pdo->exec("UPDATE user_sessions us LEFT JOIN sync_devices sd ON sd.device_id=us.device_id
            SET us.revoked_at=CURRENT_TIMESTAMP
            WHERE us.revoked_at IS NULL AND (us.expires_at<NOW() OR sd.status='disabled')");

        // Historical sale transactions without a shift cannot be assigned safely
        // after the fact. Persist a visible issue rather than inventing a cashier.
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues
            (id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_shift_',SUBSTRING(SHA2(t.id,256),1,32)),
                'unassigned_required_shift_transaction','transaction',t.id,'warning',
                CONCAT('kind=',t.transactionKind,'; amount=',t.amount,'; date=',t.`date`,
                    '; actor=',COALESCE(t.updatedBy,t.createdBy,'unknown')),'open'
            FROM transactions t JOIN hotel_operational_settings h ON h.id='system_default'
            WHERE h.require_open_shift_for_sale=1 AND t.shiftSessionId IS NULL
              AND COALESCE(t.recordOrigin,'live_operation')='live_operation'
              AND COALESCE(t.shiftExempt,0)=0
              AND t.transactionKind IN ('manual','booking_payment','booking_charge','down_payment','settlement')");

        // Snapshot every row that will be changed, so rollback/manual comparison is possible.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'transaction_allocation',a.id,JSON_OBJECT(
                'booking_id',a.booking_id,'transaction_id',a.transaction_id,'allocation_type',a.allocation_type,
                'amount',a.amount,'booking_total_delta',a.booking_total_delta,'extra_was_existing',a.extra_was_existing,'status',a.status)
            FROM transaction_allocations a
            WHERE a.status='active' AND a.allocation_type='extra' AND COALESCE(a.extra_was_existing,0)=0
              AND COALESCE(a.booking_total_delta,0)=0 AND a.amount>0")->execute([$version]);
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT DISTINCT ?,'booking',b.id,JSON_OBJECT(
                'totalAmount',b.totalAmount,'vatAmount',b.vatAmount,'roomCharge',b.roomCharge,'extraCharge',b.extraCharge,
                'amountPaid',b.amountPaid,'balanceDue',b.balanceDue,'paymentStatus',b.paymentStatus,'checkIn',b.checkIn,
                'checkOut',b.checkOut,'actualCheckInAt',b.actualCheckInAt,'actualCheckOutAt',b.actualCheckOutAt)
            FROM bookings b JOIN transaction_allocations a ON a.booking_id=b.id
            WHERE a.status='active' AND a.allocation_type='extra' AND COALESCE(a.extra_was_existing,0)=0
              AND COALESCE(a.booking_total_delta,0)=0 AND a.amount>0")->execute([$version]);

        // A newly-created extra is a new charge. Legacy rows that created an extra
        // with zero booking delta caused roomCharge to shrink and ledger overpayment.
        $pdo->exec("UPDATE bookings b JOIN (
                SELECT booking_id,SUM(amount) amount_delta,SUM(COALESCE(tax_amount,0)) tax_delta
                FROM transaction_allocations
                WHERE status='active' AND allocation_type='extra' AND COALESCE(extra_was_existing,0)=0
                  AND COALESCE(booking_total_delta,0)=0 AND amount>0
                GROUP BY booking_id
            ) x ON x.booking_id=b.id
            SET b.totalAmount=ROUND(COALESCE(b.totalAmount,0)+x.amount_delta,2),
                b.vatAmount=ROUND(COALESCE(b.vatAmount,0)+x.tax_delta,2),
                b.version=COALESCE(b.version,0)+1,b.updatedAt=CURRENT_TIMESTAMP,
                b.updatedBy='system-invariant-repair',b.updatedSource='migration-15.0'");
        $pdo->exec("UPDATE transaction_allocations
            SET booking_total_delta=amount
            WHERE status='active' AND allocation_type='extra' AND COALESCE(extra_was_existing,0)=0
              AND COALESCE(booking_total_delta,0)=0 AND amount>0");

        // Completed/cancelled records may never end before they start. Open-ended
        // active stays are excluded because same-day placeholder checkout is allowed.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'booking-date',id,JSON_OBJECT('status',status,'isOpenEnded',isOpenEnded,'checkIn',checkIn,'checkOut',checkOut,
                'actualCheckInAt',actualCheckInAt,'actualCheckOutAt',actualCheckOutAt,'checkoutDueAt',checkoutDueAt,
                'lateCheckoutStatus',lateCheckoutStatus,'lateCheckoutReason',lateCheckoutReason,
                'lateCheckoutFee',lateCheckoutFee,'lateCheckoutApprovedBy',lateCheckoutApprovedBy)
            FROM bookings
            WHERE ((COALESCE(isOpenEnded,0)=0 OR status IN ('completed','cancelled')) AND checkOut<=checkIn)
               OR (actualCheckInAt IS NOT NULL AND DATE(actualCheckInAt)<checkIn)
               OR (actualCheckOutAt IS NOT NULL AND actualCheckInAt IS NOT NULL AND actualCheckOutAt<actualCheckInAt)
               OR (status IN ('reserved','active') AND COALESCE(isOpenEnded,0)=1 AND (
                    checkoutDueAt IS NOT NULL OR COALESCE(lateCheckoutStatus,'none')<>'none'
                    OR COALESCE(lateCheckoutFee,0)<>0 OR lateCheckoutReason IS NOT NULL
                    OR lateCheckoutApprovedBy IS NOT NULL))")->execute([$version]);
        // Never guess booking dates/times. Same-date stays are valid short-time
        // records and migration 15.4 classifies them without changing the original
        // dates. Truly contradictory historical timestamps are surfaced for
        // operator review instead of being rewritten by migration code.
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues
            (id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_booking_date_',SUBSTRING(SHA2(id,256),1,32)),
                CASE WHEN checkOut<checkIn THEN 'booking_checkout_before_checkin' ELSE 'same_date_stay_review' END,
                'booking',id,CASE WHEN checkOut<checkIn THEN 'warning' ELSE 'info' END,
                CONCAT('status=',status,'; checkIn=',checkIn,'; checkOut=',checkOut,
                    '; source=',COALESCE(updatedSource,'unknown')),'open'
            FROM bookings WHERE checkOut<=checkIn");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues
            (id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_booking_actual_in_',SUBSTRING(SHA2(id,256),1,32)),
                'actual_checkin_before_scheduled_date','booking',id,'warning',
                CONCAT('checkIn=',checkIn,'; actualCheckInAt=',actualCheckInAt,
                    '; source=',COALESCE(updatedSource,'unknown')),'open'
            FROM bookings WHERE actualCheckInAt IS NOT NULL AND DATE(actualCheckInAt)<checkIn");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues
            (id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_booking_actual_out_',SUBSTRING(SHA2(id,256),1,32)),
                'actual_checkout_before_actual_checkin','booking',id,'warning',
                CONCAT('actualCheckInAt=',actualCheckInAt,'; actualCheckOutAt=',actualCheckOutAt,
                    '; source=',COALESCE(updatedSource,'unknown')),'open'
            FROM bookings WHERE actualCheckOutAt IS NOT NULL AND actualCheckInAt IS NOT NULL AND actualCheckOutAt<actualCheckInAt");

        // Active/reserved open-ended stays do not have a checkout deadline until
        // the operator explicitly closes or converts the stay. Legacy due/late
        // fields made them appear overdue and could trigger fees prematurely.
        $pdo->exec("UPDATE bookings
            SET checkoutDueAt=NULL,lateCheckoutStatus='none',lateCheckoutReason=NULL,
                lateCheckoutFee=0,lateCheckoutApprovedBy=NULL,
                version=COALESCE(version,0)+1,updatedAt=CURRENT_TIMESTAMP,
                updatedBy='system-invariant-repair',updatedSource='migration-15.0'
            WHERE status IN ('reserved','active') AND COALESCE(isOpenEnded,0)=1 AND (
                checkoutDueAt IS NOT NULL OR COALESCE(lateCheckoutStatus,'none')<>'none'
                OR COALESCE(lateCheckoutFee,0)<>0 OR lateCheckoutReason IS NOT NULL
                OR lateCheckoutApprovedBy IS NOT NULL)");

        // Orphan tasks cannot ever be completed by a real room workflow.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'housekeeping_task',h.id,JSON_OBJECT('room_number',h.room_number,'status',h.status,'notes',h.notes,
                'assigned_staff_id',h.assigned_staff_id,'created_at',h.created_at)
            FROM housekeeping_tasks h LEFT JOIN rooms r ON r.number=h.room_number
            WHERE r.number IS NULL AND h.status NOT IN ('ready','completed')")->execute([$version]);
        $pdo->exec("UPDATE housekeeping_tasks h LEFT JOIN rooms r ON r.number=h.room_number
            SET h.status='completed',h.completed_at=COALESCE(h.completed_at,CURRENT_TIMESTAMP),
                h.notes=CONCAT(COALESCE(h.notes,''),CASE WHEN COALESCE(h.notes,'')='' THEN '' ELSE '\n' END,
                    'Migrasi 15.0: task orphan ditutup karena nomor kamar tidak ada pada master rooms.'),
                h.last_action_by_staff_id='system-invariant-repair',h.completed_by_staff_id='system-invariant-repair',
                h.last_action_source='migration-15.0',h.updated_at=CURRENT_TIMESTAMP
            WHERE r.number IS NULL AND h.status NOT IN ('ready','completed')");

        // Custody is not occupancy. A physical key belongs to a guest only after
        // canonical key-issue writes room_key_events AND changes the booking.
        // Historical invariant repair used to infer possession from active booking,
        // silently pre-binding fresh guests and causing valid key-issue to fail.
        // Never erase an outstanding/missing key or pending smart-lock custody.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'room_access_control',rac.room_number,JSON_OBJECT(
                'access_mode',rac.access_mode,'physical_key_status',rac.physical_key_status,
                'current_booking_id',rac.current_booking_id,'physical_key_ref',rac.physical_key_ref,
                'smart_lock_enabled',rac.smart_lock_enabled,'smart_lock_device_id',rac.smart_lock_device_id)
            FROM room_access_control rac LEFT JOIN rooms r ON r.number=rac.room_number
            WHERE r.number IS NULL
               OR (rac.physical_key_status='secured' AND rac.current_booking_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.id=rac.current_booking_id
                       AND b.status='active' AND b.keyControlStatus='issued'))")->execute([$version]);
        // An orphan register containing an outstanding physical key is an incident,
        // NOT a row that a cleanup migration may silently delete.
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_orphan_key_',SUBSTRING(SHA2(rac.room_number,256),1,32)),
                'orphan_outstanding_room_key','room_access_control',rac.room_number,'critical',
                CONCAT('status=',rac.physical_key_status,'; booking=',COALESCE(rac.current_booking_id,'NULL')),'open'
            FROM room_access_control rac LEFT JOIN rooms r ON r.number=rac.room_number
            WHERE r.number IS NULL AND (rac.physical_key_status<>'secured' OR rac.current_booking_id IS NOT NULL)");
        $pdo->exec("DELETE rac FROM room_access_control rac LEFT JOIN rooms r ON r.number=rac.room_number
            WHERE r.number IS NULL AND rac.physical_key_status='secured' AND rac.current_booking_id IS NULL");
        // Newly created control registers begin with an unissued, unbound key.
        // Existing booking access cannot be reconstructed from a guessed JOIN.
        $pdo->exec("INSERT IGNORE INTO room_access_control
            (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,last_event_at,updated_by,updated_at)
            SELECT r.number,COALESCE(NULLIF(b.accessMode,''),'physical'),CONCAT('KEY-',r.number),
                CASE WHEN b.keyControlStatus IN ('issued','missing','override','pending_smart_issue','pending_smart_revoke')
                    THEN 'override' ELSE 'secured' END,
                NULL,CURRENT_TIMESTAMP,'system-invariant-repair',CURRENT_TIMESTAMP
            FROM rooms r LEFT JOIN bookings b ON b.id=(
                SELECT b2.id FROM bookings b2 WHERE b2.roomNumber=r.number AND b2.status='active'
                ORDER BY COALESCE(b2.actualCheckInAt,b2.createdAt) DESC,b2.id DESC LIMIT 1
            )");
        // Never rewrite physical_key_status to 'issued' or 'secured' based solely
        // on occupancy/booking state. Flag contradictions for an audited operator.
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_custody_',SUBSTRING(SHA2(rac.room_number,256),1,32)),
                'room_key_custody_mismatch','room_access_control',rac.room_number,'warning',
                CONCAT('keyStatus=',rac.physical_key_status,'; holder=',COALESCE(rac.current_booking_id,'NULL'),
                       '; activeBooking=',COALESCE(b.id,'NULL'),'; bookingKeyStatus=',COALESCE(b.keyControlStatus,'NULL')),'open'
            FROM room_access_control rac LEFT JOIN bookings b ON b.id=(
                SELECT b2.id FROM bookings b2 WHERE b2.roomNumber=rac.room_number AND b2.status='active'
                ORDER BY COALESCE(b2.actualCheckInAt,b2.createdAt) DESC,b2.id DESC LIMIT 1
            )
            WHERE (rac.current_booking_id IS NOT NULL AND (b.id IS NULL OR rac.current_booking_id<>b.id
                OR COALESCE(b.keyControlStatus,'not_issued') NOT IN ('issued','pending_smart_issue','pending_smart_revoke')))
               OR (rac.physical_key_status IN ('issued','override') AND (b.id IS NULL OR b.keyControlStatus<>'issued'))
               OR (b.keyControlStatus IN ('issued','missing','override','pending_smart_issue','pending_smart_revoke')
                   AND rac.current_booking_id IS NULL)");

        // Repair actor identity that legacy allocation logging incorrectly filled
        // with booking ID/guest name instead of the staff who performed the action.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'activity_log',l.id,JSON_OBJECT('staff_id',l.staff_id,'staff_name',l.staff_name,'action_type',l.action_type,'description',l.description)
            FROM activity_logs l
            WHERE l.action_type IN ('allocate_manual_transaction','void_transaction_allocation')
              AND (l.staff_id LIKE 'b\\_%' OR l.staff_id IS NULL OR l.staff_id='')")->execute([$version]);
        $pdo->exec("UPDATE activity_logs l
            JOIN transaction_allocations a ON l.action_type='allocate_manual_transaction'
             AND l.description LIKE CONCAT('%',a.transaction_id,'%') AND l.description LIKE CONCAT('%',a.booking_id,'%')
            LEFT JOIN staff s ON s.id=a.created_by
            SET l.staff_id=a.created_by,l.staff_name=COALESCE(s.name,CONCAT('Staf ',a.created_by))
            WHERE l.staff_id LIKE 'b\\_%' OR l.staff_id IS NULL OR l.staff_id=''");
        $pdo->exec("UPDATE activity_logs l
            JOIN transaction_allocations a ON l.action_type='void_transaction_allocation'
             AND l.description LIKE CONCAT('%',a.id,'%')
            LEFT JOIN staff s ON s.id=a.voided_by
            SET l.staff_id=a.voided_by,l.staff_name=COALESCE(s.name,CONCAT('Staf ',a.voided_by))
            WHERE (l.staff_id LIKE 'b\\_%' OR l.staff_id IS NULL OR l.staff_id='') AND a.voided_by IS NOT NULL");

        // Legacy heartbeat requests were successful telemetry, but PHP 8.4 receipt
        // finalization left them uncertain/processing. Reconcile only rows with a
        // matching successful audit payload; business mutations are never guessed.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT DISTINCT ?,'request_operation_receipt',r.operation_id,JSON_OBJECT('status',r.status,'http_status',r.http_status,
                'error_message',r.error_message,'action',r.action,'updated_at',r.updated_at)
            FROM request_operation_receipts r JOIN audit_logs a ON a.operation_id=r.operation_id
            WHERE r.action='operations-center' AND r.status IN ('processing','uncertain','failed')
              AND a.outcome='success' AND a.action='POST operations-center'
              AND a.new_data LIKE '%\"command\":\"device-heartbeat\"%'")->execute([$version]);
        $pdo->exec("UPDATE request_operation_receipts r JOIN audit_logs a ON a.operation_id=r.operation_id
            SET r.status='completed',r.http_status=200,
                r.response_body='{\"success\":true,\"reconciled\":true,\"source\":\"legacy-device-heartbeat\"}',
                r.error_message=NULL,r.completed_at=COALESCE(r.completed_at,a.created_at),r.updated_at=CURRENT_TIMESTAMP
            WHERE r.action='operations-center' AND r.status IN ('processing','uncertain','failed')
              AND a.outcome='success' AND a.action='POST operations-center'
              AND a.new_data LIKE '%\"command\":\"device-heartbeat\"%'");

        // The shutdown audit is written after handler classification and receipt
        // finalization. Therefore an exact successful MUTATION ATTEMPT record with
        // the same operation_id, HTTP method, and action is durable proof that the
        // business handler completed even when the legacy receipt stayed uncertain.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'request_operation_receipt',r.operation_id,JSON_OBJECT('status',r.status,'http_status',r.http_status,
                'error_message',r.error_message,'action',r.action,'updated_at',r.updated_at)
            FROM request_operation_receipts r JOIN audit_logs a ON a.operation_id=r.operation_id
            WHERE r.status IN ('processing','uncertain','failed') AND a.outcome='success'
              AND a.action=CONCAT('MUTATION ATTEMPT ',UPPER(r.http_method),' ',r.action)")->execute([$version]);
        $pdo->exec("UPDATE request_operation_receipts r JOIN audit_logs a ON a.operation_id=r.operation_id
            SET r.status='completed',r.http_status=CASE WHEN COALESCE(r.http_status,0) BETWEEN 200 AND 399 THEN r.http_status ELSE 200 END,
                r.response_body=COALESCE(r.response_body,JSON_OBJECT('success',TRUE,'reconciled',TRUE,
                    'operationId',r.operation_id,'refreshRequired',TRUE)),
                r.error_message=NULL,r.completed_at=COALESCE(r.completed_at,a.created_at,CURRENT_TIMESTAMP),r.updated_at=CURRENT_TIMESTAMP
            WHERE r.status IN ('processing','uncertain','failed') AND a.outcome='success'
              AND a.action=CONCAT('MUTATION ATTEMPT ',UPPER(r.http_method),' ',r.action)");

        // Public reservation rows carry the exact same operation_id, providing
        // durable evidence that the request was accepted before receipt finalization
        // failed on PHP 8.4. Other business mutations are never guessed.
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'request_operation_receipt',r.operation_id,JSON_OBJECT('status',r.status,'http_status',r.http_status,
                'error_message',r.error_message,'action',r.action,'updated_at',r.updated_at)
            FROM request_operation_receipts r JOIN public_reservation_requests p ON p.operation_id=r.operation_id
            WHERE r.action='public-reservation-request' AND r.status IN ('processing','uncertain','failed')")->execute([$version]);
        $pdo->exec("UPDATE request_operation_receipts r JOIN public_reservation_requests p ON p.operation_id=r.operation_id
            SET r.status='completed',r.http_status=200,
                r.response_body=COALESCE(r.response_body,JSON_OBJECT('success',TRUE,'requestId',p.public_request_id,
                    'status','pending_review','reconciled',TRUE)),
                r.error_message=NULL,r.completed_at=COALESCE(r.completed_at,p.created_at,CURRENT_TIMESTAMP),r.updated_at=CURRENT_TIMESTAMP
            WHERE r.action='public-reservation-request' AND r.status IN ('processing','uncertain','failed')");

        // Keep unresolved non-heartbeat receipts visible for explicit reconciliation.
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_receipt_',SUBSTRING(SHA2(r.operation_id,256),1,32)),'unresolved_operation_receipt',
                   'request_operation_receipt',r.operation_id,'warning',
                   CONCAT('action=',r.action,'; status=',r.status,'; http_status=',COALESCE(r.http_status,'NULL')),'open'
            FROM request_operation_receipts r
            WHERE r.status IN ('processing','uncertain')
              AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.operation_id=r.operation_id AND a.outcome='success'
                  AND a.action='POST operations-center' AND a.new_data LIKE '%\"command\":\"device-heartbeat\"%')
              AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.operation_id=r.operation_id AND a.outcome='success'
                  AND a.action=CONCAT('MUTATION ATTEMPT ',UPPER(r.http_method),' ',r.action))
              AND NOT EXISTS (SELECT 1 FROM public_reservation_requests p WHERE p.operation_id=r.operation_id)");

        $systemActor=['id'=>'system-invariant-repair','name'=>'System Invariant Repair','role'=>'admin'];
        foreach($affectedBookingIds as $affectedBookingId){
            recalculateBookingFinancials($pdo,$affectedBookingId,true);
            assertBookingLedgerInvariant($pdo,$affectedBookingId,$systemActor,'migration-15.0',false);
        }

        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Repair booking/receipt/date/access/session/operation invariants, orphan housekeeping, audit actors, and evidenced legacy receipts',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $failureMessage=clientExceptionMessage('[invariant repair 15.0] migration failed',$e);
        error_log($failureMessage);
        // Jangan menandai migration sebagai berhasil. Simpan issue kritis di luar
        // transaksi agar Admin dapat melihat bahwa source 15.0 belum aman dipakai
        // untuk mutasi keuangan sampai akar kegagalan migration diselesaikan.
        try{
            $issueId='issue_migration_150_'.substr(hash('sha256',$version),0,24);
            $stmtIssue=$pdo->prepare("INSERT INTO data_integrity_issues
                (id,issue_type,entity_type,entity_id,severity,details,status,detected_at)
                VALUES (?,'migration_failure','schema_migration',?,'critical',?,'open',CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE severity='critical',details=VALUES(details),status='open',resolved_at=NULL,resolution_note=NULL,detected_at=CURRENT_TIMESTAMP");
            $stmtIssue->execute([$issueId,$version,substr($failureMessage,0,4000)]);
        }catch(Throwable $issueError){
            error_log(clientExceptionMessage('[invariant repair 15.0] failed to persist migration issue',$issueError));
        }
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_invariant_repair_150')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.15.1: independently verified audit follow-up; no guessed business state. */
function runRc41015151IndependentRuntimeAudit(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.1-independent-runtime-audit.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_independent_runtime_audit_151',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();
        $pdo->prepare("INSERT IGNORE INTO invariant_repair_snapshots(repair_version,entity_type,entity_id,before_data)
            SELECT ?,'activity_log_salary_actor',l.id,JSON_OBJECT('staff_id',l.staff_id,'staff_name',l.staff_name,
                'action_type',l.action_type,'description',l.description,'timestamp',l.timestamp)
            FROM activity_logs l
            WHERE l.action_type IN ('cancel_salary_payment','correct_salary_payment')
              AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.id=l.staff_id)
              AND EXISTS (SELECT 1 FROM audit_logs a WHERE a.created_at=l.timestamp AND a.outcome='success'
                  AND a.action='POST salary-payment-correction' AND a.staff_id IS NOT NULL AND a.staff_id<>'')")->execute([$version]);
        $pdo->exec("UPDATE activity_logs l JOIN audit_logs a ON a.created_at=l.timestamp
            AND a.outcome='success' AND a.action='POST salary-payment-correction'
            SET l.staff_id=a.staff_id,l.staff_name=a.staff_name
            WHERE l.action_type IN ('cancel_salary_payment','correct_salary_payment')
              AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.id=l.staff_id)");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_zero_salary_',SUBSTRING(SHA2(t.id,256),1,32)),'zero_paid_salary_transaction',
                   'transaction',t.id,'warning',CONCAT('sourceSlip=',COALESCE(t.sourceEntityId,'NULL'),'; date=',t.`date`,
                   '; amount=',t.amount,'; actor=',COALESCE(t.updatedBy,t.createdBy,'unknown')),'open'
            FROM transactions t WHERE t.transactionKind='salary_payment' AND t.amount<=0");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_outbox_',SUBSTRING(SHA2(o.id,256),1,32)),'communication_outbox_overdue',
                   'communication_outbox',o.id,'warning',CONCAT('channel=',COALESCE(o.channel_id,'NULL'),
                   '; scheduled=',COALESCE(o.scheduled_at,'NULL'),'; attempts=',COALESCE(o.attempts,0),
                   '; last_error=',COALESCE(o.last_error,'')),'open'
            FROM communication_outbox o
            WHERE o.status='pending' AND COALESCE(o.scheduled_at,o.created_at)<=CURRENT_TIMESTAMP");
        $expectedRelease=defined('TAMASYA_RELEASE') ? (string)TAMASYA_RELEASE : '';
        $deviceIssueStmt=$pdo->prepare("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_device_version_',SUBSTRING(SHA2(d.device_id,256),1,32)),'stale_device_app_version',
                   'sync_device',d.device_id,'warning',CONCAT('expected=',?,'; appVersion=',COALESCE(d.app_version,'NULL'),
                   '; status=',COALESCE(d.status,'NULL'),'; pending=',COALESCE(d.pending_count,0),
                   '; conflicts=',COALESCE(d.conflict_count,0)),'open'
            FROM sync_devices d WHERE d.status<>'disabled' AND COALESCE(d.app_version,'')<>''
              AND COALESCE(d.app_version,'')<>?");
        $deviceIssueStmt->execute([$expectedRelease,$expectedRelease]);
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_cluster_members_',SUBSTRING(SHA2(s.cluster_id,256),1,32)),'cluster_single_member',
                   'node_cluster_state',s.cluster_id,'warning',
                   CONCAT('memberCount=',(SELECT COUNT(*) FROM node_cluster_members m WHERE m.cluster_id=s.cluster_id),
                          '; primary=',COALESCE(s.current_primary_node_id,'NULL')),'open'
            FROM node_cluster_state s
            WHERE (SELECT COUNT(*) FROM node_cluster_members m WHERE m.cluster_id=s.cluster_id)<2");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_cluster_lease_',SUBSTRING(SHA2(s.cluster_id,256),1,32)),'cluster_lease_expired',
                   'node_cluster_state',s.cluster_id,'warning',
                   CONCAT('primary=',COALESCE(s.current_primary_node_id,'NULL'),'; leaseExpires=',COALESCE(s.lease_expires_at,'NULL')),'open'
            FROM node_cluster_state s
            WHERE s.lease_expires_at IS NOT NULL AND s.lease_expires_at<UTC_TIMESTAMP()");
        $pdo->exec("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status)
            SELECT CONCAT('issue_cluster_member_',SUBSTRING(SHA2(m.node_id,256),1,32)),'cluster_member_isolated',
                   'node_cluster_member',m.node_id,'warning',
                   CONCAT('cluster=',COALESCE(m.cluster_id,'NULL'),'; role=',COALESCE(m.effective_role,'NULL'),
                          '; public=',COALESCE(m.public_url,'NULL'),'; peer=',COALESCE(m.peer_url,'NULL')),'open'
            FROM node_cluster_members m
            WHERE m.member_status='isolated'
               OR (COALESCE(m.public_url,'')<>'' AND TRIM(TRAILING '/' FROM m.public_url)=TRIM(TRAILING '/' FROM COALESCE(m.peer_url,'')))");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at)
            VALUES (?,'Repair evidenced salary audit actors and surface zero payroll, overdue outbox, stale device-version, and inactive cluster-topology issues without fabricating business state',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[independent runtime audit 15.1] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_independent_runtime_audit_151')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.2: category/subcategory catalog uses immutable category IDs. */
function runRc41015152CategoryCatalogRepair(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.2-category-catalog.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_category_catalog_152',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;

        ensureColumnExists($pdo,'categories','system_key','VARCHAR(80) DEFAULT NULL');
        ensureColumnExists($pdo,'categories','is_system','TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo,'categories','is_active','TINYINT(1) NOT NULL DEFAULT 1');
        ensureColumnExists($pdo,'categories','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'categories','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'subcategories','category_id','VARCHAR(50) DEFAULT NULL');
        ensureColumnExists($pdo,'subcategories','system_key','VARCHAR(80) DEFAULT NULL');
        ensureColumnExists($pdo,'subcategories','is_system','TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo,'subcategories','is_active','TINYINT(1) NOT NULL DEFAULT 1');
        ensureColumnExists($pdo,'subcategories','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'subcategories','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $pdo->exec("CREATE TABLE IF NOT EXISTS category_catalog_repair_archive (
            archive_id VARCHAR(100) PRIMARY KEY,
            entity_type VARCHAR(30) NOT NULL,
            entity_id VARCHAR(80) NOT NULL,
            reason VARCHAR(100) NOT NULL,
            row_json LONGTEXT NOT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category_archive_entity(entity_type,entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Old uniqueness by category_name makes two categories with the same visible name share one subtree.
        try{
            $idx=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subcategories' AND INDEX_NAME='uq_subcategory'");
            if((int)($idx?$idx->fetchColumn():0)>0)$pdo->exec("ALTER TABLE subcategories DROP INDEX uq_subcategory");
        }catch(Throwable $ignored){}

        $ensureCategory=function(string $preferredId,string $name,string $type,string $systemKey)use($pdo):array{
            $stmt=$pdo->prepare("SELECT * FROM categories WHERE system_key=? OR id=? OR (LOWER(TRIM(name))=LOWER(TRIM(?)) AND type=?) ORDER BY CASE WHEN system_key=? THEN 0 WHEN id=? THEN 1 ELSE 2 END LIMIT 1 FOR UPDATE");
            $stmt->execute([$systemKey,$preferredId,$name,$type,$systemKey,$preferredId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$row){
                $pdo->prepare("INSERT INTO categories(id,name,type,system_key,is_system,is_active) VALUES (?,?,?,?,1,1)")->execute([$preferredId,$name,$type,$systemKey]);
                return ['id'=>$preferredId,'name'=>$name,'type'=>$type];
            }
            $pdo->prepare("UPDATE categories SET system_key=?,is_system=1,is_active=1 WHERE id=?")->execute([$systemKey,$row['id']]);
            return ['id'=>(string)$row['id'],'name'=>(string)$row['name'],'type'=>(string)$row['type']];
        };

        $roomCategory=$ensureCategory('cat_room_sale','Sewa Kamar','income','room_rental');
        $extraCategory=$ensureCategory('cat_layanan_extra_new','Layanan Extra','income','extra_service');

        // Backfill only unambiguous legacy names first.
        $pdo->exec("UPDATE subcategories s
            JOIN categories c ON LOWER(TRIM(c.name))=LOWER(TRIM(s.category_name)) AND c.is_active=1
            JOIN (SELECT LOWER(TRIM(name)) normalized_name FROM categories WHERE is_active=1 GROUP BY LOWER(TRIM(name)) HAVING COUNT(*)=1) u
              ON u.normalized_name=LOWER(TRIM(c.name))
            SET s.category_id=c.id,s.category_name=c.name
            WHERE (s.category_id IS NULL OR TRIM(s.category_id)='')");

        // Ambiguous legacy names are cloned per category ID, then the ambiguous source row is archived and removed.
        $legacy=$pdo->query("SELECT * FROM subcategories WHERE category_id IS NULL OR TRIM(category_id)='' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($legacy as $row){
            $match=$pdo->prepare("SELECT id,name,type FROM categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND is_active=1 ORDER BY type,id");
            $match->execute([(string)$row['category_name']]);
            $cats=$match->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(count($cats)>1){
                foreach($cats as $cat){
                    $cloneId='sub_mig_'.substr(hash('sha256',(string)$row['id'].'|'.(string)$cat['id']),0,32);
                    $pdo->prepare("INSERT INTO subcategories(id,category_id,category_name,name,system_key,is_system,is_active)
                        VALUES (?,?,?,?,NULL,0,?) ON DUPLICATE KEY UPDATE category_name=VALUES(category_name),is_active=GREATEST(is_active,VALUES(is_active))")
                        ->execute([$cloneId,$cat['id'],$cat['name'],$row['name'],(int)($row['is_active']??1)]);
                }
                $archiveId='cat_archive_'.substr(hash('sha256','ambiguous|'.(string)$row['id']),0,48);
                $pdo->prepare("INSERT IGNORE INTO category_catalog_repair_archive(archive_id,entity_type,entity_id,reason,row_json) VALUES (?,?,?,?,?)")
                    ->execute([$archiveId,'subcategory',(string)$row['id'],'ambiguous_category_name',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $pdo->prepare("UPDATE subcategories SET is_active=0 WHERE id=?")->execute([$row['id']]);
            }elseif(count($cats)===1){
                $pdo->prepare("UPDATE subcategories SET category_id=?,category_name=? WHERE id=?")->execute([$cats[0]['id'],$cats[0]['name'],$row['id']]);
            }else{
                $archiveId='cat_archive_'.substr(hash('sha256','orphan|'.(string)$row['id']),0,48);
                $pdo->prepare("INSERT IGNORE INTO category_catalog_repair_archive(archive_id,entity_type,entity_id,reason,row_json) VALUES (?,?,?,?,?)")
                    ->execute([$archiveId,'subcategory',(string)$row['id'],'category_not_found',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $pdo->prepare("UPDATE subcategories SET is_active=0 WHERE id=?")->execute([$row['id']]);
                try{$pdo->prepare("INSERT IGNORE INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status) VALUES (?,'orphan_finance_subcategory','subcategory',?,'warning',?,'open')")
                    ->execute(['issue_subcat_'.substr(hash('sha256',(string)$row['id']),0,32),(string)$row['id'],'category_name='.(string)$row['category_name'].'; name='.(string)$row['name']]);}catch(Throwable $ignored){}
            }
        }

        // Remove exact duplicates safely; transactions retain category/subcategory text for historical reporting.
        $duplicates=$pdo->query("SELECT d.* FROM subcategories d JOIN subcategories k
            ON d.category_id=k.category_id AND LOWER(TRIM(d.name))=LOWER(TRIM(k.name)) AND d.id>k.id
            WHERE d.category_id IS NOT NULL AND TRIM(d.category_id)<>''")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($duplicates as $row){
            $archiveId='cat_archive_'.substr(hash('sha256','duplicate|'.(string)$row['id']),0,48);
            $pdo->prepare("INSERT IGNORE INTO category_catalog_repair_archive(archive_id,entity_type,entity_id,reason,row_json) VALUES (?,?,?,?,?)")
                ->execute([$archiveId,'subcategory',(string)$row['id'],'duplicate_category_id_name',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $pdo->prepare("UPDATE subcategories SET category_id=NULL,is_active=0 WHERE id=?")->execute([$row['id']]);
        }

        $ensureSub=function(array $category,string $preferredId,string $name,string $systemKey)use($pdo):void{
            $stmt=$pdo->prepare("SELECT * FROM subcategories WHERE system_key=? OR id=? OR (category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?))) ORDER BY CASE WHEN system_key=? THEN 0 WHEN id=? THEN 1 ELSE 2 END LIMIT 1 FOR UPDATE");
            $stmt->execute([$systemKey,$preferredId,$category['id'],$name,$systemKey,$preferredId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$row){
                $pdo->prepare("INSERT INTO subcategories(id,category_id,category_name,name,system_key,is_system,is_active) VALUES (?,?,?,?,?,1,1)")
                    ->execute([$preferredId,$category['id'],$category['name'],$name,$systemKey]);
            }else{
                $pdo->prepare("UPDATE subcategories SET category_id=?,category_name=?,system_key=?,is_system=1,is_active=1 WHERE id=?")
                    ->execute([$category['id'],$category['name'],$systemKey,$row['id']]);
            }
        };
        foreach([
            ['sub_room_daily','Harian','room_daily'],['sub_room_weekly','Mingguan','room_weekly'],
            ['sub_room_extension','Perpanjangan Kamar','room_extension'],['sub_room_transfer','Pindah Kamar','room_transfer'],
            ['sub_room_extra_bed','Tambahan Bed','room_extra_bed']
        ] as $def)$ensureSub($roomCategory,$def[0],$def[1],$def[2]);
        foreach([
            ['sub_extra_general','Umum','extra_general'],['sub_extra_breakfast','Sarapan Pagi','extra_breakfast'],
            ['sub_extra_minibar','Mini Bar','extra_minibar'],['sub_extra_laundry','Laundry','extra_laundry'],
            ['sub_extra_bed','Extra Bed','extra_bed'],['sub_extra_other','Lainnya','extra_other']
        ] as $def)$ensureSub($extraCategory,$def[0],$def[1],$def[2]);

        $pdo->exec("UPDATE subcategories s JOIN categories c ON c.id=s.category_id SET s.category_name=c.name WHERE s.category_id IS NOT NULL");
        try{$pdo->exec("CREATE UNIQUE INDEX uq_category_system_key ON categories(system_key)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE INDEX idx_categories_active_type ON categories(is_active,type,name)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE UNIQUE INDEX uq_subcategory_category_name ON subcategories(category_id,name)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE UNIQUE INDEX uq_subcategory_system_key ON subcategories(system_key)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE INDEX idx_subcategories_active ON subcategories(category_id,is_active,name)");}catch(Throwable $ignored){}
        try{$pdo->exec("CREATE INDEX idx_subcategories_legacy_name ON subcategories(category_name,name)");}catch(Throwable $ignored){}

        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Bind subcategories to immutable category IDs, protect system masters, archive ambiguous/orphan rows, and stop per-request hidden seeding',CURRENT_TIMESTAMP)")
            ->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[category catalog 15.2] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_category_catalog_152')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.15.3: Sewa Kamar subcategories are the canonical room-type catalog. */
function runRc41015153RoomTypeSubcategoryAlignment(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.3-room-type-subcategory.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_room_type_subcategory_153',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();

        $stmtCategory=$pdo->query("SELECT * FROM categories WHERE system_key='room_rental' OR id='cat_room_sale' ORDER BY CASE WHEN system_key='room_rental' THEN 0 ELSE 1 END LIMIT 1 FOR UPDATE");
        $roomCategory=$stmtCategory?$stmtCategory->fetch(PDO::FETCH_ASSOC):null;
        if(!$roomCategory){
            $pdo->prepare("INSERT INTO categories(id,name,type,system_key,is_system,is_active) VALUES ('cat_room_sale','Sewa Kamar','income','room_rental',1,1)")->execute();
            $roomCategory=['id'=>'cat_room_sale','name'=>'Sewa Kamar'];
        }else{
            $pdo->prepare("UPDATE categories SET name='Sewa Kamar',type='income',system_key='room_rental',is_system=1,is_active=1 WHERE id=?")
                ->execute([$roomCategory['id']]);
            $roomCategory['name']='Sewa Kamar';
        }

        $workflowKeys=['room_daily','room_weekly','room_extension','room_transfer','room_extra_bed'];
        $placeholders=implode(',',array_fill(0,count($workflowKeys),'?'));
        $stmtLegacy=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND system_key IN ($placeholders) FOR UPDATE");
        $stmtLegacy->execute(array_merge([(string)$roomCategory['id']],$workflowKeys));
        foreach($stmtLegacy->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $archiveId='cat_archive_'.substr(hash('sha256','room_workflow_not_type|'.(string)$row['id']),0,48);
            $pdo->prepare("INSERT IGNORE INTO category_catalog_repair_archive(archive_id,entity_type,entity_id,reason,row_json) VALUES (?,?,?,?,?)")
                ->execute([$archiveId,'subcategory',(string)$row['id'],'room_workflow_label_is_not_room_type',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
        $stmtDeactivate=$pdo->prepare("UPDATE subcategories SET is_active=0 WHERE category_id=? AND system_key IN ($placeholders)");
        $stmtDeactivate->execute(array_merge([(string)$roomCategory['id']],$workflowKeys));

        $typeRows=$pdo->query("SELECT DISTINCT TRIM(type) AS room_type FROM rooms WHERE TRIM(COALESCE(type,''))<>''
            UNION SELECT DISTINCT TRIM(roomType) AS room_type FROM bookings WHERE TRIM(COALESCE(roomType,''))<>''")
            ->fetchAll(PDO::FETCH_COLUMN)?:[];
        foreach($typeRows as $roomType){
            $roomType=trim((string)$roomType);
            if($roomType==='')continue;
            $stmtExisting=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
            $stmtExisting->execute([(string)$roomCategory['id'],$roomType]);
            $existing=$stmtExisting->fetch(PDO::FETCH_ASSOC)?:null;
            if($existing){
                $pdo->prepare("UPDATE subcategories SET category_name='Sewa Kamar',name=?,system_key=NULL,is_system=0,is_active=1 WHERE id=?")
                    ->execute([$roomType,$existing['id']]);
            }else{
                $id='sub_room_type_'.substr(hash('sha256',mb_strtolower($roomType,'UTF-8')),0,32);
                $pdo->prepare("INSERT INTO subcategories(id,category_id,category_name,name,system_key,is_system,is_active) VALUES (?,?, 'Sewa Kamar', ?, NULL,0,1)")
                    ->execute([$id,(string)$roomCategory['id'],$roomType]);
            }
        }

        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Make active Sewa Kamar subcategories the canonical room-type catalog and retire workflow labels from that catalog',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[room type subcategory 15.3] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_room_type_subcategory_153')");}catch(Throwable $ignored){}}
    }
}


/**
 * RC4.10.15.15.19: recover the finance category catalog without touching
 * historical transactions or the flexible-backfill workflow.
 *
 * Ledger rows keep category/subcategory labels as immutable snapshots, while
 * the cash-entry form reads active rows from categories/subcategories. A
 * restored legacy database may therefore contain valid expenses but no active
 * expense catalog. This one-time migration restores the standard masters and
 * recreates missing masters from ledger snapshots. It never rewrites the
 * transaction date, recordOrigin, backfill metadata, tax snapshot, or amount.
 */
function runRc410151519FinanceCatalogRecovery(PDO $pdo): void {
    // Revision .2 intentionally runs even if an earlier draft of this hotfix
    // was applied, because it avoids DDL inside a transaction and is safe/idempotent.
    $version='2026.08.rc4.10.15.15.19-finance-catalog-recovery.2';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_finance_catalog_151519_v2',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;

        // Schema alignment can issue CREATE/ALTER TABLE, which auto-commits in MySQL.
        // Complete it before opening the data-repair transaction.
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL,
            system_key VARCHAR(80) DEFAULT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_category_name_type(name,type),
            UNIQUE KEY uq_category_system_key(system_key),
            INDEX idx_categories_active_type(is_active,type,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS subcategories (
            id VARCHAR(50) PRIMARY KEY,
            category_id VARCHAR(50) DEFAULT NULL,
            category_name VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            system_key VARCHAR(80) DEFAULT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_subcategories_active(category_id,is_active,name),
            INDEX idx_subcategories_legacy_name(category_name,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        ensureColumnExists($pdo,'categories','system_key','VARCHAR(80) DEFAULT NULL');
        ensureColumnExists($pdo,'categories','is_system','TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo,'categories','is_active','TINYINT(1) NOT NULL DEFAULT 1');
        ensureColumnExists($pdo,'categories','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'categories','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'subcategories','category_id','VARCHAR(50) DEFAULT NULL');
        ensureColumnExists($pdo,'subcategories','system_key','VARCHAR(80) DEFAULT NULL');
        ensureColumnExists($pdo,'subcategories','is_system','TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo,'subcategories','is_active','TINYINT(1) NOT NULL DEFAULT 1');
        ensureColumnExists($pdo,'subcategories','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        ensureColumnExists($pdo,'subcategories','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $pdo->beginTransaction();

        $stableId=static function(string $prefix,string $value):string{
            $normalized=strtolower(trim($value));
            return $prefix.substr(hash('sha256',$normalized),0,30);
        };

        $ensureCategory=static function(
            PDO $pdo,
            string $name,
            string $type,
            ?string $preferredId,
            bool $forceActive,
            callable $stableId
        ):array{
            $name=trim($name);
            $type=strtolower(trim($type));
            $stmt=$pdo->prepare("SELECT * FROM categories
                WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND LOWER(TRIM(type))=?
                ORDER BY is_active DESC,id LIMIT 1 FOR UPDATE");
            $stmt->execute([$name,$type]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($row){
                if($forceActive && (int)($row['is_active']??1)!==1){
                    $pdo->prepare("UPDATE categories SET is_active=1 WHERE id=?")->execute([$row['id']]);
                }
                return [
                    'id'=>(string)$row['id'],
                    'name'=>(string)$row['name'],
                    'type'=>(string)$row['type'],
                    'isActive'=>$forceActive ? true : (int)($row['is_active']??1)===1,
                ];
            }

            $candidate=trim((string)$preferredId);
            if($candidate==='')$candidate=$stableId('cat_hist_',$type.'|'.$name);
            $idCheck=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE id=?");
            $idCheck->execute([$candidate]);
            if((int)$idCheck->fetchColumn()>0)$candidate=$stableId('cat_hist_',$type.'|'.$name.'|catalog');

            $pdo->prepare("INSERT INTO categories(id,name,type,is_system,is_active) VALUES (?,?,?,0,1)")
                ->execute([$candidate,$name,$type]);
            return ['id'=>$candidate,'name'=>$name,'type'=>$type,'isActive'=>true];
        };

        $ensureSubcategory=static function(
            PDO $pdo,
            array $category,
            string $name,
            ?string $preferredId,
            bool $forceActive,
            callable $stableId
        ):void{
            $name=trim($name);
            if($name==='')return;

            $stmt=$pdo->prepare("SELECT * FROM subcategories
                WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?))
                ORDER BY is_active DESC,id LIMIT 1 FOR UPDATE");
            $stmt->execute([$category['id'],$name]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($row){
                if($forceActive || (int)($row['is_active']??1)===1){
                    $pdo->prepare("UPDATE subcategories SET category_name=?,name=?,is_active=1 WHERE id=?")
                        ->execute([$category['name'],$name,$row['id']]);
                }
                return;
            }

            // Reuse a legacy unbound row only when the category name is unique
            // across active category types; names such as “Lain-lain” are ambiguous.
            $nameCount=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND is_active=1");
            $nameCount->execute([$category['name']]);
            if((int)$nameCount->fetchColumn()===1){
                $legacy=$pdo->prepare("SELECT * FROM subcategories
                    WHERE (category_id IS NULL OR TRIM(category_id)='')
                      AND LOWER(TRIM(category_name))=LOWER(TRIM(?))
                      AND LOWER(TRIM(name))=LOWER(TRIM(?))
                    ORDER BY is_active DESC,id LIMIT 1 FOR UPDATE");
                $legacy->execute([$category['name'],$name]);
                $legacyRow=$legacy->fetch(PDO::FETCH_ASSOC)?:null;
                if($legacyRow){
                    $pdo->prepare("UPDATE subcategories SET category_id=?,category_name=?,name=?,is_active=1 WHERE id=?")
                        ->execute([$category['id'],$category['name'],$name,$legacyRow['id']]);
                    return;
                }
            }

            $candidate=trim((string)$preferredId);
            if($candidate==='')$candidate=$stableId('sub_hist_',$category['id'].'|'.$name);
            $idCheck=$pdo->prepare("SELECT COUNT(*) FROM subcategories WHERE id=?");
            $idCheck->execute([$candidate]);
            if((int)$idCheck->fetchColumn()>0)$candidate=$stableId('sub_hist_',$category['id'].'|'.$name.'|catalog');

            $pdo->prepare("INSERT INTO subcategories(id,category_id,category_name,name,is_system,is_active)
                VALUES (?,?,?,?,0,1)")
                ->execute([$candidate,$category['id'],$category['name'],$name]);
        };

        // Restore the standard catalog used by the original finance workflow.
        // Preferred IDs preserve compatibility with older clients and reports.
        $defaults=[
            ['cat_gaji','Gaji Karyawan','expense',[
                ['sub_g1','Resepsionis'],['sub_g2','Cleaning Service'],['sub_g3','Keamanan']
            ]],
            ['cat_operasional','Listrik, Air & WiFi','expense',[
                ['sub_o1','Token Listrik'],['sub_o2','Tagihan Air PAM'],['sub_o3','Paket Internet WiFi']
            ]],
            ['cat_inventaris','Kebutuhan Kamar','expense',[
                ['sub_i1','Sprei & Selimut'],['sub_i2','Sabun & Shampo'],['sub_i3','Air Mineral Botol'],['sub_i4','Handuk & Tissue']
            ]],
            ['cat_lain_lain_ex','Lain-lain','expense',[
                ['sub_x1_ex','Biaya Tak Terduga']
            ]],
            ['cat_laundry','Laundry','income',[
                ['sub_l1','Kiloan'],['sub_l2','Dry Clean'],['sub_l3','Setrika']
            ]],
            ['cat_food_beverage','Makanan & Minuman','income',[
                ['sub_f1','Sarapan Pagi'],['sub_f2','Mini Bar'],['sub_f3','Room Service']
            ]],
            ['cat_lain_lain_in','Lain-lain','income',[
                ['sub_x1_in','Umum']
            ]],
        ];
        foreach($defaults as $definition){
            $category=$ensureCategory($pdo,$definition[1],$definition[2],$definition[0],true,$stableId);
            foreach($definition[3] as $subcategoryDefinition){
                $ensureSubcategory($pdo,$category,$subcategoryDefinition[1],$subcategoryDefinition[0],true,$stableId);
            }
        }

        // Reconstruct any additional category/subcategory that is actually used
        // by the ledger. This only restores the form catalog; transaction snapshots
        // and flexible historical/backfill metadata remain unchanged.
        $rows=$pdo->query("SELECT DISTINCT
                LOWER(TRIM(type)) AS tx_type,
                TRIM(category) AS category_name,
                TRIM(COALESCE(subcategory,'')) AS subcategory_name
            FROM transactions
            WHERE LOWER(TRIM(type)) IN ('income','expense')
              AND TRIM(COALESCE(category,''))<>''
            ORDER BY tx_type,category_name,subcategory_name")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $row){
            $type=(string)$row['tx_type'];
            $categoryName=trim((string)$row['category_name']);
            if($categoryName==='')continue;
            $category=$ensureCategory($pdo,$categoryName,$type,null,true,$stableId);
            $ensureSubcategory($pdo,$category,(string)$row['subcategory_name'],null,true,$stableId);
        }

        // Keep the legacy display name synchronized with the immutable parent ID.
        $pdo->exec("UPDATE subcategories s
            JOIN categories c ON c.id=s.category_id
            SET s.category_name=c.name
            WHERE s.category_id IS NOT NULL AND TRIM(s.category_id)<>''");

        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at)
            VALUES (?,'Restore finance category/subcategory masters from standard defaults and ledger snapshots without changing historical/backfill transactions',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[finance catalog recovery 15.19 v2] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_finance_catalog_151519_v2')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.15.4: same-day short-time stays and explicit historical-import shift exemption. */
/**
 * RC4.10.15.15.20: strict compatibility repair for the bookings projection.
 *
 * Older production databases may already contain migration markers from an
 * earlier build while still missing columns later used by hotel-data. This
 * migration verifies the real schema instead of trusting an old marker. It is
 * additive only and does not rewrite booking, transaction, tax, or backfill data.
 */
function runRc410151520BookingsReadCompatibilityRepair(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.20-bookings-read-compatibility.1';
    $definitions=[
        'roomCharge'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'extraCharge'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'discountAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'amountPaid'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'balanceDue'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'refundAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositRequired'=>"TINYINT(1) NOT NULL DEFAULT 0",
        'securityDepositRequiredAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositReceived'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositRefunded'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositForfeited'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositHeld'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'securityDepositStatus'=>"VARCHAR(30) NOT NULL DEFAULT 'not_required'",
        'financialClosureStatus'=>"VARCHAR(30) NOT NULL DEFAULT 'not_required'",
        'financialClosureBalance'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'financialClosureReason'=>"VARCHAR(255) NULL",
        'financialClosureAt'=>"DATETIME NULL",
        'financialClosureBy'=>"VARCHAR(50) NULL",
        'financialClosureSource'=>"VARCHAR(30) NULL",
        'financialClosureOperationId'=>"VARCHAR(100) NULL",
        'financialProjectionMode'=>"VARCHAR(30) NOT NULL DEFAULT 'live_ledger'",
        'financialProjectionLockedAt'=>"DATETIME NULL",
        'financialProjectionLockReason'=>"VARCHAR(255) NULL",
        'ktpPhoto'=>"LONGTEXT NULL",
        'extras'=>"LONGTEXT NULL",
        'bookingSource'=>"VARCHAR(255) NOT NULL DEFAULT 'Direct'",
        'isSplitPayment'=>"TINYINT(1) NOT NULL DEFAULT 0",
        'splitCashAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'splitTransferAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'splitTransferBankAccountId'=>"VARCHAR(50) NULL",
        'downPaymentAmount'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'downPaymentMethod'=>"VARCHAR(50) NULL",
        'downPaymentBankAccountId'=>"VARCHAR(50) NULL",
        'downPaymentDate'=>"VARCHAR(50) NULL",
        'isOpenEnded'=>"TINYINT(1) NOT NULL DEFAULT 0",
        'stayMode'=>"VARCHAR(30) NOT NULL DEFAULT 'overnight'",
        'scheduledCheckInAt'=>"DATETIME NULL",
        'scheduledCheckOutAt'=>"DATETIME NULL",
        'actualCheckInAt'=>"DATETIME NULL",
        'actualCheckOutAt'=>"DATETIME NULL",
        'checkoutDueAt'=>"DATETIME NULL",
        'lateCheckoutStatus'=>"VARCHAR(30) NOT NULL DEFAULT 'none'",
        'lateCheckoutReason'=>"TEXT NULL",
        'lateCheckoutFee'=>"DECIMAL(15,2) NOT NULL DEFAULT 0",
        'lateCheckoutApprovedBy'=>"VARCHAR(50) NULL",
        'keyControlStatus'=>"VARCHAR(30) NOT NULL DEFAULT 'not_issued'",
        'accessMode'=>"VARCHAR(20) NOT NULL DEFAULT 'physical'",
        'keyIssuedAt'=>"DATETIME NULL",
        'keyIssuedBy'=>"VARCHAR(50) NULL",
        'keyReturnedAt'=>"DATETIME NULL",
        'keyReturnedBy'=>"VARCHAR(50) NULL",
        'version'=>"INT NOT NULL DEFAULT 1",
        'updatedAt'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'updatedBy'=>"VARCHAR(100) NULL",
        'updatedSource'=>"VARCHAR(30) NULL"
    ];
    $missing=[];
    foreach($definitions as $column=>$definition){if(rc410ColumnMeta($pdo,'bookings',$column)===null)$missing[]=$column;}
    if(!$missing&&schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_booking_read_151520',8)")->fetchColumn()===1;
        if(!$lock)return;
        foreach($definitions as $column=>$definition){
            if(rc410ColumnMeta($pdo,'bookings',$column)===null)rc410EnsureColumn($pdo,'bookings',$column,$definition);
        }
        $stillMissing=[];
        foreach(array_keys($definitions) as $column){if(rc410ColumnMeta($pdo,'bookings',$column)===null)$stillMissing[]=$column;}
        if($stillMissing)throw new RuntimeException('Kolom bookings belum berhasil diselaraskan: '.implode(',',$stillMissing));
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at)
            VALUES (?,'Strict additive bookings projection compatibility repair; preserves historical and tax data',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description),applied_at=VALUES(applied_at)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[bookings read compatibility 15.20] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_booking_read_151520')");}catch(Throwable $ignored){}}
    }
}

function runRc41015154ShortTimeHistoricalImport(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.4-short-time-historical-import.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_short_time_historical_154',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        ensureColumnExists($pdo,'bookings','stayMode',"VARCHAR(30) NOT NULL DEFAULT 'overnight'");
        ensureColumnExists($pdo,'bookings','scheduledCheckInAt','DATETIME NULL');
        ensureColumnExists($pdo,'bookings','scheduledCheckOutAt','DATETIME NULL');
        ensureColumnExists($pdo,'transactions','recordOrigin',"VARCHAR(30) NOT NULL DEFAULT 'live_operation'");
        ensureColumnExists($pdo,'transactions','shiftExempt','TINYINT(1) NOT NULL DEFAULT 0');
        ensureColumnExists($pdo,'transactions','shiftExemptionReason','VARCHAR(255) NULL');
        ensureColumnExists($pdo,'transactions','importBatchId','VARCHAR(100) NULL');
        $pdo->exec("UPDATE bookings SET stayMode=CASE WHEN COALESCE(isOpenEnded,0)=1 THEN 'open_ended' WHEN checkOut=checkIn THEN 'short_time' ELSE 'overnight' END WHERE stayMode IS NULL OR stayMode='' OR stayMode NOT IN ('overnight','short_time','open_ended')");
        $pdo->exec("DELETE i FROM data_integrity_issues i JOIN transactions t ON t.id=i.entity_id WHERE i.issue_type='unassigned_required_shift_transaction' AND t.recordOrigin='historical_import' AND COALESCE(t.shiftExempt,0)=1");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Allow same-day short-time stays with timestamp windows and distinguish historical backfill from live shift operations',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[short-time historical import 15.4] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_short_time_historical_154')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.5: guest security deposit liability ledger, separate from room payment/DP. */
function runRc41015155GuestSecurityDeposit(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.5-guest-security-deposit.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_guest_security_deposit_155',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        foreach([
            ['securityDepositRequired',"TINYINT(1) NOT NULL DEFAULT 0"],
            ['securityDepositRequiredAmount',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['securityDepositReceived',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['securityDepositRefunded',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['securityDepositForfeited',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['securityDepositHeld',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['securityDepositStatus',"VARCHAR(30) NOT NULL DEFAULT 'not_required'"]
        ] as $column)ensureColumnExists($pdo,'bookings',$column[0],$column[1]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS guest_security_deposits (
            id VARCHAR(50) PRIMARY KEY,
            booking_id VARCHAR(50) NOT NULL,
            required_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            received_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            refunded_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            forfeited_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            held_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'not_required',
            version INT NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NULL,
            updated_by VARCHAR(50) NULL,
            updated_source VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_guest_security_deposit_booking(booking_id),
            INDEX idx_guest_security_deposit_status(status,held_balance)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS guest_security_deposit_ledger (
            id VARCHAR(50) PRIMARY KEY,
            operation_id VARCHAR(100) NOT NULL,
            deposit_id VARCHAR(50) NOT NULL,
            booking_id VARCHAR(50) NOT NULL,
            entry_type VARCHAR(30) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            payment_method VARCHAR(20) NULL,
            bank_account_id VARCHAR(50) NULL,
            reason TEXT NULL,
            reason_type VARCHAR(30) NULL,
            transaction_id VARCHAR(50) NULL,
            shift_session_id VARCHAR(80) NULL,
            created_by VARCHAR(50) NULL,
            created_by_name VARCHAR(100) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'web',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_guest_security_deposit_operation(operation_id),
            INDEX idx_guest_security_deposit_ledger_booking(booking_id,created_at),
            INDEX idx_guest_security_deposit_ledger_deposit(deposit_id,created_at),
            INDEX idx_guest_security_deposit_ledger_transaction(transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO categories(id,name,type,system_key,is_system,is_active,created_at,updated_at) VALUES
            ('cat_security_deposit_income','Deposito Jaminan Tamu','income','guest_security_deposit_income',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('cat_security_deposit_expense','Deposito Jaminan Tamu','expense','guest_security_deposit_expense',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('cat_guest_compensation_income','Ganti Rugi Tamu','income','guest_compensation_income',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE name=VALUES(name),is_system=1,is_active=1,updated_at=CURRENT_TIMESTAMP");
        $pdo->exec("INSERT INTO subcategories(id,category_id,category_name,name,system_key,is_system,is_active,created_at,updated_at) VALUES
            ('sub_security_deposit_received','cat_security_deposit_income','Deposito Jaminan Tamu','Penerimaan Deposit','guest_security_deposit_received',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('sub_security_deposit_refund','cat_security_deposit_expense','Deposito Jaminan Tamu','Pengembalian Deposit','guest_security_deposit_refund',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('sub_guest_comp_key','cat_guest_compensation_income','Ganti Rugi Tamu','Penggantian Kunci','guest_compensation_key',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('sub_guest_comp_damage','cat_guest_compensation_income','Ganti Rugi Tamu','Kerusakan Kamar','guest_compensation_damage',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('sub_guest_comp_lost_item','cat_guest_compensation_income','Ganti Rugi Tamu','Barang Hotel Hilang','guest_compensation_lost_item',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
            ('sub_guest_comp_other','cat_guest_compensation_income','Ganti Rugi Tamu','Ganti Rugi Lainnya','guest_compensation_other',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),category_name=VALUES(category_name),name=VALUES(name),is_system=1,is_active=1,updated_at=CURRENT_TIMESTAMP");
        $pdo->exec("UPDATE bookings SET securityDepositRequired=0,securityDepositRequiredAmount=0,securityDepositReceived=0,securityDepositRefunded=0,securityDepositForfeited=0,securityDepositHeld=0,securityDepositStatus='not_required' WHERE securityDepositStatus IS NULL OR securityDepositStatus=''");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Guest security deposit liability ledger with receipt, refund, hold and forfeiture workflows separated from booking revenue',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[guest security deposit 15.5] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_guest_security_deposit_155')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.8: operational checkout may release a physically vacated room while preserving an audited receivable. */
function runRc41015158OperationalCheckoutFinancialClosure(PDO $pdo): void {
    $version='2026.07.rc4.10.15.15.8-operational-checkout-financial-closure.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_operational_checkout_158',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        foreach([
            ['financialClosureStatus',"VARCHAR(30) NOT NULL DEFAULT 'not_required'"],
            ['financialClosureBalance',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['financialClosureReason',"VARCHAR(255) NULL"],
            ['financialClosureAt',"DATETIME NULL"],
            ['financialClosureBy',"VARCHAR(50) NULL"],
            ['financialClosureSource',"VARCHAR(30) NULL"],
            ['financialClosureOperationId',"VARCHAR(100) NULL"]
        ] as $column)ensureColumnExists($pdo,'bookings',$column[0],$column[1]);
        try{$pdo->exec("CREATE INDEX idx_bookings_financial_closure ON bookings(financialClosureStatus,financialClosureBalance)");}catch(Throwable $ignored){}
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Separate physical room checkout from financial closure; allow verified field checkout with audited receivable and held deposit',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[operational checkout financial closure 15.8] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_operational_checkout_158')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.15: historical allocations may reference cancelled bookings for annual reporting only. */
function runRc410151515HistoricalCancelledReportingAllocation(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.15-historical-cancelled-reporting-allocation.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_historical_cancelled_allocation_151515',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        ensureColumnExists($pdo,'transaction_allocations','reporting_only','TINYINT(1) NOT NULL DEFAULT 0');
        try{$pdo->exec("CREATE INDEX idx_transaction_allocation_reporting ON transaction_allocations(reporting_only,status,booking_id)");}catch(Throwable $ignored){}
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Allow historical_import allocations to cancelled reservations as reporting-only records without mutating booking operations',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[historical cancelled reporting allocation 15.15] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_historical_cancelled_allocation_151515')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.15.14: close orphaned Telegram journals created before operation-id normalization. */
function runRc410151514TelegramJournalRepair(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.14-telegram-journal-repair.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_telegram_journal_151514',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();
        $repairPayload=json_encode([
            'error'=>'Jurnal Telegram lama ditutup otomatis setelah perbaikan operation ID 15.14. Silakan ulangi perintah.'
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$pdo->prepare("UPDATE sync_operations
            SET status='failed',result_json=?,processed_at=CURRENT_TIMESTAMP
            WHERE device_id='telegram'
              AND status='processing'
              AND created_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE)");
        $stmt->execute([$repairPayload]);
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Close orphaned pre-15.14 Telegram processing journals after operation-id normalization',CURRENT_TIMESTAMP)")->execute([$version]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[telegram journal 15.14] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_telegram_journal_151514')");}catch(Throwable $ignored){}}
    }
}



/** RC4.10.15.15.16: unified live/manual tax snapshots and unresolved audit state. */
function runRc410151516DynamicHistoricalTaxSync(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.16-dynamic-historical-tax-sync.2';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_dynamic_tax_sync_151516',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        foreach([
            ['taxSnapshotStatus',"VARCHAR(30) NOT NULL DEFAULT 'legacy'"],
            ['taxSource',"VARCHAR(40) NULL"],
            ['taxRuleId',"VARCHAR(100) NULL"],
            ['taxRuleName',"VARCHAR(150) NULL"],
            ['taxNote',"VARCHAR(255) NULL"]
        ] as $column)ensureColumnExists($pdo,'transactions',$column[0],$column[1]);
        // Split-payment snapshot on manual/historical transactions (FIX29): mirrors
        // the canonical bookings split columns so Log Kas manual entries can record
        // cash+transfer composition with the same authority as checkout.
        foreach([
            ['isSplitPayment',"TINYINT(1) NOT NULL DEFAULT 0"],
            ['splitCashAmount',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['splitTransferAmount',"DECIMAL(15,2) NOT NULL DEFAULT 0"],
            ['splitTransferBankAccountId',"VARCHAR(50) NULL"]
        ] as $column)ensureColumnExists($pdo,'transactions',$column[0],$column[1]);
        foreach([
            ['tax_snapshot_status',"VARCHAR(30) NOT NULL DEFAULT 'legacy'"],
            ['tax_source',"VARCHAR(40) NULL"],
            ['tax_rule_id',"VARCHAR(100) NULL"]
        ] as $column)ensureColumnExists($pdo,'transaction_allocations',$column[0],$column[1]);
        try{$pdo->exec("CREATE INDEX idx_transactions_tax_status ON transactions(recordOrigin,taxSnapshotStatus,`date`)");}catch(Throwable $ignored){}
        $pdo->exec("UPDATE transactions SET
            taxSnapshotStatus=CASE
              WHEN type<>'income' THEN 'confirmed'
              WHEN baseAmount IS NULL OR taxAmount IS NULL OR taxRate IS NULL THEN 'unresolved'
              ELSE 'confirmed' END,
            taxSource=CASE
              WHEN type<>'income' THEN 'not_applicable'
              WHEN baseAmount IS NULL OR taxAmount IS NULL OR taxRate IS NULL THEN 'unresolved'
              WHEN recordOrigin='historical_import' OR shiftExempt=1 THEN 'historical_document'
              ELSE 'live_rule' END
            WHERE taxSnapshotStatus IS NULL OR taxSnapshotStatus='' OR taxSnapshotStatus='legacy'");
        $pdo->exec("UPDATE transaction_allocations a
            JOIN transactions t ON t.id=a.transaction_id
            SET a.tax_snapshot_status=CASE WHEN t.taxSnapshotStatus='confirmed' THEN 'confirmed' ELSE 'unresolved' END,
                a.tax_source=COALESCE(NULLIF(t.taxSource,''),'unresolved'),
                a.tax_rule_id=t.taxRuleId
            WHERE a.tax_snapshot_status IS NULL OR a.tax_snapshot_status='' OR a.tax_snapshot_status='legacy'");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Unify live and historical tax snapshots, retain effective rule identity and display name, and expose unresolved PBJT instead of tax zero',CURRENT_TIMESTAMP)")->execute([$version]);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[dynamic historical tax sync 15.16] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_dynamic_tax_sync_151516')");}catch(Throwable $ignored){}}
    }
}

/**
 * RC4.10.15.15.17: explicit, editable OTA non-taxable policy.
 *
 * This is a business-policy seed requested for this hotel deployment, not a
 * hard-coded tax branch.  The resolver continues to use tax_rules only.  The
 * rule is created once, only when no grouped OTA wildcard rule exists, and can
 * then be edited, periodized, overridden by a higher-priority exact-source
 * rule, disabled, or deleted by an authorized administrator.
 */
function runRc410151517OtaNonTaxablePolicy(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.17-ota-nontaxable-policy.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_ota_tax_policy_151517',8)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        $pdo->beginTransaction();
        $seedRaw=strtolower(trim((string)(getenv('TAMASYA_SEED_OTA_NON_TAXABLE_RULE')?:'0')));
        $seedEnabled=!in_array($seedRaw,['0','false','no','off'],true);
        $existing=null;
        $created=false;
        if($seedEnabled){
            $check=$pdo->query("SELECT id,name,is_active,taxable,rate,priority,effective_from,effective_until
                FROM tax_rules
                WHERE LOWER(TRIM(source_pattern))='ota' AND LOWER(TRIM(transaction_kind))='room'
                ORDER BY is_active DESC,priority DESC,created_at DESC,id ASC
                LIMIT 1 FOR UPDATE");
            $existing=$check?$check->fetch(PDO::FETCH_ASSOC):null;
            if(!$existing){
                $id='tax_ota_group_non_taxable_hotel_policy';
                $pdo->prepare("INSERT INTO tax_rules
                    (id,name,source_pattern,transaction_kind,taxable,rate,priority,effective_from,effective_until,is_active,created_by,created_at)
                    VALUES (?,?, 'OTA','room',0,0,50,NULL,NULL,1,'system-policy',CURRENT_TIMESTAMP)")
                    ->execute([$id,'Booking awal OTA — hotel tidak memungut PBJT tambahan']);
                $existing=['id'=>$id,'name'=>'Booking awal OTA — hotel tidak memungut PBJT tambahan','is_active'=>1,'taxable'=>0,'rate'=>0,'priority'=>50,'effective_from'=>null,'effective_until'=>null];
                $created=true;
            }
        }
        $description=$seedEnabled
            ? ($created
                ? 'Create an explicit editable OTA-group non-taxable rule without overriding existing OTA policy'
                : 'Preserve the existing OTA-group tax rule; no automatic overwrite')
            : 'OTA non-taxable seed disabled by TAMASYA_SEED_OTA_NON_TAXABLE_RULE';
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,?,CURRENT_TIMESTAMP)")
            ->execute([$version,$description]);
        if($seedEnabled){
            $message=$created
                ? 'Rule booking awal OTA (kind=room) dibuat sebagai kebijakan awal. Extra dan perpanjangan tetap mengikuti rule masing-masing di Aturan Pajak.'
                : 'Rule booking awal OTA yang sudah ada dipertahankan. Sistem tidak menimpa tarif/status pajak yang telah dikonfigurasi Admin.';
            $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type)
                VALUES ('notif_rc4_10_15_15_17_ota_tax_policy',?,CURRENT_TIMESTAMP,0,'system')
                ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP")
                ->execute([$message]);
        }
        bumpServerRevision($pdo);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log(clientExceptionMessage('[OTA non-taxable policy 15.17] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_ota_tax_policy_151517')");}catch(Throwable $ignored){}}
    }
}


/**
 * RC4.10.15.15.21: production-readiness accounting and configuration repair.
 *
 * Rebuilds transaction-projection journals with PBJT liability separation,
 * removes executable bank defaults, and surfaces unsafe/incomplete payment
 * configuration without overwriting legitimate operator data.
 */
function runRc410151521ProductionReadiness(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.21-production-readiness.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_production_readiness_151521',15)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;

        foreach([
            "ALTER TABLE config MODIFY bank_name VARCHAR(100) DEFAULT ''",
            "ALTER TABLE config MODIFY bank_account VARCHAR(100) DEFAULT ''",
            "ALTER TABLE config MODIFY bank_recipient VARCHAR(255) DEFAULT ''",
            "ALTER TABLE config MODIFY smtp_from VARCHAR(255) DEFAULT ''"
        ] as $sql){try{$pdo->exec($sql);}catch(Throwable $ignored){}}

        // Disable only the exact demonstration accounts shipped by older builds.
        // Operator-created accounts and any row whose identity/value changed are preserved.
        $demoBankRows=$pdo->exec("UPDATE bank_accounts SET isActive=0 WHERE
            (id='ba_bca_trf' AND REPLACE(REPLACE(TRIM(accountNumber),' ',''),'-','')='3190887124') OR
            (id='ba_mandiri_trf' AND REPLACE(REPLACE(TRIM(accountNumber),' ',''),'-','')='1150098712') OR
            (id='ba_bca_edc' AND UPPER(REPLACE(REPLACE(TRIM(accountNumber),' ',''),'-',''))='EDCBCA9812') OR
            (id='ba_mandiri_edc' AND UPPER(REPLACE(REPLACE(TRIM(accountNumber),' ',''),'-',''))='EDCMND3251')");
        $pdo->exec("UPDATE config SET smtp_from='' WHERE LOWER(TRIM(COALESCE(smtp_from,'')))='no-reply@tamasya-garden-hotel.my.id'");

        // Every projection must be rebuilt by the corrected pure journal builder.
        $pdo->exec("UPDATE journal_entries SET source_version=0 WHERE source='transaction_projection'");
        for($pass=0;$pass<100;$pass++){
            $remaining=(int)$pdo->query("SELECT COUNT(*) FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL OR j.source_version<>COALESCE(t.version,1)")->fetchColumn();
            if($remaining===0)break;
            syncJournalProjections($pdo,true);
        }
        $remaining=(int)$pdo->query("SELECT COUNT(*) FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL OR j.source_version<>COALESCE(t.version,1)")->fetchColumn();
        if($remaining!==0)throw new RuntimeException('Rebuild jurnal produksi belum selesai: '.$remaining.' transaksi tersisa.');

        $unsafeBank=(int)$pdo->query("SELECT COUNT(*) FROM config WHERE id='system_default' AND (
            (TRIM(COALESCE(bank_name,''))='' AND (TRIM(COALESCE(bank_account,''))<>'' OR TRIM(COALESCE(bank_recipient,''))<>'')) OR
            (TRIM(COALESCE(bank_name,''))<>'' AND (TRIM(COALESCE(bank_account,''))='' OR TRIM(COALESCE(bank_recipient,''))='')) OR
            REPLACE(REPLACE(TRIM(COALESCE(bank_account,'')),' ',''),'-','') IN ('3190887124','1150098712')
        )")->fetchColumn();
        if($unsafeBank>0||$demoBankRows>0){
            $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES
                ('notif_rc4_10_15_15_21_bank_config','Rekening contoh telah dinonaktifkan atau konfigurasi pembayaran belum lengkap. Verifikasi dan aktifkan hanya rekening resmi hotel sebelum menerima pembayaran publik.',CURRENT_TIMESTAMP,0,'system')
                ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP,`read`=0")
                ->execute();
        }

        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Rebuild PBJT-aware journal projections, blank payment defaults, and flag unsafe bank configuration',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        bumpServerRevision($pdo);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[production readiness 15.21] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_production_readiness_151521')");}catch(Throwable $ignored){}}
    }
}

/** RC4.10.15.15.23: additive POS/minibar tables and migration marker. */
function runRc410151523PosMinibar(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.23-pos-minibar.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_pos_minibar_151523',15)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        tamasyaPosEnsureSchema($pdo);
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Additive POS/minibar products, sales, stock movements, room charges, PBJT snapshots and HPP integration',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        bumpServerRevision($pdo);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[POS minibar 15.23] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_pos_minibar_151523')");}catch(Throwable $ignored){}}
    }
}


/** RC4.10.15.15.24: thermal receipt, picking slip and room-delivery audit. */
function runRc410151524ThermalRoomDelivery(PDO $pdo): void {
    $version='2026.08.rc4.10.15.15.24-thermal-room-delivery.1';
    if(!$pdo||schemaMigrationApplied($pdo,$version))return;
    $lock=false;
    try{
        $lock=(int)$pdo->query("SELECT GET_LOCK('tamasya_thermal_delivery_151524',15)")->fetchColumn()===1;
        if(!$lock||schemaMigrationApplied($pdo,$version))return;
        tamasyaPosEnsureSchema($pdo);
        $pdo->exec("UPDATE pos_sales SET delivery_status=CASE WHEN payment_method='room_charge' THEN 'created' ELSE 'not_required' END,delivery_updated_at=COALESCE(delivery_updated_at,created_at) WHERE delivery_status IS NULL OR delivery_status='' OR (payment_method='room_charge' AND delivery_status='not_required')");
        $pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES (?,'Additive thermal 58/80 mm receipts, picking slips, room-delivery workflow, print COPY audit and recipient confirmation',CURRENT_TIMESTAMP)")
            ->execute([$version]);
        bumpServerRevision($pdo);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[thermal room delivery 15.24] migration failed',$e));
    }finally{
        if($lock){try{$pdo->query("SELECT RELEASE_LOCK('tamasya_thermal_delivery_151524')");}catch(Throwable $ignored){}}
    }
}
