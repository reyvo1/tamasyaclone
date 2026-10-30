-- ============================================================================
-- TAMASYA ADMIN - PATCH DATABASE DATA HISTORIS / BACKFILL
-- Tanggal: 2026-08-27
-- Sumber schema: tamasya-admin-app-full-20260826.zip / database_setup.sql
--
-- TUJUAN
-- 1. Membuat tabel financial_reporting_periods jika belum ada.
-- 2. Membuat tabel historical_backfill_adjustments jika belum ada.
-- 3. Menambahkan kolom transaksi yang dibutuhkan fitur historical_import/backfill
--    jika kolom tersebut belum ada.
--
-- AMAN UNTUK DATABASE EXISTING:
-- - Tidak DROP TABLE.
-- - Tidak DELETE data.
-- - Tidak TRUNCATE data.
-- - Kolom hanya ditambahkan jika belum ada.
--
-- PENTING:
-- Jalankan file ini SETELAH memilih database TAMASYA yang benar di phpMyAdmin.
-- Sangat disarankan membuat backup database sebelum migration.
-- ============================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1) Tabel kontrol periode laporan keuangan.
-- Backend backfill selalu membaca tabel ini melalui tamasyaGetReportingPeriod().
-- Jika periode belum mempunyai row, aplikasi memperlakukannya sebagai periode OPEN.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `financial_reporting_periods` (
  `period_key` char(7) NOT NULL,
  `cash_status` varchar(20) NOT NULL DEFAULT 'open',
  `tax_status` varchar(20) NOT NULL DEFAULT 'open',
  `report_reference` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status_source` varchar(40) NOT NULL DEFAULT 'manual',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`period_key`),
  KEY `idx_financial_reporting_period_status` (`cash_status`,`tax_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------------
-- 2) Register adjustment transaksi backfill untuk periode reported/closed.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `historical_backfill_adjustments` (
  `id` varchar(50) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `transaction_id` varchar(50) NOT NULL,
  `period_key` char(7) NOT NULL,
  `impact_status` varchar(40) NOT NULL,
  `cash_delta` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_base_delta` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_delta` decimal(15,2) NOT NULL DEFAULT 0.00,
  `requires_tax_amendment` tinyint(1) NOT NULL DEFAULT 0,
  `review_status` varchar(30) NOT NULL DEFAULT 'pending_review',
  `reason` varchar(255) NOT NULL,
  `source_type` varchar(30) DEFAULT NULL,
  `source_reference` varchar(160) DEFAULT NULL,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_historical_backfill_adjustment_operation` (`operation_id`),
  KEY `idx_historical_backfill_adjustment_period` (`period_key`,`review_status`),
  KEY `idx_historical_backfill_adjustment_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------------
-- 3) Sinkronisasi kolom BACKFILL pada tabel transactions.
-- Menggunakan INFORMATION_SCHEMA + dynamic ALTER supaya dapat di-import ulang.
-- Tidak membutuhkan privilege CREATE PROCEDURE / CREATE ROUTINE.
-- --------------------------------------------------------------------------

-- importBatchId
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'importBatchId') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `importBatchId` varchar(100) DEFAULT NULL',
  'SELECT ''SKIP importBatchId: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- recordOrigin
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'recordOrigin') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `recordOrigin` varchar(30) NOT NULL DEFAULT ''live_operation''',
  'SELECT ''SKIP recordOrigin: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- shiftExempt
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'shiftExempt') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `shiftExempt` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT ''SKIP shiftExempt: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- shiftExemptionReason
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'shiftExemptionReason') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `shiftExemptionReason` varchar(255) DEFAULT NULL',
  'SELECT ''SKIP shiftExemptionReason: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- serviceDate
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'serviceDate') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `serviceDate` date DEFAULT NULL',
  'SELECT ''SKIP serviceDate: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- historicalSourceType
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'historicalSourceType') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `historicalSourceType` varchar(30) DEFAULT NULL',
  'SELECT ''SKIP historicalSourceType: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- historicalSourceReference
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'historicalSourceReference') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `historicalSourceReference` varchar(160) DEFAULT NULL',
  'SELECT ''SKIP historicalSourceReference: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- sourceReportedBy
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'sourceReportedBy') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `sourceReportedBy` varchar(120) DEFAULT NULL',
  'SELECT ''SKIP sourceReportedBy: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- reportingPeriod
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'reportingPeriod') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `reportingPeriod` char(7) DEFAULT NULL',
  'SELECT ''SKIP reportingPeriod: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- periodStatusAtEntry
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'periodStatusAtEntry') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `periodStatusAtEntry` varchar(30) NOT NULL DEFAULT ''open''',
  'SELECT ''SKIP periodStatusAtEntry: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- periodImpactStatus
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'periodImpactStatus') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `periodImpactStatus` varchar(40) NOT NULL DEFAULT ''normal''',
  'SELECT ''SKIP periodImpactStatus: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- requiresTaxAmendment
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'requiresTaxAmendment') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `requiresTaxAmendment` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT ''SKIP requiresTaxAmendment: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- historicalReviewStatus
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'historicalReviewStatus') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `historicalReviewStatus` varchar(30) NOT NULL DEFAULT ''not_required''',
  'SELECT ''SKIP historicalReviewStatus: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- historicalReviewedBy
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'historicalReviewedBy') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `historicalReviewedBy` varchar(50) DEFAULT NULL',
  'SELECT ''SKIP historicalReviewedBy: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- historicalReviewedAt
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'historicalReviewedAt') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `historicalReviewedAt` datetime DEFAULT NULL',
  'SELECT ''SKIP historicalReviewedAt: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- periodCorrectionReason
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'periodCorrectionReason') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `periodCorrectionReason` varchar(255) DEFAULT NULL',
  'SELECT ''SKIP periodCorrectionReason: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- inputDelayDays
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'inputDelayDays') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `inputDelayDays` int(11) NOT NULL DEFAULT 0',
  'SELECT ''SKIP inputDelayDays: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- --------------------------------------------------------------------------
-- 4) Kolom snapshot pajak yang digunakan posting canonical backfill.
-- Ditambahkan hanya jika database lama belum memilikinya.
-- --------------------------------------------------------------------------

-- taxNote
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'taxNote') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `taxNote` varchar(255) DEFAULT NULL',
  'SELECT ''SKIP taxNote: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- taxRuleId
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'taxRuleId') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `taxRuleId` varchar(100) DEFAULT NULL',
  'SELECT ''SKIP taxRuleId: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- taxRuleName
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'taxRuleName') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `taxRuleName` varchar(150) DEFAULT NULL',
  'SELECT ''SKIP taxRuleName: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- taxSnapshotStatus
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'taxSnapshotStatus') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `taxSnapshotStatus` varchar(30) NOT NULL DEFAULT ''unresolved''',
  'SELECT ''SKIP taxSnapshotStatus: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- taxSource
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'taxSource') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `taxSource` varchar(40) DEFAULT NULL',
  'SELECT ''SKIP taxSource: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- --------------------------------------------------------------------------
-- 5) Kolom split payment dari schema source saat ini.
-- Posting manual/backfill versi source ini ikut mengirim snapshot kolom tersebut.
-- --------------------------------------------------------------------------

-- isSplitPayment
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'isSplitPayment') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `isSplitPayment` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT ''SKIP isSplitPayment: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- splitCashAmount
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'splitCashAmount') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `splitCashAmount` decimal(15,2) DEFAULT NULL',
  'SELECT ''SKIP splitCashAmount: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- splitTransferAmount
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'splitTransferAmount') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `splitTransferAmount` decimal(15,2) DEFAULT NULL',
  'SELECT ''SKIP splitTransferAmount: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- splitTransferBankAccountId
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'splitTransferBankAccountId') = 0,
  'ALTER TABLE `transactions` ADD COLUMN `splitTransferBankAccountId` varchar(50) DEFAULT NULL',
  'SELECT ''SKIP splitTransferBankAccountId: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- --------------------------------------------------------------------------
-- 6) Index khusus backfill. Dibuat setelah semua kolom tersedia.
-- --------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_transactions_backfill_period') = 0,
  'ALTER TABLE `transactions` ADD INDEX `idx_transactions_backfill_period` (`recordOrigin`,`reportingPeriod`,`periodImpactStatus`)',
  'SELECT ''SKIP idx_transactions_backfill_period: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_transactions_backfill_review') = 0,
  'ALTER TABLE `transactions` ADD INDEX `idx_transactions_backfill_review` (`historicalReviewStatus`,`requiresTaxAmendment`,`date`)',
  'SELECT ''SKIP idx_transactions_backfill_review: sudah ada'' AS migration_info'
);
PREPARE tamasya_stmt FROM @sql; EXECUTE tamasya_stmt; DEALLOCATE PREPARE tamasya_stmt;

-- --------------------------------------------------------------------------
-- 7) VERIFIKASI. Hasil akhir seharusnya menunjukkan kedua tabel = 1,
-- dan daftar kolom backfill muncul pada result set kedua.
-- --------------------------------------------------------------------------
SELECT
  DATABASE() AS selected_database,
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'financial_reporting_periods') AS financial_reporting_periods_ok,
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historical_backfill_adjustments') AS historical_backfill_adjustments_ok;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'transactions'
  AND COLUMN_NAME IN (
    'importBatchId','recordOrigin','shiftExempt','shiftExemptionReason',
    'serviceDate','historicalSourceType','historicalSourceReference','sourceReportedBy',
    'reportingPeriod','periodStatusAtEntry','periodImpactStatus','requiresTaxAmendment',
    'historicalReviewStatus','historicalReviewedBy','historicalReviewedAt',
    'periodCorrectionReason','inputDelayDays','taxNote','taxRuleId','taxRuleName','taxSnapshotStatus',
    'taxSource','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId'
  )
ORDER BY COLUMN_NAME;

SELECT 'PATCH BACKFILL TAMASYA SELESAI' AS status;
