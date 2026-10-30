-- TAMASYA V137 FRESH CANONICAL DATABASE
-- Import into a NEW/EMPTY database. No CREATE DATABASE/USE statement is included.
-- FK checks are disabled only for this import session because several canonical
-- child tables are intentionally declared before their parent tables.
SET NAMES utf8mb4;
SET @TAMASYA_PREV_FOREIGN_KEY_CHECKS := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET @tamasya_node_mirror_apply := 0;

-- ============================================================================
-- TAMASYA HOTEL SYSTEM V137 - FRESH CANONICAL MULTI-HOTEL BASELINE
-- Generated from the audited current production schema structure, WITHOUT production data.
-- Purpose: NEW/EMPTY DATABASE ONLY. This file contains NO DROP/TRUNCATE/DELETE statements.
-- Safety: importing into a database that already contains these tables should FAIL rather
-- than overwrite existing hotel data. Create a NEW empty database for this baseline.
--
-- Canonical finance/catalog rules:
--   * Category/subcategory display labels are property-owned master data; no hotel business label is seeded.
--   * Stable categories.system_key values carry accounting/workflow meaning while category names remain property-owned.
--   * Operational rooms.type values are independent from Finance subcategories; no duplicate room-type table is created.
--   * No room type, room, booking, transaction, journal, staff, bank, tax, category, subcategory, or guest business data is seeded.
--   * LIVE tax rules must be configured before live income; historical backfill may use explicit unresolved tax when source evidence is insufficient.
--   * Historical data should be entered using the app historical/backfill flow; display labels never determine accounting behavior.
--
-- Multi-hotel rules:
--   * No hotel name, bank account, tax rate, domain, or production secret is hard-coded here
--   * Each property uses its own database and property environment identity
-- ============================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- TABLE: activity_logs
CREATE TABLE `activity_logs` (
  `id` varchar(50) NOT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `staff_id` varchar(50) DEFAULT NULL,
  `staff_name` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_timestamp` (`timestamp` DESC),
  KEY `idx_activity_staff` (`staff_id`),
  KEY `idx_activity_time` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: app_documents
CREATE TABLE `app_documents` (
  `id` varchar(80) NOT NULL,
  `title` varchar(190) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'SOP',
  `content` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_doc_category` (`category`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: approval_requests
CREATE TABLE `approval_requests` (
  `id` varchar(80) NOT NULL,
  `request_type` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` varchar(190) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` text NOT NULL,
  `payload` longtext DEFAULT NULL,
  `requester_id` varchar(50) NOT NULL,
  `requester_name` varchar(150) NOT NULL,
  `approver_id` varchar(50) DEFAULT NULL,
  `approver_name` varchar(150) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `decision_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_status` (`status`,`created_at`),
  KEY `idx_approval_usage` (`entity_type`,`entity_id`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: attendance
CREATE TABLE `attendance` (
  `id` varchar(50) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `clock_in` varchar(20) NOT NULL,
  `clock_out` varchar(20) DEFAULT NULL,
  `method` varchar(50) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'present',
  `fingerprint_token` varchar(255) DEFAULT NULL,
  `face_data_url` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `clock_out_device_id` varchar(50) DEFAULT NULL,
  `clock_out_source_event_id` varchar(190) DEFAULT NULL,
  `clock_out_verification_id` varchar(120) DEFAULT NULL,
  `clock_out_verification_score` decimal(7,5) DEFAULT NULL,
  `clock_out_verified_at` datetime DEFAULT NULL,
  `device_id` varchar(50) DEFAULT NULL,
  `evidence_hash` char(64) DEFAULT NULL,
  `source_event_id` varchar(190) DEFAULT NULL,
  `verification_id` varchar(120) DEFAULT NULL,
  `verification_score` decimal(7,5) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `clock_out_location` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_source_event` (`source_event_id`),
  UNIQUE KEY `uq_attendance_clock_out_source_event` (`clock_out_source_event_id`),
  KEY `idx_attendance_date` (`date` DESC),
  KEY `idx_attendance_staff` (`staff_id`),
  KEY `idx_attendance_verification` (`verification_id`),
  KEY `idx_attendance_device` (`device_id`,`verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: audit_logs
CREATE TABLE `audit_logs` (
  `id` varchar(80) NOT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `staff_name` varchar(150) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'web',
  `action` varchar(190) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` varchar(190) DEFAULT NULL,
  `old_data` longtext DEFAULT NULL,
  `new_data` longtext DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `device_id` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `session_id` varchar(80) DEFAULT NULL,
  `operation_id` varchar(100) DEFAULT NULL,
  `cluster_epoch` bigint(20) DEFAULT NULL,
  `fencing_token_hash` varchar(64) DEFAULT NULL,
  `channel_id` varchar(100) DEFAULT NULL,
  `cluster_id` varchar(100) DEFAULT NULL,
  `node_id` varchar(100) DEFAULT NULL,
  `outcome` varchar(30) NOT NULL DEFAULT 'success',
  `property_id` varchar(80) NOT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `staff_role` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_staff` (`staff_id`),
  KEY `idx_audit_session` (`session_id`,`created_at`),
  KEY `idx_audit_operation` (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: backup_runs
CREATE TABLE `backup_runs` (
  `id` varchar(80) NOT NULL,
  `backup_type` varchar(50) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'completed',
  `size_bytes` bigint(20) DEFAULT NULL,
  `checksum` varchar(128) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `restore_tested_at` datetime DEFAULT NULL,
  `restore_tested_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backup_created` (`created_at`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: bank_accounts
CREATE TABLE `bank_accounts` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `accountNumber` varchar(100) DEFAULT NULL,
  `accountHolder` varchar(255) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'bank',
  `isActive` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: biometric_device_users
CREATE TABLE `biometric_device_users` (
  `id` varchar(50) NOT NULL,
  `device_id` varchar(50) NOT NULL,
  `device_user_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `biometric_type` varchar(30) NOT NULL,
  `enrollment_status` varchar(20) NOT NULL DEFAULT 'active',
  `enrolled_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biometric_device_user` (`device_id`,`device_user_id`),
  KEY `idx_biometric_device_staff` (`staff_id`,`enrollment_status`),
  CONSTRAINT `fk_biometric_device_user_device` FOREIGN KEY (`device_id`) REFERENCES `biometric_devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_biometric_device_user_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: biometric_devices
CREATE TABLE `biometric_devices` (
  `id` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `serial_number` varchar(150) NOT NULL,
  `device_type` varchar(30) NOT NULL,
  `connection_mode` varchar(30) NOT NULL DEFAULT 'webhook',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `api_key_hash` char(64) NOT NULL,
  `webhook_secret_encrypted` text NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biometric_device_serial` (`serial_number`),
  UNIQUE KEY `uq_biometric_device_api_key` (`api_key_hash`),
  KEY `idx_biometric_device_status` (`status`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: biometric_verifications
CREATE TABLE `biometric_verifications` (
  `id` varchar(120) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `method` varchar(30) NOT NULL,
  `source` varchar(40) NOT NULL,
  `device_id` varchar(50) DEFAULT NULL,
  `device_serial` varchar(190) DEFAULT NULL,
  `provider` varchar(100) DEFAULT NULL,
  `score` decimal(7,5) DEFAULT NULL,
  `liveness_passed` tinyint(1) NOT NULL DEFAULT 0,
  `evidence_hash` char(64) DEFAULT NULL,
  `bridge_nonce` varchar(190) DEFAULT NULL,
  `source_event_id` varchar(190) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'verified',
  `verified_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `raw_metadata` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biometric_bridge_nonce` (`bridge_nonce`),
  UNIQUE KEY `uq_biometric_source_event` (`source_event_id`),
  KEY `idx_biometric_verification_staff` (`staff_id`,`verified_at`),
  KEY `idx_biometric_verification_status` (`status`,`expires_at`),
  KEY `fk_biometric_verification_device` (`device_id`),
  CONSTRAINT `fk_biometric_verification_device` FOREIGN KEY (`device_id`) REFERENCES `biometric_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_biometric_verification_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: bookings
CREATE TABLE `bookings` (
  `id` varchar(50) NOT NULL,
  `guestName` varchar(100) NOT NULL,
  `guestEmail` varchar(100) DEFAULT '',
  `guestPhone` varchar(30) DEFAULT '',
  `roomNumber` varchar(10) NOT NULL,
  `roomType` varchar(50) NOT NULL,
  `checkIn` date NOT NULL,
  `checkOut` date NOT NULL,
  `totalAmount` decimal(15,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'reserved',
  `paymentStatus` varchar(20) NOT NULL DEFAULT 'unpaid',
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  `paymentMethod` varchar(50) DEFAULT NULL,
  `bankAccountId` varchar(50) DEFAULT NULL,
  `vatRate` decimal(7,3) DEFAULT NULL,
  `vatAmount` decimal(15,2) DEFAULT NULL,
  `ktpPhoto` longtext DEFAULT NULL,
  `extras` longtext DEFAULT NULL,
  `bookingSource` varchar(255) DEFAULT 'Direct',
  `isSplitPayment` tinyint(1) DEFAULT 0,
  `splitCashAmount` decimal(15,2) DEFAULT NULL,
  `splitTransferAmount` decimal(15,2) DEFAULT NULL,
  `splitTransferBankAccountId` varchar(50) DEFAULT NULL,
  `downPaymentAmount` decimal(15,2) DEFAULT 0.00,
  `downPaymentMethod` varchar(50) DEFAULT NULL,
  `downPaymentBankAccountId` varchar(50) DEFAULT NULL,
  `downPaymentDate` varchar(50) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `updatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updatedBy` varchar(100) DEFAULT NULL,
  `updatedSource` varchar(30) DEFAULT NULL,
  `documentNumber` varchar(80) DEFAULT NULL,
  `roomCharge` decimal(15,2) NOT NULL DEFAULT 0.00,
  `extraCharge` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discountAmount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amountPaid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balanceDue` decimal(15,2) NOT NULL DEFAULT 0.00,
  `refundAmount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `isOpenEnded` tinyint(1) NOT NULL DEFAULT 0,
  `actualCheckInAt` datetime DEFAULT NULL,
  `actualCheckOutAt` datetime DEFAULT NULL,
  `checkoutDueAt` datetime DEFAULT NULL,
  `lateCheckoutStatus` varchar(30) NOT NULL DEFAULT 'none',
  `lateCheckoutReason` text DEFAULT NULL,
  `lateCheckoutFee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `lateCheckoutApprovedBy` varchar(50) DEFAULT NULL,
  `keyControlStatus` varchar(30) NOT NULL DEFAULT 'not_issued',
  `accessMode` varchar(20) NOT NULL DEFAULT 'physical',
  `keyIssuedAt` datetime DEFAULT NULL,
  `keyIssuedBy` varchar(50) DEFAULT NULL,
  `keyReturnedAt` datetime DEFAULT NULL,
  `keyReturnedBy` varchar(50) DEFAULT NULL,
  `smartLockCodeHash` varchar(64) DEFAULT NULL,
  `smartLockCodeLast4` varchar(4) DEFAULT NULL,
  `smartLockValidFrom` datetime DEFAULT NULL,
  `smartLockValidUntil` datetime DEFAULT NULL,
  `financialClosureAt` datetime DEFAULT NULL,
  `financialClosureBalance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `financialClosureBy` varchar(50) DEFAULT NULL,
  `financialClosureOperationId` varchar(100) DEFAULT NULL,
  `financialClosureReason` varchar(255) DEFAULT NULL,
  `financialClosureSource` varchar(30) DEFAULT NULL,
  `financialClosureStatus` varchar(30) NOT NULL DEFAULT 'not_required',
  `financialProjectionLockReason` varchar(255) DEFAULT NULL,
  `financialProjectionLockedAt` datetime DEFAULT NULL,
  `financialProjectionMode` varchar(30) NOT NULL DEFAULT 'live_ledger',
  `scheduledCheckInAt` datetime DEFAULT NULL,
  `scheduledCheckOutAt` datetime DEFAULT NULL,
  `securityDepositForfeited` decimal(15,2) NOT NULL DEFAULT 0.00,
  `securityDepositHeld` decimal(15,2) NOT NULL DEFAULT 0.00,
  `securityDepositReceived` decimal(15,2) NOT NULL DEFAULT 0.00,
  `securityDepositRefunded` decimal(15,2) NOT NULL DEFAULT 0.00,
  `securityDepositRequired` tinyint(1) NOT NULL DEFAULT 0,
  `securityDepositRequiredAmount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `securityDepositStatus` varchar(30) NOT NULL DEFAULT 'not_required',
  `stayMode` varchar(30) NOT NULL DEFAULT 'overnight',
  PRIMARY KEY (`id`),
  KEY `idx_bookings_dates` (`checkIn`,`checkOut`),
  KEY `idx_bookings_room` (`roomNumber`),
  KEY `idx_bookings_status` (`status`),
  KEY `idx_bookings_created` (`createdAt` DESC),
  KEY `idx_bookings_room_status` (`roomNumber`,`status`),
  KEY `idx_bookings_due_status` (`status`,`checkoutDueAt`),
  KEY `idx_bookings_key_status` (`status`,`keyControlStatus`),
  KEY `idx_bookings_actual_checkout` (`actualCheckOutAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: categories
CREATE TABLE `categories` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL,
  `system_key` varchar(80) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_name_type` (`name`,`type`),
  UNIQUE KEY `uq_category_system_key` (`system_key`),
  KEY `idx_categories_active_type` (`is_active`,`type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- TABLE: chat_messages
CREATE TABLE `chat_messages` (
  `id` varchar(50) NOT NULL,
  `sender` varchar(50) NOT NULL,
  `text` text NOT NULL,
  `timestamp` datetime NOT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_messages_timestamp` (`timestamp` DESC),
  KEY `idx_chat_staff_time` (`staff_id`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_binding_codes
CREATE TABLE `communication_binding_codes` (
  `id` varchar(100) NOT NULL,
  `channel_id` varchar(80) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_provider_user_id` varchar(190) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_binding_code` (`code_hash`),
  KEY `idx_comm_binding_staff` (`channel_id`,`staff_id`,`expires_at`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_channels
CREATE TABLE `communication_channels` (
  `id` varchar(80) NOT NULL,
  `provider_key` varchar(64) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `inbound_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `outbound_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `managed_by` varchar(40) NOT NULL DEFAULT 'communication_core',
  `config_json` longtext DEFAULT NULL,
  `credential_encrypted` longtext DEFAULT NULL,
  `webhook_secret_encrypted` longtext DEFAULT NULL,
  `health_status` varchar(30) NOT NULL DEFAULT 'unknown',
  `last_checked_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comm_channel_provider` (`provider_key`,`enabled`),
  KEY `idx_comm_channel_health` (`health_status`,`last_checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_delivery_attempts
CREATE TABLE `communication_delivery_attempts` (
  `id` varchar(100) NOT NULL,
  `outbox_id` varchar(100) NOT NULL,
  `channel_id` varchar(80) NOT NULL,
  `provider_message_id` varchar(190) DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `error_message` text DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comm_delivery_outbox` (`outbox_id`,`attempt_number`),
  KEY `idx_comm_delivery_channel` (`channel_id`,`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_identities
CREATE TABLE `communication_identities` (
  `id` varchar(100) NOT NULL,
  `channel_id` varchar(80) NOT NULL,
  `provider_user_id` varchar(190) NOT NULL,
  `provider_conversation_id` varchar(190) DEFAULT NULL,
  `staff_id` varchar(50) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `verified_at` datetime DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_identity_provider` (`channel_id`,`provider_user_id`),
  UNIQUE KEY `uq_comm_identity_staff` (`channel_id`,`staff_id`),
  KEY `idx_comm_identity_staff` (`staff_id`,`status`),
  KEY `idx_comm_identity_conversation` (`channel_id`,`provider_conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_inbox
CREATE TABLE `communication_inbox` (
  `id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `channel_id` varchar(80) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `action_json` longtext DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comm_inbox_staff` (`staff_id`,`read_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_outbox
CREATE TABLE `communication_outbox` (
  `id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `recipient_staff_id` varchar(50) DEFAULT NULL,
  `preferred_channel_id` varchar(80) DEFAULT NULL,
  `fallback_channel_ids` longtext DEFAULT NULL,
  `message_json` longtext NOT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 5,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comm_outbox_ready` (`status`,`scheduled_at`,`priority`,`created_at`),
  KEY `idx_comm_outbox_staff` (`recipient_staff_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_preferences
CREATE TABLE `communication_preferences` (
  `staff_id` varchar(50) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `primary_channel_id` varchar(80) DEFAULT NULL,
  `fallback_channel_ids` longtext DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `quiet_hours_json` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_id`,`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_sessions
CREATE TABLE `communication_sessions` (
  `id` varchar(100) NOT NULL,
  `channel_identity_id` varchar(100) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_session_identity` (`channel_identity_id`),
  KEY `idx_comm_session_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_templates
CREATE TABLE `communication_templates` (
  `id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `channel_id` varchar(80) DEFAULT NULL,
  `language` varchar(20) NOT NULL DEFAULT 'id',
  `subject_template` varchar(255) DEFAULT NULL,
  `body_template` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_template` (`event_type`,`channel_id`,`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: communication_webhook_events
CREATE TABLE `communication_webhook_events` (
  `id` varchar(100) NOT NULL,
  `channel_id` varchar(80) NOT NULL,
  `provider_event_id` varchar(190) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'processing',
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_error` text DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comm_webhook_event` (`channel_id`,`provider_event_id`),
  KEY `idx_comm_webhook_status` (`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: config
CREATE TABLE `config` (
  `id` varchar(50) NOT NULL,
  `gemini_api_key` varchar(255) DEFAULT '',
  `telegram_bot_token` varchar(255) DEFAULT '',
  `initial_balance` decimal(15,2) DEFAULT 0.00,
  `target_investment` decimal(15,2) DEFAULT 0.00,
  `telegram_chat_ids` text DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT '',
  `bank_account` varchar(100) DEFAULT '',
  `bank_recipient` varchar(255) DEFAULT '',
  `qris_merchant_name` varchar(255) DEFAULT '',
  `qris_value` text DEFAULT NULL,
  `telegram_webhook_active` tinyint(1) DEFAULT 0,
  `db_type` varchar(50) DEFAULT 'mysql',
  `db_host` varchar(100) DEFAULT '',
  `db_port` varchar(50) DEFAULT '3306',
  `db_name` varchar(100) DEFAULT '',
  `db_user` varchar(100) DEFAULT '',
  `db_password` varchar(255) DEFAULT '',
  `is_seeded` tinyint(1) DEFAULT 0,
  `smtp_host` varchar(255) DEFAULT '',
  `smtp_port` int(11) DEFAULT 587,
  `smtp_user` varchar(255) DEFAULT '',
  `smtp_password` varchar(255) DEFAULT '',
  `smtp_secure` varchar(50) DEFAULT 'tls',
  `smtp_from` varchar(255) DEFAULT '',
  `telegram_webhook_secret` varchar(255) DEFAULT NULL,
  `server_revision` bigint(20) NOT NULL DEFAULT 0,
  `approval_required_sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `approval_threshold` decimal(15,2) NOT NULL DEFAULT 0.00,
  `telegram_webhook_url` varchar(500) DEFAULT NULL,
  `telegram_webhook_last_error` text DEFAULT NULL,
  `telegram_webhook_checked_at` datetime DEFAULT NULL,
  `cleanup_mode_until` datetime DEFAULT NULL,
  `cleanup_mode_enabled_by` varchar(50) DEFAULT NULL,
  `cleanup_mode_note` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: data_integrity_issues
CREATE TABLE `data_integrity_issues` (
  `id` varchar(100) NOT NULL,
  `issue_type` varchar(100) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` varchar(190) NOT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'warning',
  `details` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_integrity_issue` (`issue_type`,`entity_type`,`entity_id`),
  KEY `idx_integrity_issue_status` (`status`,`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: data_purge_batch_items
CREATE TABLE `data_purge_batch_items` (
  `id` varchar(100) NOT NULL,
  `batch_id` varchar(80) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(100) NOT NULL,
  `related_booking_id` varchar(100) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_hash` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purge_batch_entity` (`batch_id`,`entity_type`,`entity_id`),
  KEY `idx_purge_item_batch` (`batch_id`),
  KEY `idx_purge_item_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: data_purge_batches
CREATE TABLE `data_purge_batches` (
  `id` varchar(80) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'completed',
  `reason_category` varchar(50) NOT NULL,
  `reason` text NOT NULL,
  `backup_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `force_mode` tinyint(1) NOT NULL DEFAULT 0,
  `booking_count` int(11) NOT NULL DEFAULT 0,
  `standalone_transaction_count` int(11) NOT NULL DEFAULT 0,
  `transaction_count` int(11) NOT NULL DEFAULT 0,
  `gross_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_expense` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `affected_shift_count` int(11) NOT NULL DEFAULT 0,
  `affected_reconciliation_count` int(11) NOT NULL DEFAULT 0,
  `impact_json` longtext DEFAULT NULL,
  `snapshot_hash` varchar(64) NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `created_by_name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_purge_created` (`created_at`),
  KEY `idx_data_purge_staff` (`created_by`,`created_at`),
  KEY `idx_data_purge_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: financial_reporting_periods
CREATE TABLE `financial_reporting_periods` (
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


-- TABLE: guest_profiles
CREATE TABLE `guest_profiles` (
  `id` varchar(80) NOT NULL,
  `name` varchar(190) NOT NULL,
  `phone` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `identity_number` varchar(190) DEFAULT NULL,
  `preferences` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `blacklisted` tinyint(1) NOT NULL DEFAULT 0,
  `retention_until` date DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guest_phone` (`phone`),
  KEY `idx_guest_email` (`email`),
  KEY `idx_guest_blacklist` (`blacklisted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: guest_security_deposit_ledger
CREATE TABLE `guest_security_deposit_ledger` (
  `id` varchar(50) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `deposit_id` varchar(50) NOT NULL,
  `booking_id` varchar(50) NOT NULL,
  `entry_type` varchar(30) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(20) DEFAULT NULL,
  `bank_account_id` varchar(50) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `reason_type` varchar(30) DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `shift_session_id` varchar(80) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'web',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_security_deposit_operation` (`operation_id`),
  KEY `idx_guest_security_deposit_ledger_booking` (`booking_id`,`created_at`),
  KEY `idx_guest_security_deposit_ledger_deposit` (`deposit_id`,`created_at`),
  KEY `idx_guest_security_deposit_ledger_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: guest_security_deposits
CREATE TABLE `guest_security_deposits` (
  `id` varchar(50) NOT NULL,
  `booking_id` varchar(50) NOT NULL,
  `required_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `received_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `refunded_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `forfeited_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `held_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'not_required',
  `version` int(11) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_source` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_security_deposit_booking` (`booking_id`),
  KEY `idx_guest_security_deposit_status` (`status`,`held_balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: historical_backfill_adjustments
CREATE TABLE `historical_backfill_adjustments` (
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


-- TABLE: hotel_operational_settings
CREATE TABLE `hotel_operational_settings` (
  `id` varchar(40) NOT NULL,
  `checkin_time` time NOT NULL DEFAULT '14:00:00',
  `checkout_time` time NOT NULL DEFAULT '12:00:00',
  `shift_day_start` time NOT NULL DEFAULT '07:00:00',
  `shift_siang_start` time NOT NULL DEFAULT '15:00:00',
  `shift_malam_start` time NOT NULL DEFAULT '23:00:00',
  `checkout_reminder_minutes` int(11) NOT NULL DEFAULT 30,
  `late_grace_minutes` int(11) NOT NULL DEFAULT 60,
  `allow_early_checkin` tinyint(1) NOT NULL DEFAULT 1,
  `require_open_shift_for_sale` tinyint(1) NOT NULL DEFAULT 1,
  `require_key_control` tinyint(1) NOT NULL DEFAULT 1,
  `require_payment_before_key_issue` tinyint(1) NOT NULL DEFAULT 0,
  `require_night_audit_for_night_shift` tinyint(1) NOT NULL DEFAULT 1,
  `night_audit_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cash_variance_tolerance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `smart_lock_bridge_url` varchar(500) DEFAULT NULL,
  `smart_lock_bridge_token` text DEFAULT NULL,
  `smart_lock_bridge_timeout` int(11) NOT NULL DEFAULT 8,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: property_settings
CREATE TABLE `property_settings` (
  `id` varchar(40) NOT NULL,
  `company_id` varchar(80) DEFAULT NULL,
  `property_id` varchar(80) NOT NULL,
  `property_code` varchar(40) NOT NULL,
  `property_name` varchar(190) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `whatsapp` varchar(80) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `timezone` varchar(80) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `country_code` char(2) NOT NULL,
  `locale` varchar(20) NOT NULL DEFAULT 'id-ID',
  `invoice_prefix` varchar(30) NOT NULL,
  `accounting_basis` varchar(20) NOT NULL DEFAULT 'cash',
  `tax_setup_mode` varchar(30) NOT NULL DEFAULT 'pending',
  `payment_setup_mode` varchar(30) NOT NULL DEFAULT 'pending',
  `setup_status` varchar(30) NOT NULL DEFAULT 'identity_ready',
  `setup_version` int(11) NOT NULL DEFAULT 1,
  `configured_by` varchar(50) DEFAULT NULL,
  `configured_at` datetime DEFAULT NULL,
  `ready_by` varchar(50) DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_settings_property_id` (`property_id`),
  UNIQUE KEY `uq_property_settings_property_code` (`property_code`),
  KEY `idx_property_settings_company` (`company_id`,`setup_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: housekeeping_tasks
CREATE TABLE `housekeeping_tasks` (
  `id` varchar(80) NOT NULL,
  `room_number` varchar(50) NOT NULL,
  `assigned_to` varchar(150) DEFAULT NULL,
  `priority` varchar(30) NOT NULL DEFAULT 'normal',
  `status` varchar(30) NOT NULL DEFAULT 'dirty',
  `checklist` text DEFAULT NULL,
  `photos` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_staff_id` varchar(50) DEFAULT NULL,
  `completed_by_staff_id` varchar(50) DEFAULT NULL,
  `last_action_by_staff_id` varchar(50) DEFAULT NULL,
  `last_action_source` varchar(30) DEFAULT NULL,
  `active_room_key` varchar(50) GENERATED ALWAYS AS (case when `status` not in ('ready','completed') then `room_number` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hk_one_active_room` (`active_room_key`),
  KEY `idx_hk_room` (`room_number`,`status`),
  KEY `idx_hk_status` (`status`,`priority`),
  KEY `idx_hk_assigned_staff` (`assigned_staff_id`,`status`),
  KEY `idx_hk_completed_staff` (`completed_by_staff_id`,`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: inventory
CREATE TABLE `inventory` (
  `id` varchar(50) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `location` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit` varchar(20) NOT NULL DEFAULT 'Pcs',
  `condition_status` varchar(20) NOT NULL DEFAULT 'baik',
  `purchase_date` date DEFAULT NULL,
  `price` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by_staff_id` varchar(100) DEFAULT NULL,
  `updated_source` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: inventory_maintenance
CREATE TABLE `inventory_maintenance` (
  `id` varchar(50) NOT NULL,
  `inventory_id` varchar(50) NOT NULL,
  `maintenance_date` date NOT NULL,
  `action_taken` varchar(255) NOT NULL,
  `cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `staff_name` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by_staff_id` varchar(100) DEFAULT NULL,
  `updated_source` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_maintenance_date` (`maintenance_date` DESC),
  KEY `idx_maintenance_inventory` (`inventory_id`),
  KEY `idx_maintenance_date` (`maintenance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: journal_entries
CREATE TABLE `journal_entries` (
  `id` varchar(100) NOT NULL,
  `transaction_id` varchar(80) NOT NULL,
  `document_number` varchar(80) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'transaction_projection',
  `source_version` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `idx_journal_date` (`entry_date`),
  KEY `idx_journal_tx` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: journal_lines
CREATE TABLE `journal_lines` (
  `id` varchar(120) NOT NULL,
  `journal_entry_id` varchar(100) NOT NULL,
  `account_code` varchar(30) NOT NULL,
  `account_name` varchar(190) NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_line_entry` (`journal_entry_id`),
  KEY `idx_journal_account` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: lost_found_items
CREATE TABLE `lost_found_items` (
  `id` varchar(80) NOT NULL,
  `alert_id` varchar(80) DEFAULT NULL,
  `booking_id` varchar(100) DEFAULT NULL,
  `room_number` varchar(50) NOT NULL,
  `item_description` text NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'operational',
  `source_id` varchar(100) DEFAULT NULL,
  `custody_status` varchar(30) NOT NULL DEFAULT 'found',
  `storage_location` varchar(190) DEFAULT NULL,
  `custody_tag` varchar(100) DEFAULT NULL,
  `guest_notified_at` datetime DEFAULT NULL,
  `guest_notified_by` varchar(50) DEFAULT NULL,
  `resolution` varchar(30) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `returned_to` varchar(190) DEFAULT NULL,
  `disposed_at` datetime DEFAULT NULL,
  `found_at` datetime NOT NULL DEFAULT current_timestamp(),
  `found_by` varchar(50) DEFAULT NULL,
  `found_by_name` varchar(150) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lost_found_alert` (`alert_id`),
  KEY `idx_lost_found_room_status` (`room_number`,`custody_status`),
  KEY `idx_lost_found_booking` (`booking_id`,`created_at`),
  KEY `idx_lost_found_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: maintenance_tickets
CREATE TABLE `maintenance_tickets` (
  `id` varchar(80) NOT NULL,
  `asset_ref` varchar(190) NOT NULL,
  `title` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` varchar(30) NOT NULL DEFAULT 'normal',
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `assigned_to` varchar(150) DEFAULT NULL,
  `work_type` varchar(40) NOT NULL DEFAULT 'corrective',
  `origin_type` varchar(50) NOT NULL DEFAULT 'manual',
  `origin_id` varchar(100) DEFAULT NULL,
  `estimated_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `actual_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `resolution_note` text DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `photos` longtext DEFAULT NULL,
  `transaction_id` varchar(80) DEFAULT NULL,
  `approval_request_id` varchar(80) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mt_status` (`status`,`priority`),
  KEY `idx_mt_asset` (`asset_ref`),
  KEY `idx_mt_origin` (`origin_type`,`origin_id`),
  KEY `idx_mt_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: maintenance_cancellation_reviews
CREATE TABLE `maintenance_cancellation_reviews` (
  `id` varchar(80) NOT NULL,
  `alert_id` varchar(80) DEFAULT NULL,
  `ticket_id` varchar(80) DEFAULT NULL,
  `room_number` varchar(50) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `decision` varchar(40) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'maintenance-cancelled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maintenance_cancel_review_alert` (`alert_id`),
  UNIQUE KEY `uq_maintenance_cancel_review_ticket` (`ticket_id`),
  KEY `idx_maintenance_cancel_review_room` (`room_number`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: night_audit_items
CREATE TABLE `night_audit_items` (
  `id` varchar(90) NOT NULL,
  `audit_id` varchar(80) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `system_room_status` varchar(30) DEFAULT NULL,
  `active_booking_id` varchar(80) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `physical_occupancy` varchar(20) NOT NULL DEFAULT 'unchecked',
  `observed_key_status` varchar(30) NOT NULL DEFAULT 'unchecked',
  `discrepancy_type` varchar(100) DEFAULT NULL,
  `resolution_status` varchar(30) NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `checked_by` varchar(50) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_night_item_audit` (`audit_id`,`room_number`),
  KEY `idx_night_item_discrepancy` (`discrepancy_type`,`resolution_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: night_audit_runs
CREATE TABLE `night_audit_runs` (
  `id` varchar(80) NOT NULL,
  `audit_date` date NOT NULL,
  `shift_session_id` varchar(100) DEFAULT NULL,
  `staff_id` varchar(50) NOT NULL,
  `staff_name` varchar(150) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `total_rooms` int(11) NOT NULL DEFAULT 0,
  `checked_rooms` int(11) NOT NULL DEFAULT 0,
  `occupancy_discrepancies` int(11) NOT NULL DEFAULT 0,
  `key_discrepancies` int(11) NOT NULL DEFAULT 0,
  `cash_discrepancies` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_night_audit_date` (`audit_date`,`status`),
  KEY `idx_night_audit_shift` (`shift_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_cluster_events
CREATE TABLE `node_cluster_events` (
  `id` varchar(100) NOT NULL,
  `cluster_id` varchar(100) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `from_node_id` varchar(100) DEFAULT NULL,
  `to_node_id` varchar(100) DEFAULT NULL,
  `leadership_epoch` bigint(20) NOT NULL DEFAULT 0,
  `reason` text DEFAULT NULL,
  `actor_staff_id` varchar(50) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cluster_events_time` (`cluster_id`,`created_at`),
  KEY `idx_cluster_events_epoch` (`cluster_id`,`leadership_epoch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_cluster_members
CREATE TABLE `node_cluster_members` (
  `node_id` varchar(100) NOT NULL,
  `cluster_id` varchar(100) NOT NULL,
  `node_kind` varchar(30) NOT NULL DEFAULT 'unknown',
  `public_url` varchar(500) DEFAULT NULL,
  `peer_url` varchar(500) DEFAULT NULL,
  `effective_role` varchar(30) NOT NULL DEFAULT 'standby',
  `member_status` varchar(30) NOT NULL DEFAULT 'standby_ready',
  `leadership_epoch` bigint(20) NOT NULL DEFAULT 0,
  `server_revision` bigint(20) NOT NULL DEFAULT 0,
  `pending_outbox` int(11) NOT NULL DEFAULT 0,
  `open_conflicts` int(11) NOT NULL DEFAULT 0,
  `last_seen_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`node_id`),
  KEY `idx_cluster_members` (`cluster_id`,`effective_role`,`member_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_cluster_state
CREATE TABLE `node_cluster_state` (
  `id` varchar(50) NOT NULL,
  `cluster_id` varchar(100) NOT NULL,
  `current_primary_node_id` varchar(100) NOT NULL,
  `current_primary_url` varchar(500) DEFAULT NULL,
  `leadership_epoch` bigint(20) NOT NULL DEFAULT 1,
  `fencing_token` varchar(100) NOT NULL,
  `transfer_state` varchar(30) NOT NULL DEFAULT 'active',
  `transfer_target_node_id` varchar(100) DEFAULT NULL,
  `split_brain_risk` tinyint(1) NOT NULL DEFAULT 0,
  `lease_owner_node_id` varchar(100) DEFAULT NULL,
  `lease_id` varchar(100) DEFAULT NULL,
  `lease_renewed_at` datetime DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `last_peer_probe_at` datetime DEFAULT NULL,
  `last_peer_probe_error` text DEFAULT NULL,
  `last_change_reason` text DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cluster_primary` (`current_primary_node_id`,`leadership_epoch`),
  KEY `idx_cluster_state` (`transfer_state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_conflicts
CREATE TABLE `node_sync_conflicts` (
  `id` varchar(100) NOT NULL,
  `event_id` varchar(100) NOT NULL,
  `origin_node_id` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `actor_staff_id` varchar(50) DEFAULT NULL,
  `base_primary_revision` bigint(20) NOT NULL DEFAULT 0,
  `primary_revision` bigint(20) NOT NULL DEFAULT 0,
  `reason` text NOT NULL,
  `local_payload` longtext DEFAULT NULL,
  `primary_response` longtext DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_node_conflict_event` (`event_id`),
  KEY `idx_node_conflict_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_nonces
CREATE TABLE `node_sync_nonces` (
  `nonce` varchar(100) NOT NULL,
  `node_id` varchar(100) NOT NULL,
  `used_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`nonce`),
  KEY `idx_node_nonce_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_outbox
CREATE TABLE `node_sync_outbox` (
  `event_id` varchar(100) NOT NULL,
  `origin_node_id` varchar(100) NOT NULL,
  `actor_staff_id` varchar(50) DEFAULT NULL,
  `device_id` varchar(190) DEFAULT NULL,
  `session_id` varchar(80) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `http_method` varchar(10) NOT NULL,
  `query_json` longtext DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `operation_id` varchar(100) DEFAULT NULL,
  `base_primary_revision` bigint(20) NOT NULL DEFAULT 0,
  `mutation_counter_start` bigint(20) NOT NULL DEFAULT 0,
  `mutation_counter` bigint(20) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_http_status` int(11) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `response_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_node_outbox_status` (`status`,`created_at`),
  KEY `idx_node_outbox_actor` (`actor_staff_id`,`created_at`),
  KEY `idx_node_outbox_operation` (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_receipts
CREATE TABLE `node_sync_receipts` (
  `event_id` varchar(100) NOT NULL,
  `origin_node_id` varchar(100) NOT NULL,
  `actor_staff_id` varchar(50) DEFAULT NULL,
  `device_id` varchar(190) DEFAULT NULL,
  `session_id` varchar(80) DEFAULT NULL,
  `operation_id` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `http_method` varchar(10) DEFAULT NULL,
  `query_hash` varchar(64) DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'processing',
  `http_status` int(11) DEFAULT NULL,
  `response_json` longtext DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_node_receipt_status` (`status`,`updated_at`),
  KEY `idx_node_receipt_operation` (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_runs
CREATE TABLE `node_sync_runs` (
  `id` varchar(100) NOT NULL,
  `node_id` varchar(100) NOT NULL,
  `direction` varchar(20) NOT NULL,
  `status` varchar(30) NOT NULL,
  `pushed_count` int(11) NOT NULL DEFAULT 0,
  `pulled_table_count` int(11) NOT NULL DEFAULT 0,
  `pulled_row_count` int(11) NOT NULL DEFAULT 0,
  `conflict_count` int(11) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_runs_time` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: node_sync_settings
CREATE TABLE `node_sync_settings` (
  `id` varchar(50) NOT NULL,
  `node_id` varchar(100) NOT NULL,
  `node_role` varchar(30) NOT NULL DEFAULT 'online_primary',
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_primary_revision` bigint(20) NOT NULL DEFAULT 0,
  `local_mutation_counter` bigint(20) NOT NULL DEFAULT 0,
  `last_mirrored_local_counter` bigint(20) NOT NULL DEFAULT 0,
  `mutation_guard_ready` tinyint(1) NOT NULL DEFAULT 0,
  `mirror_in_progress` tinyint(1) NOT NULL DEFAULT 0,
  `mirror_started_at` datetime DEFAULT NULL,
  `local_security_initialized` tinyint(1) NOT NULL DEFAULT 0,
  `last_push_at` datetime DEFAULT NULL,
  `last_pull_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: notification_reads
CREATE TABLE `notification_reads` (
  `notification_id` varchar(50) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`notification_id`,`staff_id`),
  KEY `idx_notification_reads_staff` (`staff_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: notifications
CREATE TABLE `notifications` (
  `id` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `timestamp` datetime NOT NULL,
  `read` tinyint(1) DEFAULT 0,
  `type` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_timestamp` (`timestamp` DESC),
  KEY `idx_notifications_time` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: number_sequences
CREATE TABLE `number_sequences` (
  `sequence_key` varchar(100) NOT NULL,
  `last_number` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: ota_disbursement_items
CREATE TABLE `ota_disbursement_items` (
  `id` varchar(100) NOT NULL,
  `disbursement_id` varchar(80) NOT NULL,
  `receivable_transaction_id` varchar(50) NOT NULL,
  `booking_id` varchar(50) DEFAULT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ota_disbursement_receivable` (`disbursement_id`,`receivable_transaction_id`),
  KEY `idx_ota_item_disbursement` (`disbursement_id`),
  KEY `idx_ota_item_receivable` (`receivable_transaction_id`),
  KEY `idx_ota_item_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: ota_disbursements
CREATE TABLE `ota_disbursements` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `disbursement_date` date NOT NULL,
  `bank_account_id` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `commission_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `source_transaction_id` varchar(50) NOT NULL,
  `destination_transaction_id` varchar(50) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'completed',
  `created_by` varchar(50) NOT NULL,
  `created_by_name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ota_disbursement_operation` (`operation_id`),
  KEY `idx_ota_disbursement_date` (`disbursement_date`),
  KEY `idx_ota_disbursement_bank` (`bank_account_id`),
  KEY `idx_ota_disbursement_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_categories
CREATE TABLE `pos_categories` (
  `id` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `version` int(11) NOT NULL DEFAULT 1,
  `created_by` varchar(80) DEFAULT NULL,
  `updated_by` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_category_name` (`name`),
  KEY `idx_pos_category_active` (`is_active`,`sort_order`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_delivery_events
CREATE TABLE `pos_delivery_events` (
  `id` varchar(80) NOT NULL,
  `sale_id` varchar(80) NOT NULL,
  `from_status` varchar(30) NOT NULL,
  `to_status` varchar(30) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `received_by_name` varchar(190) DEFAULT NULL,
  `operation_id` varchar(120) NOT NULL,
  `acted_by` varchar(80) NOT NULL,
  `acted_by_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_delivery_operation` (`operation_id`),
  KEY `idx_pos_delivery_sale` (`sale_id`,`created_at`),
  KEY `idx_pos_delivery_status` (`to_status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_print_logs
CREATE TABLE `pos_print_logs` (
  `id` varchar(80) NOT NULL,
  `sale_id` varchar(80) NOT NULL,
  `print_type` varchar(40) NOT NULL,
  `paper_width` smallint(6) NOT NULL DEFAULT 80,
  `requested_copies` tinyint(4) NOT NULL DEFAULT 1,
  `copy_label` varchar(20) NOT NULL DEFAULT 'ORIGINAL',
  `operation_id` varchar(120) NOT NULL,
  `printed_by` varchar(80) NOT NULL,
  `printed_by_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_print_operation` (`operation_id`),
  KEY `idx_pos_print_sale` (`sale_id`,`created_at`),
  KEY `idx_pos_print_type` (`print_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_products
CREATE TABLE `pos_products` (
  `id` varchar(80) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `name` varchar(190) NOT NULL,
  `category_id` varchar(80) DEFAULT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'pcs',
  `cost_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `min_stock` decimal(15,3) NOT NULL DEFAULT 0.000,
  `tax_kind` varchar(80) NOT NULL DEFAULT 'extra',
  `barcode` varchar(120) DEFAULT NULL,
  `location` varchar(120) NOT NULL DEFAULT 'Front Office / Minibar',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `version` int(11) NOT NULL DEFAULT 1,
  `created_by` varchar(80) DEFAULT NULL,
  `updated_by` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_product_sku` (`sku`),
  UNIQUE KEY `uq_pos_product_barcode` (`barcode`),
  KEY `idx_pos_product_active` (`is_active`,`name`),
  KEY `idx_pos_product_category` (`category_id`,`is_active`),
  KEY `idx_pos_product_stock` (`stock_quantity`,`min_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_sale_items
CREATE TABLE `pos_sale_items` (
  `id` varchar(80) NOT NULL,
  `sale_id` varchar(80) NOT NULL,
  `product_id` varchar(80) NOT NULL,
  `sku` varchar(80) NOT NULL,
  `product_name` varchar(190) NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_gross_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(15,2) NOT NULL,
  `base_amount` decimal(15,2) NOT NULL,
  `tax_amount` decimal(15,2) NOT NULL,
  `tax_rate` decimal(7,3) NOT NULL DEFAULT 0.000,
  `tax_rule_id` varchar(80) DEFAULT NULL,
  `tax_kind` varchar(80) NOT NULL DEFAULT 'extra',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pos_item_sale` (`sale_id`,`id`),
  KEY `idx_pos_item_product` (`product_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_sales
CREATE TABLE `pos_sales` (
  `id` varchar(80) NOT NULL,
  `receipt_number` varchar(80) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `payment_method` varchar(30) NOT NULL,
  `bank_account_id` varchar(80) DEFAULT NULL,
  `booking_id` varchar(80) DEFAULT NULL,
  `room_number` varchar(30) DEFAULT NULL,
  `guest_name` varchar(190) DEFAULT NULL,
  `subtotal_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_type` varchar(20) NOT NULL DEFAULT 'none',
  `discount_value` decimal(15,3) NOT NULL DEFAULT 0.000,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `discount_authorized_by` varchar(80) DEFAULT NULL,
  `discount_authorized_by_name` varchar(190) DEFAULT NULL,
  `gross_amount` decimal(15,2) NOT NULL,
  `base_amount` decimal(15,2) NOT NULL,
  `tax_amount` decimal(15,2) NOT NULL,
  `cost_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_count` int(11) NOT NULL DEFAULT 0,
  `transaction_id` varchar(80) DEFAULT NULL,
  `cogs_transaction_id` varchar(80) DEFAULT NULL,
  `booking_extra_id` varchar(80) DEFAULT NULL,
  `operation_id` varchar(120) NOT NULL,
  `notes` text DEFAULT NULL,
  `sale_date` date NOT NULL,
  `created_by` varchar(80) NOT NULL,
  `created_by_name` varchar(190) NOT NULL,
  `voided_by` varchar(80) DEFAULT NULL,
  `voided_by_name` varchar(190) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delivery_status` varchar(30) NOT NULL DEFAULT 'not_required',
  `delivery_note` varchar(255) DEFAULT NULL,
  `prepared_by` varchar(80) DEFAULT NULL,
  `prepared_by_name` varchar(190) DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `dispatched_by` varchar(80) DEFAULT NULL,
  `dispatched_by_name` varchar(190) DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `delivered_by` varchar(80) DEFAULT NULL,
  `delivered_by_name` varchar(190) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `received_by_name` varchar(190) DEFAULT NULL,
  `delivery_updated_at` datetime DEFAULT NULL,
  `print_count` int(11) NOT NULL DEFAULT 0,
  `last_printed_at` datetime DEFAULT NULL,
  `last_printed_by` varchar(80) DEFAULT NULL,
  `last_printed_by_name` varchar(190) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_sale_receipt` (`receipt_number`),
  UNIQUE KEY `uq_pos_sale_operation` (`operation_id`),
  KEY `idx_pos_sale_date` (`sale_date`,`status`),
  KEY `idx_pos_sale_booking` (`booking_id`,`status`),
  KEY `idx_pos_sale_creator` (`created_by`,`created_at`),
  KEY `idx_pos_sales_delivery` (`delivery_status`,`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: pos_stock_movements
CREATE TABLE `pos_stock_movements` (
  `id` varchar(80) NOT NULL,
  `product_id` varchar(80) NOT NULL,
  `sale_id` varchar(80) DEFAULT NULL,
  `movement_type` varchar(40) NOT NULL,
  `quantity_delta` decimal(15,3) NOT NULL,
  `quantity_before` decimal(15,3) NOT NULL,
  `quantity_after` decimal(15,3) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reference` varchar(190) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `operation_id` varchar(120) NOT NULL,
  `created_by` varchar(80) NOT NULL,
  `created_by_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pos_stock_operation` (`operation_id`,`product_id`,`movement_type`),
  KEY `idx_pos_stock_product` (`product_id`,`created_at`),
  KEY `idx_pos_stock_sale` (`sale_id`,`movement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: promotion_codes
CREATE TABLE `promotion_codes` (
  `id` varchar(80) NOT NULL,
  `code` varchar(80) NOT NULL,
  `title` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` varchar(20) NOT NULL DEFAULT 'fixed',
  `discount_value` decimal(15,3) NOT NULL DEFAULT 0.000,
  `min_spend` decimal(15,2) NOT NULL DEFAULT 0.00,
  `max_discount` decimal(15,2) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `applies_to` varchar(20) NOT NULL DEFAULT 'both',
  `auto_apply` tinyint(1) NOT NULL DEFAULT 0,
  `event_name` varchar(190) DEFAULT NULL,
  `stackable` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(50) DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `operation_id` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_promo_code_status_validity` (`status`,`valid_from`,`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: promotion_code_usages
CREATE TABLE `promotion_code_usages` (
  `id` varchar(80) NOT NULL,
  `promotion_code_id` varchar(80) DEFAULT NULL,
  `code` varchar(80) DEFAULT NULL,
  `usage_type` varchar(20) NOT NULL DEFAULT 'unknown',
  `source_type` varchar(20) NOT NULL DEFAULT 'unknown',
  `source` varchar(20) NOT NULL DEFAULT 'unknown',
  `actor_id` varchar(50) DEFAULT NULL,
  `actor_name` varchar(190) DEFAULT NULL,
  `actor` varchar(190) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `discount_amount` decimal(15,2) DEFAULT NULL,
  `operation_id` varchar(100) NOT NULL,
  `property_id` varchar(80) DEFAULT NULL,
  `company_id` varchar(80) DEFAULT NULL,
  `branch_id` varchar(80) DEFAULT NULL,
  `entity_type` varchar(20) DEFAULT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promo_usage_operation` (`operation_id`),
  KEY `idx_promo_usage_code` (`promotion_code_id`,`created_at`),
  KEY `idx_promo_usage_type` (`usage_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_promotions
CREATE TABLE `public_promotions` (
  `id` varchar(80) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `title` varchar(190) NOT NULL,
  `summary` text DEFAULT NULL,
  `details` longtext DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `cta_label` varchar(100) DEFAULT NULL,
  `cta_url` varchar(500) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_by` varchar(50) DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_public_promo_status` (`status`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_reservation_requests
CREATE TABLE `public_reservation_requests` (
  `id` varchar(80) NOT NULL,
  `public_request_id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `guest_name` varchar(100) NOT NULL,
  `whatsapp` varchar(30) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `guest_count` int(11) NOT NULL DEFAULT 1,
  `room_type_id` varchar(80) NOT NULL,
  `extra_request` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `promo_code` varchar(80) DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'website',
  `status` varchar(30) NOT NULL DEFAULT 'pending_review',
  `request_fingerprint` varchar(64) DEFAULT NULL,
  `user_agent_hash` varchar(64) DEFAULT NULL,
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `linked_booking_id` varchar(80) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_request_id` (`public_request_id`),
  UNIQUE KEY `operation_id` (`operation_id`),
  KEY `idx_public_request_status` (`status`,`created_at`),
  KEY `idx_public_request_dates` (`check_in`,`check_out`),
  KEY `idx_public_request_room_type` (`room_type_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_room_types
CREATE TABLE `public_room_types` (
  `id` varchar(80) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `name` varchar(190) NOT NULL,
  `short_description` text DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `price_from` decimal(15,2) NOT NULL DEFAULT 0.00,
  `capacity` int(11) NOT NULL DEFAULT 2,
  `room_size` varchar(50) DEFAULT NULL,
  `bed_type` varchar(100) DEFAULT NULL,
  `facilities_json` longtext DEFAULT NULL,
  `rules_json` longtext DEFAULT NULL,
  `images_json` longtext DEFAULT NULL,
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `public_status` varchar(30) NOT NULL DEFAULT 'draft',
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `updated_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_public_room_status` (`public_status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_site_media
CREATE TABLE `public_site_media` (
  `id` varchar(80) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `media_kind` varchar(40) NOT NULL DEFAULT 'gallery',
  `file_url` varchar(700) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `status` varchar(30) NOT NULL DEFAULT 'published',
  `uploaded_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_public_media_property` (`property_id`,`status`,`media_kind`,`sort_order`),
  KEY `idx_public_media_uploader` (`uploaded_by`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_site_settings
CREATE TABLE `public_site_settings` (
  `id` varchar(50) NOT NULL,
  `hotel_name` varchar(190) NOT NULL,
  `slogan` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `whatsapp` varchar(50) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `favicon_url` varchar(500) DEFAULT NULL,
  `hero_image_url` varchar(500) DEFAULT NULL,
  `hero_mobile_image_url` varchar(500) DEFAULT NULL,
  `hero_eyebrow` varchar(190) DEFAULT NULL,
  `hero_title` varchar(255) DEFAULT NULL,
  `hero_subtitle` text DEFAULT NULL,
  `primary_cta_text` varchar(100) DEFAULT NULL,
  `primary_cta_url` varchar(500) DEFAULT NULL,
  `secondary_cta_text` varchar(100) DEFAULT NULL,
  `secondary_cta_url` varchar(500) DEFAULT NULL,
  `check_in_policy` text DEFAULT NULL,
  `cancellation_policy` text DEFAULT NULL,
  `social_links_json` longtext DEFAULT NULL,
  `facilities_json` longtext DEFAULT NULL,
  `gallery_json` longtext DEFAULT NULL,
  `faq_json` longtext DEFAULT NULL,
  `theme_json` longtext DEFAULT NULL,
  `section_config_json` longtext DEFAULT NULL,
  `seo_json` longtext DEFAULT NULL,
  `section_copy_json` longtext DEFAULT NULL,
  `map_url` varchar(700) DEFAULT NULL,
  `footer_text` varchar(500) DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `published_by` varchar(50) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_support_conversations
CREATE TABLE `public_support_conversations` (
  `id` varchar(80) NOT NULL,
  `public_code` varchar(80) NOT NULL,
  `visitor_token_hash` char(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'open',
  `source` varchar(24) NOT NULL DEFAULT 'website',
  `assigned_staff_id` varchar(50) DEFAULT NULL,
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_code` (`public_code`),
  KEY `idx_public_support_status` (`status`,`last_message_at`),
  KEY `idx_public_support_assigned` (`assigned_staff_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: public_support_messages
CREATE TABLE `public_support_messages` (
  `id` varchar(100) NOT NULL,
  `conversation_id` varchar(80) NOT NULL,
  `sender` varchar(20) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `telegram_user_id` varchar(100) DEFAULT NULL,
  `ai_provider` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_public_support_messages` (`conversation_id`,`created_at`,`id`),
  KEY `idx_public_support_sender` (`sender`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: rate_limits
CREATE TABLE `rate_limits` (
  `scope_key` varchar(190) NOT NULL,
  `window_start` datetime NOT NULL,
  `hit_count` int(11) NOT NULL DEFAULT 0,
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scope_key`),
  KEY `idx_rate_blocked` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: reconciliation_items
CREATE TABLE `reconciliation_items` (
  `id` varchar(80) NOT NULL,
  `source` varchar(80) NOT NULL,
  `reference` varchar(190) DEFAULT NULL,
  `transaction_id` varchar(80) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'unmatched',
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `matched_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `matched_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rec_status` (`status`,`transaction_date`),
  KEY `idx_rec_tx` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: request_operation_receipts
CREATE TABLE `request_operation_receipts` (
  `operation_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `device_id` varchar(190) NOT NULL,
  `session_id` varchar(80) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `http_method` varchar(10) NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `cluster_epoch` bigint(20) DEFAULT NULL,
  `fencing_token_hash` varchar(64) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'processing',
  `http_status` int(11) DEFAULT NULL,
  `response_body` longtext DEFAULT NULL,
  `error_message` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`operation_id`),
  KEY `idx_request_receipt_actor` (`staff_id`,`device_id`,`created_at`),
  KEY `idx_request_receipt_status` (`status`,`updated_at`),
  KEY `idx_request_receipt_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: operational_entity_links
CREATE TABLE `operational_entity_links` (
  `id` varchar(80) NOT NULL,
  `from_entity_type` varchar(80) NOT NULL,
  `from_entity_id` varchar(190) NOT NULL,
  `to_entity_type` varchar(80) NOT NULL,
  `to_entity_id` varchar(190) NOT NULL,
  `relation` varchar(80) NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operational_entity_relation` (`from_entity_type`,`from_entity_id`,`to_entity_type`,`to_entity_id`,`relation`),
  KEY `idx_operational_link_from` (`from_entity_type`,`from_entity_id`),
  KEY `idx_operational_link_to` (`to_entity_type`,`to_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: room_access_control
CREATE TABLE `room_access_control` (
  `room_number` varchar(20) NOT NULL,
  `access_mode` varchar(20) NOT NULL DEFAULT 'physical',
  `physical_key_ref` varchar(100) DEFAULT NULL,
  `physical_key_status` varchar(30) NOT NULL DEFAULT 'secured',
  `current_booking_id` varchar(80) DEFAULT NULL,
  `smart_lock_provider` varchar(100) DEFAULT NULL,
  `smart_lock_device_id` varchar(190) DEFAULT NULL,
  `smart_lock_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_event_at` datetime DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`room_number`),
  KEY `idx_room_access_booking` (`current_booking_id`),
  KEY `idx_room_access_status` (`physical_key_status`,`access_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: room_key_events
CREATE TABLE `room_key_events` (
  `id` varchar(80) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `booking_id` varchar(80) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `access_mode` varchar(20) NOT NULL DEFAULT 'physical',
  `physical_key_ref` varchar(100) DEFAULT NULL,
  `smart_code_last4` varchar(4) DEFAULT NULL,
  `credential_status` varchar(30) DEFAULT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `staff_name` varchar(150) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `device_id` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_key_event_room` (`room_number`,`created_at`),
  KEY `idx_key_event_booking` (`booking_id`,`created_at`),
  KEY `idx_key_event_staff` (`staff_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: operational_incidents
CREATE TABLE `operational_incidents` (
  `id` varchar(80) NOT NULL,
  `incident_type` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `severity` varchar(30) NOT NULL DEFAULT 'warning',
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `room_number` varchar(50) DEFAULT NULL,
  `area_ref` varchar(190) DEFAULT NULL,
  `blocks_room` tinyint(1) NOT NULL DEFAULT 0,
  `hold_id` varchar(80) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'manual',
  `source_id` varchar(100) DEFAULT NULL,
  `reported_by` varchar(50) DEFAULT NULL,
  `reported_by_name` varchar(150) DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assigned_to` varchar(50) DEFAULT NULL,
  `acknowledged_by` varchar(50) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_operational_incident_status` (`status`,`severity`,`reported_at`),
  KEY `idx_operational_incident_room` (`room_number`,`status`),
  KEY `idx_operational_incident_source` (`source_type`,`source_id`),
  KEY `idx_operational_incident_hold` (`hold_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: guest_service_requests
CREATE TABLE `guest_service_requests` (
  `id` varchar(80) NOT NULL,
  `booking_id` varchar(100) DEFAULT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `context_type` varchar(40) NOT NULL DEFAULT 'legacy_unspecified',
  `guest_name_snapshot` varchar(150) DEFAULT NULL,
  `requester_name` varchar(150) DEFAULT NULL,
  `requester_contact` varchar(190) DEFAULT NULL,
  `location_label` varchar(150) DEFAULT NULL,
  `request_channel` varchar(40) NOT NULL DEFAULT 'staff_recorded',
  `needed_at` datetime DEFAULT NULL,
  `context_verified_at` datetime DEFAULT NULL,
  `context_verified_by` varchar(50) DEFAULT NULL,
  `request_type` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `priority` varchar(30) NOT NULL DEFAULT 'normal',
  `department` varchar(80) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `assigned_to` varchar(50) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'operations_center',
  `source_id` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `fulfilled_by` varchar(50) DEFAULT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_service_source` (`source_type`,`source_id`),
  KEY `idx_guest_service_booking` (`booking_id`,`status`),
  KEY `idx_guest_service_room` (`room_number`,`status`),
  KEY `idx_guest_service_context` (`context_type`,`status`,`created_at`),
  KEY `idx_guest_service_needed` (`status`,`needed_at`),
  KEY `idx_guest_service_queue` (`status`,`priority`,`created_at`),
  KEY `idx_guest_service_assignee` (`assigned_to`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: room_operational_holds
CREATE TABLE `room_operational_holds` (
  `id` varchar(80) NOT NULL,
  `room_number` varchar(50) NOT NULL,
  `hold_type` varchar(80) NOT NULL,
  `severity` varchar(30) NOT NULL DEFAULT 'critical',
  `reason` text NOT NULL,
  `source_entity_type` varchar(80) DEFAULT NULL,
  `source_entity_id` varchar(190) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `released_by` varchar(50) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `release_note` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_room_hold_open` (`room_number`,`status`),
  KEY `idx_room_hold_source` (`source_entity_type`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: room_vacancy_reports
CREATE TABLE `room_vacancy_reports` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `booking_id` varchar(100) NOT NULL,
  `room_number` varchar(50) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `key_observation` varchar(30) NOT NULL DEFAULT 'unknown',
  `belongings_status` varchar(30) NOT NULL DEFAULT 'unknown',
  `belongings_detail` text DEFAULT NULL,
  `damage_status` varchar(30) NOT NULL DEFAULT 'unknown',
  `damage_detail` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `photos` longtext DEFAULT NULL,
  `observed_at` datetime NOT NULL,
  `reported_by` varchar(50) NOT NULL,
  `reported_by_name` varchar(150) NOT NULL,
  `reported_role` varchar(50) NOT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'web',
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `checkout_operation_id` varchar(100) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_vacancy_operation` (`operation_id`),
  KEY `idx_room_vacancy_status` (`status`,`reported_at`),
  KEY `idx_room_vacancy_booking` (`booking_id`,`status`),
  KEY `idx_room_vacancy_room` (`room_number`,`status`),
  KEY `idx_room_vacancy_reporter` (`reported_by`,`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- TABLE: rooms
CREATE TABLE `rooms` (
  `id` varchar(50) NOT NULL,
  `number` varchar(10) NOT NULL,
  `type` varchar(50) NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'available',
  `floor` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `updatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updatedBy` varchar(100) DEFAULT NULL,
  `updatedSource` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`number`),
  KEY `idx_rooms_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: runtime_request_events
CREATE TABLE `runtime_request_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(80) NOT NULL,
  `operation_hash` char(64) DEFAULT NULL,
  `action` varchar(100) NOT NULL DEFAULT '',
  `http_method` varchar(10) NOT NULL DEFAULT 'GET',
  `stage` varchar(100) NOT NULL DEFAULT 'unknown',
  `failed_stage` varchar(100) DEFAULT NULL,
  `http_status` int(11) NOT NULL DEFAULT 200,
  `outcome` varchar(20) NOT NULL DEFAULT 'success',
  `staff_id` varchar(50) DEFAULT NULL,
  `staff_role` varchar(50) DEFAULT NULL,
  `node_id` varchar(100) DEFAULT NULL,
  `duration_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `error_reference` varchar(32) DEFAULT NULL,
  `error_class` varchar(100) DEFAULT NULL,
  `source_file` varchar(255) DEFAULT NULL,
  `source_line` int(10) unsigned DEFAULT NULL,
  `error_severity` int(11) DEFAULT NULL,
  `sql_state` varchar(10) DEFAULT NULL,
  `safe_message` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_runtime_request` (`request_id`),
  KEY `idx_runtime_created` (`created_at`),
  KEY `idx_runtime_status` (`http_status`,`created_at`),
  KEY `idx_runtime_action` (`action`,`created_at`),
  KEY `idx_runtime_staff` (`staff_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: salary_slips
CREATE TABLE `salary_slips` (
  `id` varchar(50) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `period` varchar(50) NOT NULL,
  `basic_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `allowances` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `detailed_allowances` text DEFAULT NULL,
  `detailed_deductions` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT NULL,
  `bank_account_id` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `reversal_transaction_id` varchar(100) DEFAULT NULL,
  `correction_of_slip_id` varchar(80) DEFAULT NULL,
  `corrected_by_slip_id` varchar(80) DEFAULT NULL,
  `correction_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_salary_slips_created` (`created_at` DESC),
  KEY `idx_salary_staff_period` (`staff_id`,`period`),
  KEY `idx_salary_status` (`status`,`updated_at`),
  KEY `idx_salary_correction` (`correction_of_slip_id`,`corrected_by_slip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: schema_migrations
CREATE TABLE `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: schema_release_state
CREATE TABLE `schema_release_state` (
  `id` varchar(50) NOT NULL,
  `current_release` varchar(100) NOT NULL,
  `patch_level` varchar(150) NOT NULL,
  `source_checksum` char(64) DEFAULT NULL,
  `migration_run_id` varchar(80) DEFAULT NULL,
  `maintenance_required` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_schema_release_run` (`migration_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: security_events
CREATE TABLE `security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(80) NOT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `staff_id` varchar(50) DEFAULT NULL,
  `request_id` varchar(80) DEFAULT NULL,
  `ip_hash` char(64) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `context_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_security_event_time` (`event_type`,`created_at`),
  KEY `idx_security_staff` (`staff_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: shift_reports
CREATE TABLE `shift_reports` (
  `id` varchar(50) NOT NULL,
  `staffId` varchar(50) DEFAULT NULL,
  `companionStaffId` varchar(50) DEFAULT NULL,
  `shiftSessionId` varchar(100) DEFAULT NULL,
  `staffName` varchar(100) NOT NULL,
  `shiftDate` date NOT NULL,
  `shiftTime` varchar(20) NOT NULL,
  `startingCash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `expectedCash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `actualPhysicalCash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `variance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `digitalRevenue` decimal(15,2) NOT NULL DEFAULT 0.00,
  `transactionsCount` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift_reports_session` (`shiftSessionId`),
  KEY `idx_shift_reports_date` (`shiftDate` DESC),
  KEY `idx_shift_reports_staff` (`staffName`),
  KEY `idx_shift_reports_staff_id` (`staffId`,`shiftDate`),
  KEY `idx_shift_reports_companion` (`companionStaffId`,`shiftDate`),
  KEY `idx_shift_reports_staff_name` (`staffName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: shift_sessions
CREATE TABLE `shift_sessions` (
  `id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `companion_staff_id` varchar(50) DEFAULT NULL,
  `companion_staff_name` varchar(150) DEFAULT NULL,
  `shift_date` date NOT NULL,
  `shift_time` varchar(20) NOT NULL,
  `opening_cash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `expected_cash` decimal(15,2) DEFAULT 0.00,
  `actual_cash` decimal(15,2) DEFAULT NULL,
  `variance` decimal(15,2) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `opened_at` timestamp NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  `cash_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cash_expense` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `night_audit_id` varchar(80) DEFAULT NULL,
  `key_discrepancy_count` int(11) NOT NULL DEFAULT 0,
  `occupancy_discrepancy_count` int(11) NOT NULL DEFAULT 0,
  `close_override_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shift_staff_status` (`staff_id`,`status`),
  KEY `idx_shift_date_time` (`shift_date`,`shift_time`),
  KEY `idx_shift_opened` (`opened_at`),
  KEY `idx_shift_companion_status` (`companion_staff_id`,`status`),
  KEY `idx_shift_participants_date` (`staff_id`,`companion_staff_id`,`shift_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: smart_lock_jobs
CREATE TABLE `smart_lock_jobs` (
  `id` varchar(80) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `booking_id` varchar(80) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `provider` varchar(100) DEFAULT NULL,
  `device_id` varchar(190) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_smart_job_status` (`status`,`created_at`),
  KEY `idx_smart_job_booking` (`booking_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: staff
CREATE TABLE `staff` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'receptionist',
  `job_title` varchar(30) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `telegram_chat_id` varchar(100) DEFAULT NULL,
  `salary` decimal(15,2) DEFAULT 0.00,
  `leave_start` date DEFAULT NULL,
  `leave_end` date DEFAULT NULL,
  `leave_reason` text DEFAULT NULL,
  `telegram_state` varchar(100) DEFAULT NULL,
  `telegram_context` text DEFAULT NULL,
  `two_factor_active` tinyint(1) DEFAULT 0,
  `two_factor_expires` datetime DEFAULT NULL,
  `permissions` text DEFAULT NULL,
  `leave_type` varchar(50) DEFAULT NULL,
  `two_factor_code_hash` varchar(255) DEFAULT NULL,
  `two_factor_challenge_hash` varchar(64) DEFAULT NULL,
  `two_factor_device_id` varchar(190) DEFAULT NULL,
  `failed_login_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_locked_until` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `require_password_change` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_staff_job_title` (`job_title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- TABLE: role_capability_presets (Fase 2 "Kelola Jabatan")
-- Paket capability/tab/notifikasi per jabatan sebagai data; akun hanya berubah lewat
-- aksi eksplisit "Terapkan ke jabatan" (route 052). Seed = preset editor staf saat ini
-- (cermin PA(role,null)/Eh(role) app-core.js); owner tidak di-seed (paket read-only 017).
CREATE TABLE IF NOT EXISTS `role_capability_presets` (
  `role` varchar(40) NOT NULL,
  `permissions` text NOT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- INSERT IGNORE: paket yang sudah pernah disimpan pemilik tidak ditimpa bila setup diulang.
INSERT IGNORE INTO `role_capability_presets` (`role`,`permissions`) VALUES
('admin', '{"desktopTabs":{"dashboard":true,"rooms":true,"finance":true,"savings":true,"report":true,"operations":true,"website":true,"support":true,"telegram":true,"staff":true,"config":true,"db_config":true,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":true,"inventory":true,"operations":true,"hr":true,"system":true},"capabilities":{"approve_sensitive_actions":true,"manage_sessions":true,"resolve_sync_conflict":true,"manage_tax_rules":true,"manage_backup":true,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":true,"manage_operational_settings":true,"override_key_checkout":true,"manage_public_website":true,"view_reports":true,"view_support_inbox":true,"manage_facilities":true,"daily_summary":true}}'),
('manager', '{"desktopTabs":{"dashboard":true,"rooms":true,"finance":false,"savings":true,"report":true,"operations":true,"website":true,"support":true,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":true,"inventory":true,"operations":true,"hr":true,"system":true},"capabilities":{"approve_sensitive_actions":true,"manage_sessions":true,"resolve_sync_conflict":true,"manage_tax_rules":true,"manage_backup":false,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":true,"manage_operational_settings":true,"override_key_checkout":true,"manage_public_website":true,"view_reports":true,"view_support_inbox":true,"manage_facilities":true,"daily_summary":true}}'),
('finance', '{"desktopTabs":{"dashboard":true,"rooms":false,"finance":true,"savings":true,"report":true,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":true,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":true,"reconcile_payments":true,"correct_booking_audit":true,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":true,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}'),
('receptionist', '{"desktopTabs":{"dashboard":false,"rooms":true,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":true,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":true,"finance":false,"inventory":true,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":true,"manage_room_access":true,"sell_room_access":true,"manage_stay_access":true,"receive_payment_access":true,"perform_night_audit":true,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":true,"manage_facilities":false,"daily_summary":true}}'),
('cleaning_service', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":true,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":true,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}'),
('keamanan', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":true,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":true,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":true,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}'),
('koki', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":true,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":true,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}'),
('tukang_kebun', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}'),
('lain_lain', '{"desktopTabs":{"dashboard":false,"rooms":false,"finance":false,"savings":true,"report":false,"operations":true,"website":false,"support":false,"telegram":true,"staff":false,"config":false,"db_config":false,"inventory":false,"leaves":true,"attendance":true},"telegramNotifications":{"bookings":false,"finance":false,"inventory":false,"operations":false,"hr":false,"system":false},"capabilities":{"approve_sensitive_actions":false,"manage_sessions":false,"resolve_sync_conflict":false,"manage_tax_rules":false,"manage_backup":false,"view_audit_log":false,"reconcile_payments":false,"correct_booking_audit":false,"view_guest_identity":false,"manage_room_access":false,"sell_room_access":false,"manage_stay_access":false,"receive_payment_access":false,"perform_night_audit":false,"perform_housekeeping":false,"resolve_night_audit":false,"manage_operational_settings":false,"override_key_checkout":false,"manage_public_website":false,"view_reports":false,"view_support_inbox":false,"manage_facilities":false,"daily_summary":false}}');

-- TABLE: staff_job_titles (R19 "Jabatan Dinamis")
-- Daftar jabatan yang dapat ditambah/diubah/dihapus dari UI. Setiap jabatan adalah
-- alias tampilan + paket izin di atas salah satu 9 role kanonik (`base_role`); kolom
-- staff.role tetap menyimpan role basis sehingga enforcement 017/lama tidak berubah.
-- 9 baris sistem = role kanonik (tidak dapat dihapus; key = base_role).
CREATE TABLE IF NOT EXISTS `staff_job_titles` (
  `title_key` varchar(30) NOT NULL,
  `label` varchar(60) NOT NULL,
  `base_role` varchar(20) NOT NULL DEFAULT 'lain_lain',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`title_key`),
  KEY `idx_staff_job_titles_base` (`base_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `staff_job_titles` (`title_key`,`label`,`base_role`,`is_system`,`sort_order`) VALUES
('admin','Admin','admin',1,10),
('manager','Manajer','manager',1,20),
('finance','Keuangan','finance',1,30),
('receptionist','Resepsionis','receptionist',1,40),
('cleaning_service','Cleaning Service','cleaning_service',1,50),
('keamanan','Keamanan','keamanan',1,60),
('koki','Koki','koki',1,70),
('tukang_kebun','Tukang Kebun','tukang_kebun',1,80),
('lain_lain','Lain-lain','lain_lain',1,90);



-- TABLE: rbac_* (Dynamic RBAC registry + access group bridge)
-- TAMASYA Dynamic RBAC
-- Registry role/capability/tab/notifikasi dan access group untuk enforcement.
-- Idempoten: CREATE TABLE IF NOT EXISTS + INSERT IGNORE. Data runtime dibaca
-- oleh api/support/022_dynamic_rbac.php; fallback statis hanya untuk instalasi
-- yang belum menjalankan migrasi ini.

CREATE TABLE IF NOT EXISTS `rbac_roles` (
  `role_key` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_key`),
  KEY `idx_rbac_roles_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_capabilities` (
  `capability_key` varchar(64) NOT NULL,
  `label` varchar(160) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`capability_key`),
  KEY `idx_rbac_capabilities_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_desktop_tabs` (
  `tab_key` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tab_key`),
  KEY `idx_rbac_tabs_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_notification_groups` (
  `group_key` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_key`),
  KEY `idx_rbac_notification_groups_active` (`active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `rbac_roles` (`role_key`,`label`,`is_owner`,`is_system`,`sort_order`) VALUES
('owner','Owner',1,1,0),
('admin','Admin',0,1,10),
('manager','Manajer',0,1,20),
('finance','Keuangan',0,1,30),
('receptionist','Resepsionis',0,1,40),
('cleaning_service','Cleaning Service',0,1,50),
('keamanan','Keamanan',0,1,60),
('koki','Koki',0,1,70),
('tukang_kebun','Tukang Kebun',0,1,80),
('lain_lain','Lain-lain',0,1,90);

INSERT IGNORE INTO `rbac_capabilities` (`capability_key`,`label`,`is_system`,`sort_order`) VALUES
('approve_sensitive_actions','Setujui transaksi sensitif',1,10),
('manage_sessions','Kelola session perangkat',1,20),
('resolve_sync_conflict','Selesaikan konflik sync',1,30),
('manage_tax_rules','Kelola aturan pajak',1,40),
('manage_backup','Backup dan restore',1,50),
('view_audit_log','Lihat audit log',1,60),
('reconcile_payments','Rekonsiliasi pembayaran',1,70),
('correct_booking_audit','Koreksi Reservasi & Transaksi Audit',1,80),
('view_guest_identity','Lihat KTP Tamu (tercatat audit)',1,90),
('manage_room_access','Serahkan/Kembalikan Kunci & PIN Smart Lock',1,100),
('sell_room_access','Jual Kamar & Reservasi (Telegram & Web)',1,110),
('manage_stay_access','Perpanjang, Pindah & Check-out (Telegram & Web)',1,120),
('receive_payment_access','Terima Panjar/DP (Telegram & Web)',1,130),
('perform_night_audit','Melakukan Pemeriksaan Night Audit',1,140),
('perform_housekeeping','Menjalankan Tugas Housekeeping',1,150),
('resolve_night_audit','Menyelesaikan Temuan Night Audit',1,160),
('manage_operational_settings','Mengatur Jam Check-out & Smart Lock',1,170),
('override_key_checkout','Override Check-out Tanpa Kunci Kembali',1,180),
('manage_public_website','Kelola dan Terbitkan Website Publik',1,190),
('view_reports','Lihat & Kirim Laporan Kanonik',1,200),
('view_support_inbox','Kelola Kotak Masuk Publik (Bantuan & Reservasi)',1,210),
('manage_facilities','Kelola Master Fasilitas (Tipe & Unit)',1,220),
('daily_summary','Lihat Ringkasan Harian Operasional',1,230);

INSERT IGNORE INTO `rbac_desktop_tabs` (`tab_key`,`label`,`is_system`,`sort_order`) VALUES
('dashboard','Dasbor',1,10),
('rooms','Kamar & Sewa',1,20),
('finance','Kas & Transaksi',1,30),
('savings','Simpanan Karyawan',1,40),
('report','Laporan',1,50),
('operations','Pusat Operasional',1,60),
('website','Website Publik',1,70),
('support','Layanan Tamu Publik',1,80),
('telegram','Pusat Tugas Harian',1,90),
('staff','Kelola Staf',1,100),
('config','Set Kredensial',1,110),
('db_config','Koneksi Database',1,120),
('inventory','Aset & Inventaris',1,130),
('leaves','Pengajuan Cuti',1,140),
('attendance','Absensi Karyawan',1,150);

INSERT IGNORE INTO `rbac_notification_groups` (`group_key`,`label`,`is_system`,`sort_order`) VALUES
('bookings','Reservasi & Kamar',1,10),
('finance','Keuangan & Gaji',1,20),
('inventory','Aset & Perbaikan',1,30),
('operations','Operasional & Housekeeping',1,40),
('hr','SDM, Absensi & Cuti',1,50),
('system','Sistem & Keamanan Akun',1,60);

CREATE TABLE IF NOT EXISTS `rbac_access_groups` (
  `group_key` varchar(64) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_access_group_roles` (
  `group_key` varchar(64) NOT NULL,
  `role_key` varchar(40) NOT NULL,
  PRIMARY KEY (`group_key`,`role_key`),
  KEY `idx_rbac_access_group_roles_role` (`role_key`),
  CONSTRAINT `fk_rbac_access_group_roles_group` FOREIGN KEY (`group_key`) REFERENCES `rbac_access_groups` (`group_key`) ON DELETE CASCADE,
  CONSTRAINT `fk_rbac_access_group_roles_role` FOREIGN KEY (`role_key`) REFERENCES `rbac_roles` (`role_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbac_legacy_role_sets` (
  `role_set` varchar(255) NOT NULL,
  `group_key` varchar(64) NOT NULL,
  PRIMARY KEY (`role_set`),
  KEY `idx_rbac_legacy_role_sets_group` (`group_key`),
  CONSTRAINT `fk_rbac_legacy_role_sets_group` FOREIGN KEY (`group_key`) REFERENCES `rbac_access_groups` (`group_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `rbac_access_groups` (`group_key`,`label`,`is_system`) VALUES
('admin_only','Admin',1),
('management','Admin + Manajer',1),
('finance_ops','Admin + Manajer + Keuangan',1),
('finance_ops_owner','Admin + Manajer + Keuangan + Owner',1),
('front_desk_management','Admin + Manajer + Resepsionis',1),
('all_staff','Semua staf operasional',1),
('finance_front_desk','Admin + Manajer + Keuangan + Resepsionis',1),
('admin_owner','Admin + Owner',1),
('front_desk_cleaning','Admin + Manajer + Resepsionis + Cleaning Service',1),
('admin_manager_owner','Admin + Manajer + Owner',1),
('finance_ops_cleaning','Admin + Manajer + Keuangan + Cleaning Service',1),
('security_front_desk','Admin + Manajer + Resepsionis + Keamanan',1),
('admin_finance_owner','Admin + Keuangan + Owner',1),
('admin_finance','Admin + Keuangan',1);

INSERT IGNORE INTO `rbac_access_group_roles` (`group_key`,`role_key`) VALUES
('admin_only','admin'),
('management','admin'),
('management','manager'),
('finance_ops','admin'),
('finance_ops','finance'),
('finance_ops','manager'),
('finance_ops_owner','admin'),
('finance_ops_owner','finance'),
('finance_ops_owner','manager'),
('finance_ops_owner','owner'),
('front_desk_management','admin'),
('front_desk_management','manager'),
('front_desk_management','receptionist'),
('all_staff','admin'),
('all_staff','cleaning_service'),
('all_staff','finance'),
('all_staff','keamanan'),
('all_staff','koki'),
('all_staff','lain_lain'),
('all_staff','manager'),
('all_staff','receptionist'),
('all_staff','tukang_kebun'),
('finance_front_desk','admin'),
('finance_front_desk','finance'),
('finance_front_desk','manager'),
('finance_front_desk','receptionist'),
('admin_owner','admin'),
('admin_owner','owner'),
('front_desk_cleaning','admin'),
('front_desk_cleaning','cleaning_service'),
('front_desk_cleaning','manager'),
('front_desk_cleaning','receptionist'),
('admin_manager_owner','admin'),
('admin_manager_owner','manager'),
('admin_manager_owner','owner'),
('finance_ops_cleaning','admin'),
('finance_ops_cleaning','cleaning_service'),
('finance_ops_cleaning','finance'),
('finance_ops_cleaning','manager'),
('security_front_desk','admin'),
('security_front_desk','keamanan'),
('security_front_desk','manager'),
('security_front_desk','receptionist'),
('admin_finance_owner','admin'),
('admin_finance_owner','finance'),
('admin_finance_owner','owner'),
('admin_finance','admin'),
('admin_finance','finance');

INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
('admin','admin_only'),
('admin,manager','management'),
('admin,finance,manager','finance_ops'),
('admin,finance,manager,owner','finance_ops_owner'),
('admin,manager,receptionist','front_desk_management'),
('admin,cleaning_service,finance,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff'),
('admin,finance,manager,receptionist','finance_front_desk'),
('admin,owner','admin_owner'),
('admin,cleaning_service,manager,receptionist','front_desk_cleaning'),
('admin,manager,owner','admin_manager_owner'),
('admin,cleaning_service,finance,manager','finance_ops_cleaning'),
('admin,keamanan,manager,receptionist','security_front_desk'),
('admin,finance,owner','admin_finance_owner'),
('admin,finance','admin_finance');



-- TABLE: staff_biometric_profiles
CREATE TABLE `staff_biometric_profiles` (
  `id` varchar(50) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `biometric_type` varchar(30) NOT NULL,
  `provider` varchar(100) NOT NULL,
  `provider_profile_id` varchar(190) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `enrolled_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_biometric_profile` (`staff_id`,`biometric_type`),
  KEY `idx_staff_biometric_status` (`status`,`biometric_type`),
  CONSTRAINT `fk_staff_biometric_profile_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: staff_leave_requests
CREATE TABLE `staff_leave_requests` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `staff_name` varchar(150) NOT NULL,
  `leave_type` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_requested` int(11) NOT NULL DEFAULT 1,
  `reason` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_by` varchar(50) DEFAULT NULL,
  `decided_by_name` varchar(150) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_leave_operation` (`operation_id`),
  KEY `idx_staff_leave_staff_status` (`staff_id`,`status`,`start_date`),
  KEY `idx_staff_leave_pending` (`status`,`start_date`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: staff_savings_accounts
CREATE TABLE `staff_savings_accounts` (
  `staff_id` varchar(50) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_id`),
  KEY `idx_staff_savings_account_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: staff_savings_ledger
CREATE TABLE `staff_savings_ledger` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `receipt_number` varchar(80) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `entry_type` varchar(30) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_before` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `payment_method` varchar(30) NOT NULL DEFAULT 'cash',
  `storage_reference` varchar(190) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `request_id` varchar(80) DEFAULT NULL,
  `reference_entry_id` varchar(80) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'posted',
  `created_by` varchar(50) NOT NULL,
  `created_by_name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_savings_ledger_operation` (`operation_id`),
  UNIQUE KEY `uq_staff_savings_ledger_receipt` (`receipt_number`),
  UNIQUE KEY `uq_staff_savings_ledger_request` (`request_id`),
  KEY `idx_staff_savings_ledger_staff` (`staff_id`,`created_at`),
  KEY `idx_staff_savings_ledger_type` (`entry_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: staff_savings_requests
CREATE TABLE `staff_savings_requests` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `purpose` text NOT NULL,
  `payout_method` varchar(30) NOT NULL DEFAULT 'cash',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `requested_by` varchar(50) NOT NULL,
  `requested_by_name` varchar(150) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `decision_notes` text DEFAULT NULL,
  `paid_by` varchar(50) DEFAULT NULL,
  `paid_by_name` varchar(150) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancelled_by_name` varchar(150) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `ledger_entry_id` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_savings_request_operation` (`operation_id`),
  UNIQUE KEY `uq_staff_savings_request_ledger` (`ledger_entry_id`),
  KEY `idx_staff_savings_request_staff` (`staff_id`,`status`,`requested_at`),
  KEY `idx_staff_savings_request_status` (`status`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: subcategories
CREATE TABLE `subcategories` (
  `id` varchar(50) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category_id` varchar(50) NOT NULL,
  `system_key` varchar(80) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcategory` (`category_name`,`name`),
  UNIQUE KEY `uq_subcategory_category_name` (`category_id`,`name`),
  UNIQUE KEY `uq_subcategory_system_key` (`system_key`),
  KEY `idx_subcategories_active` (`category_id`,`is_active`,`name`),
  KEY `idx_subcategories_legacy_name` (`category_name`,`name`),
  CONSTRAINT `fk_subcategories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: sync_devices
CREATE TABLE `sync_devices` (
  `device_id` varchar(190) NOT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `device_name` varchar(190) DEFAULT NULL,
  `app_version` varchar(50) DEFAULT NULL,
  `pending_count` int(11) NOT NULL DEFAULT 0,
  `conflict_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'registered',
  `last_sync_at` datetime DEFAULT NULL,
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`),
  KEY `idx_sync_seen` (`last_seen`),
  KEY `idx_sync_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: sync_operations
CREATE TABLE `sync_operations` (
  `operation_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(100) NOT NULL,
  `action` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `device_id` varchar(190) DEFAULT NULL,
  `payload_hash` varchar(64) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'processed',
  `result_json` longtext DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`operation_id`),
  KEY `idx_sync_device` (`device_id`),
  KEY `idx_sync_status` (`status`,`created_at`),
  KEY `idx_sync_staff` (`staff_id`),
  KEY `idx_sync_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: sync_tombstones
CREATE TABLE `sync_tombstones` (
  `id` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(100) NOT NULL,
  `purge_batch_id` varchar(80) NOT NULL,
  `deleted_by` varchar(50) NOT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_tombstone_entity` (`entity_type`,`entity_id`),
  KEY `idx_sync_tombstone_deleted` (`deleted_at`),
  KEY `idx_sync_tombstone_batch` (`purge_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: system_alerts
CREATE TABLE `system_alerts` (
  `id` varchar(80) NOT NULL,
  `severity` varchar(30) NOT NULL DEFAULT 'warning',
  `source` varchar(80) NOT NULL DEFAULT 'system',
  `title` varchar(190) NOT NULL,
  `message` text NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(190) DEFAULT NULL,
  `acknowledged_by` varchar(50) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alert_open` (`acknowledged_at`,`severity`),
  KEY `idx_alert_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: tax_rules
CREATE TABLE `tax_rules` (
  `id` varchar(80) NOT NULL,
  `name` varchar(190) NOT NULL,
  `source_pattern` varchar(190) NOT NULL DEFAULT '*',
  `transaction_kind` varchar(80) NOT NULL DEFAULT '*',
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `rate` decimal(7,3) NOT NULL DEFAULT 0.000,
  `priority` int(11) NOT NULL DEFAULT 0,
  `effective_from` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tax_active` (`is_active`,`priority`),
  KEY `idx_tax_period` (`effective_from`,`effective_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_binding_codes
CREATE TABLE `telegram_binding_codes` (
  `id` varchar(80) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_telegram_user_id` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  KEY `idx_tg_bind_staff` (`staff_id`,`expires_at`,`used_at`),
  KEY `idx_tg_bind_exp` (`expires_at`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_binding_conflict_archive
CREATE TABLE `telegram_binding_conflict_archive` (
  `archive_key` char(64) NOT NULL,
  `telegram_user_id` varchar(100) NOT NULL,
  `telegram_chat_id` varchar(100) DEFAULT NULL,
  `staff_id` varchar(50) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `archive_reason` varchar(100) NOT NULL,
  `source_node_id` varchar(100) DEFAULT NULL,
  `archived_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`archive_key`),
  KEY `idx_tg_binding_archive_staff` (`staff_id`,`archived_at`),
  KEY `idx_tg_binding_archive_user` (`telegram_user_id`,`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_bindings
CREATE TABLE `telegram_bindings` (
  `telegram_user_id` varchar(100) NOT NULL,
  `telegram_chat_id` varchar(100) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `verified_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`telegram_user_id`),
  UNIQUE KEY `uq_telegram_binding_staff` (`staff_id`),
  KEY `idx_telegram_binding_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_callback_tokens
CREATE TABLE `telegram_callback_tokens` (
  `token` varchar(40) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `callback_data` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_tg_callback_staff_expiry` (`staff_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_messages
CREATE TABLE `telegram_messages` (
  `id` varchar(50) NOT NULL,
  `sender` varchar(50) NOT NULL,
  `text` text NOT NULL,
  `timestamp` datetime NOT NULL,
  `isAi` tinyint(1) DEFAULT 0,
  `telegram_user_id` varchar(100) DEFAULT NULL,
  `staff_id` varchar(50) DEFAULT NULL,
  `command` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_messages_timestamp` (`timestamp` DESC),
  KEY `idx_telegram_time` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: telegram_update_log
CREATE TABLE `telegram_update_log` (
  `update_id` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'processing',
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`update_id`),
  KEY `idx_tg_update_status` (`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: test_data_purge_logs
CREATE TABLE `test_data_purge_logs` (
  `id` varchar(80) NOT NULL,
  `entity_type` varchar(50) NOT NULL DEFAULT 'booking',
  `entity_id` varchar(100) NOT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `transaction_count` int(11) NOT NULL DEFAULT 0,
  `gross_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_expense` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` text NOT NULL,
  `snapshot_hash` varchar(64) NOT NULL,
  `purged_by` varchar(50) NOT NULL,
  `purged_by_name` varchar(150) NOT NULL,
  `purged_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_test_purge_entity` (`entity_type`,`entity_id`),
  KEY `idx_test_purge_date` (`purged_at`),
  KEY `idx_test_purge_staff` (`purged_by`,`purged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: transaction_allocations
CREATE TABLE IF NOT EXISTS `guest_reviews` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `token` varchar(80) DEFAULT NULL,
  `room_number` varchar(40) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `rating` tinyint(1) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'invited',
  `published` tinyint(1) NOT NULL DEFAULT 0,
  `invited_by` varchar(50) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_review_token` (`token`),
  KEY `idx_guest_review_booking` (`booking_id`),
  KEY `idx_guest_review_status` (`property_id`,`status`),
  KEY `idx_guest_review_rating` (`property_id`,`published`,`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `guest_review_invites` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `token` varchar(80) NOT NULL,
  `room_number` varchar(40) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_review_invite_token` (`token`),
  KEY `idx_guest_review_invite_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `wholesale_rate_plans` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `room_type` varchar(80) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `valid_from` date NOT NULL,
  `valid_to` date NOT NULL,
  `min_nights` tinyint(3) NOT NULL DEFAULT 1,
  `max_nights` tinyint(3) NOT NULL DEFAULT 30,
  `cut_off_days` tinyint(3) NOT NULL DEFAULT 0,
  `include_tax` tinyint(1) NOT NULL DEFAULT 1,
  `breakfast_included` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wholesale_plan_code` (`property_id`,`code`),
  KEY `idx_wholesale_plan_window` (`property_id`,`status`,`valid_from`,`valid_to`),
  KEY `idx_wholesale_plan_company` (`property_id`,`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `wholesale_rate_nights` (
  `plan_id` varchar(64) NOT NULL,
  `stay_date` date NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `min_available` int(11) NOT NULL DEFAULT 0,
  `closed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`plan_id`,`stay_date`),
  KEY `idx_wholesale_night_date` (`stay_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `wholesale_rate_bookings` (
  `id` varchar(64) NOT NULL,
  `property_id` varchar(80) NOT NULL,
  `booking_id` varchar(64) NOT NULL,
  `plan_id` varchar(64) NOT NULL,
  `plan_code` varchar(40) DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `stay_from` date NOT NULL,
  `stay_to` date NOT NULL,
  `nightly_price` decimal(15,2) NOT NULL,
  `nights` tinyint(3) NOT NULL,
  `nightly_breakdown` text DEFAULT NULL,
  `total` decimal(15,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wholesale_booking` (`property_id`,`booking_id`),
  KEY `idx_wholesale_booking_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `exchange_rates` (
  `id` varchar(64) NOT NULL,
  `base_currency` varchar(10) NOT NULL,
  `quote_currency` varchar(10) NOT NULL,
  `rate` decimal(18,8) NOT NULL,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_rate_day` (`base_currency`,`quote_currency`,`rate_date`),
  KEY `idx_exchange_rate_pair` (`base_currency`,`quote_currency`,`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaction_allocations` (
  `id` varchar(80) NOT NULL,
  `operation_id` varchar(100) NOT NULL,
  `transaction_id` varchar(100) NOT NULL,
  `booking_id` varchar(100) NOT NULL,
  `allocation_type` varchar(30) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(7,3) NOT NULL DEFAULT 0.000,
  `category` varchar(100) DEFAULT NULL,
  `category_id` varchar(50) DEFAULT NULL,
  `category_system_key` varchar(80) DEFAULT NULL,
  `subcategory` varchar(100) DEFAULT NULL,
  `subcategory_id` varchar(50) DEFAULT NULL,
  `subcategory_system_key` varchar(80) DEFAULT NULL,
  `booking_extra_id` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `booking_total_delta` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_check_out` date DEFAULT NULL,
  `new_check_out` date DEFAULT NULL,
  `extra_was_existing` tinyint(1) NOT NULL DEFAULT 0,
  `transaction_booking_link_added` tinyint(1) NOT NULL DEFAULT 0,
  `transaction_room_link_added` tinyint(1) NOT NULL DEFAULT 0,
  `transaction_source_link_added` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voided_by` varchar(50) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `reporting_only` tinyint(1) NOT NULL DEFAULT 0,
  `tax_rule_id` varchar(100) DEFAULT NULL,
  `tax_snapshot_status` varchar(30) NOT NULL DEFAULT 'unresolved',
  `tax_source` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_allocation_operation` (`operation_id`),
  KEY `idx_transaction_allocation_tx` (`transaction_id`,`status`),
  KEY `idx_transaction_allocation_booking` (`booking_id`,`status`),
  KEY `idx_transaction_allocation_extra` (`booking_extra_id`,`status`),
  KEY `idx_transaction_allocation_category` (`category_id`,`category_system_key`),
  KEY `idx_transaction_allocation_subcategory` (`subcategory_id`),
  CONSTRAINT `fk_transaction_allocation_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_transaction_allocation_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- TABLE: transactions
CREATE TABLE `transactions` (
  `id` varchar(50) NOT NULL,
  `type` varchar(10) NOT NULL,
  `category` varchar(100) NOT NULL,
  `categoryId` varchar(50) DEFAULT NULL,
  `categorySystemKey` varchar(80) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `exchangeRate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `propertyAmount` decimal(15,2) DEFAULT NULL,
  `date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `createdBy` varchar(50) DEFAULT 'staff',
  `createdAt` timestamp NULL DEFAULT current_timestamp(),
  `subcategory` varchar(100) DEFAULT NULL,
  `subcategoryId` varchar(50) DEFAULT NULL,
  `subcategorySystemKey` varchar(80) DEFAULT NULL,
  `roomNumber` varchar(50) DEFAULT NULL,
  `proofUrl` longtext DEFAULT NULL,
  `bankAccountId` varchar(50) DEFAULT NULL,
  `bookingId` varchar(50) DEFAULT NULL,
  `baseAmount` decimal(15,2) DEFAULT NULL,
  `taxAmount` decimal(15,2) DEFAULT NULL,
  `taxRate` decimal(7,3) DEFAULT NULL,
  `bookingSource` varchar(255) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `updatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updatedBy` varchar(100) DEFAULT NULL,
  `updatedSource` varchar(30) DEFAULT NULL,
  `transactionKind` varchar(40) NOT NULL DEFAULT 'manual',
  `sourceEntity` varchar(50) DEFAULT NULL,
  `sourceEntityId` varchar(100) DEFAULT NULL,
  `isSystemGenerated` tinyint(1) NOT NULL DEFAULT 0,
  `operationId` varchar(100) DEFAULT NULL,
  `shiftSessionId` varchar(80) DEFAULT NULL,
  `lockedAt` datetime DEFAULT NULL,
  `reconciliationStatus` varchar(30) DEFAULT NULL,
  `reconciliationReference` varchar(190) DEFAULT NULL,
  `documentNumber` varchar(80) DEFAULT NULL,
  `importBatchId` varchar(100) DEFAULT NULL,
  `recordOrigin` varchar(30) NOT NULL DEFAULT 'live_operation',
  `shiftExempt` tinyint(1) NOT NULL DEFAULT 0,
  `shiftExemptionReason` varchar(255) DEFAULT NULL,
  `taxNote` varchar(255) DEFAULT NULL,
  `taxRuleId` varchar(100) DEFAULT NULL,
  `taxRuleName` varchar(150) DEFAULT NULL,
  `taxSnapshotStatus` varchar(30) NOT NULL DEFAULT 'unresolved',
  `taxSource` varchar(40) DEFAULT NULL,
  `serviceDate` date DEFAULT NULL,
  `historicalSourceType` varchar(30) DEFAULT NULL,
  `historicalSourceReference` varchar(160) DEFAULT NULL,
  `sourceReportedBy` varchar(120) DEFAULT NULL,
  `reportingPeriod` char(7) DEFAULT NULL,
  `periodStatusAtEntry` varchar(30) NOT NULL DEFAULT 'open',
  `periodImpactStatus` varchar(40) NOT NULL DEFAULT 'normal',
  `requiresTaxAmendment` tinyint(1) NOT NULL DEFAULT 0,
  `historicalReviewStatus` varchar(30) NOT NULL DEFAULT 'not_required',
  `historicalReviewedBy` varchar(50) DEFAULT NULL,
  `historicalReviewedAt` datetime DEFAULT NULL,
  `periodCorrectionReason` varchar(255) DEFAULT NULL,
  `inputDelayDays` int(11) NOT NULL DEFAULT 0,
  `isSplitPayment` tinyint(1) NOT NULL DEFAULT 0,
  `splitCashAmount` decimal(15,2) DEFAULT NULL,
  `splitTransferAmount` decimal(15,2) DEFAULT NULL,
  `splitTransferBankAccountId` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_transactions_operation` (`operationId`),
  UNIQUE KEY `uniq_transactions_document_number` (`documentNumber`),
  KEY `idx_transactions_type` (`type`),
  KEY `idx_transactions_category_id` (`categoryId`),
  KEY `idx_transactions_subcategory_id` (`subcategoryId`),
  KEY `idx_transactions_category_semantic` (`categorySystemKey`,`type`),
  KEY `idx_transactions_date` (`date`),
  KEY `idx_transactions_booking` (`bookingId`),
  KEY `idx_transactions_kind` (`transactionKind`),
  KEY `idx_transactions_financial_guard` (`recordOrigin`,`type`,`transactionKind`,`taxSnapshotStatus`),
  KEY `idx_transactions_tax_status` (`recordOrigin`,`taxSnapshotStatus`,`date`),
  KEY `idx_transactions_backfill_period` (`recordOrigin`,`reportingPeriod`,`periodImpactStatus`),
  KEY `idx_transactions_backfill_review` (`historicalReviewStatus`,`requiresTaxAmendment`,`date`),
  CONSTRAINT `fk_transactions_category` FOREIGN KEY (`categoryId`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_transactions_subcategory` FOREIGN KEY (`subcategoryId`) REFERENCES `subcategories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Canonical financial consistency guards. These are baseline schema behavior,
-- not migration patches. The database never auto-confirms taxable live income:
-- live rule provenance is supplied and verified by the application transaction
-- before commit. This trigger only normalizes explicitly non-tax financial kinds;
-- historical unknown tax remains unresolved instead of being invented as 0%.
CREATE TRIGGER `trg_transactions_tax_snapshot_bi`
BEFORE INSERT ON `transactions`
FOR EACH ROW
SET
  NEW.`categoryId` = COALESCE(NULLIF(NEW.`categoryId`,''),(SELECT c.id FROM categories c WHERE c.type=NEW.`type` AND LOWER(TRIM(c.name))=LOWER(TRIM(NEW.`category`)) AND c.is_active=1 ORDER BY c.id LIMIT 1)),
  NEW.`categorySystemKey` = COALESCE(NULLIF(NEW.`categorySystemKey`,''),(SELECT c.system_key FROM categories c WHERE c.id=COALESCE(NULLIF(NEW.`categoryId`,''),(SELECT c2.id FROM categories c2 WHERE c2.type=NEW.`type` AND LOWER(TRIM(c2.name))=LOWER(TRIM(NEW.`category`)) AND c2.is_active=1 ORDER BY c2.id LIMIT 1)) LIMIT 1)),
  NEW.`subcategoryId` = COALESCE(NULLIF(NEW.`subcategoryId`,''),(SELECT s.id FROM subcategories s WHERE s.category_id=NEW.`categoryId` AND LOWER(TRIM(s.name))=LOWER(TRIM(COALESCE(NEW.`subcategory`,''))) AND s.is_active=1 ORDER BY s.id LIMIT 1)),
  NEW.`subcategorySystemKey` = COALESCE(NULLIF(NEW.`subcategorySystemKey`,''),(SELECT s.system_key FROM subcategories s WHERE s.id=NEW.`subcategoryId` LIMIT 1)),
  NEW.`taxSnapshotStatus` = CASE
    WHEN NEW.`recordOrigin`='historical_import' THEN NEW.`taxSnapshotStatus`
    WHEN NEW.`type`<>'income' AND COALESCE(NEW.`taxSnapshotStatus`,'unresolved')='unresolved' THEN 'not_applicable'
    WHEN NEW.`type`='income' AND (
      NEW.`transactionKind` IN ('security_deposit_received','security_deposit_forfeit','salary_reversal','opening_balance_cash','opening_balance_bank')
      OR NEW.`transactionKind` LIKE '%ota_transfer%'
      OR NEW.`transactionKind` LIKE '%internal_transfer%'
    ) AND COALESCE(NEW.`taxSnapshotStatus`,'unresolved')='unresolved' THEN 'not_applicable'
    ELSE NEW.`taxSnapshotStatus`
  END,
  NEW.`taxSource` = CASE
    WHEN NEW.`recordOrigin`='historical_import' THEN NEW.`taxSource`
    WHEN NEW.`type`<>'income' AND COALESCE(NEW.`taxSource`,'')='' THEN 'not_applicable'
    WHEN NEW.`type`='income' AND (
      NEW.`transactionKind` IN ('security_deposit_received','security_deposit_forfeit','salary_reversal','opening_balance_cash','opening_balance_bank')
      OR NEW.`transactionKind` LIKE '%ota_transfer%'
      OR NEW.`transactionKind` LIKE '%internal_transfer%'
    ) AND COALESCE(NEW.`taxSource`,'')='' THEN 'not_applicable'
    ELSE NEW.`taxSource`
  END;

-- Node mirror apply preserves the source transaction version exactly.
CREATE TRIGGER `trg_transactions_version_bu`
BEFORE UPDATE ON `transactions`
FOR EACH ROW
SET NEW.`version` = CASE
  WHEN COALESCE(@tamasya_node_mirror_apply,0)=1 THEN NEW.`version`
  WHEN COALESCE(NEW.`version`,0) <= COALESCE(OLD.`version`,0) THEN COALESCE(OLD.`version`,0)+1
  ELSE NEW.`version`
END;

CREATE TRIGGER `trg_transaction_allocations_ai`
AFTER INSERT ON `transaction_allocations`
FOR EACH ROW
UPDATE `transactions`
   SET `version`=`version`+1,`updatedAt`=CURRENT_TIMESTAMP
 WHERE COALESCE(@tamasya_node_mirror_apply,0)<>1 AND `id`=NEW.`transaction_id`;

CREATE TRIGGER `trg_transaction_allocations_au`
AFTER UPDATE ON `transaction_allocations`
FOR EACH ROW
UPDATE `transactions`
   SET `version`=`version`+1,`updatedAt`=CURRENT_TIMESTAMP
 WHERE COALESCE(@tamasya_node_mirror_apply,0)<>1
   AND (`id`=OLD.`transaction_id` OR `id`=NEW.`transaction_id`);

CREATE TRIGGER `trg_transaction_allocations_ad`
AFTER DELETE ON `transaction_allocations`
FOR EACH ROW
UPDATE `transactions`
   SET `version`=`version`+1,`updatedAt`=CURRENT_TIMESTAMP
 WHERE COALESCE(@tamasya_node_mirror_apply,0)<>1 AND `id`=OLD.`transaction_id`;


-- TABLE: user_sessions
CREATE TABLE `user_sessions` (
  `id` varchar(80) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `device_id` varchar(190) NOT NULL,
  `device_name` varchar(190) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `last_activity` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `refresh_token_hash` varchar(64) DEFAULT NULL,
  `refresh_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_session_staff` (`staff_id`),
  KEY `idx_session_device` (`device_id`),
  KEY `idx_session_exp` (`expires_at`,`revoked_at`),
  KEY `idx_session_refresh` (`refresh_token_hash`,`refresh_expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------------------------
-- MINIMAL CANONICAL MASTER DATA ONLY
-- --------------------------------------------------------------------------
INSERT INTO `config` (`id`,`is_seeded`) VALUES ('system_default',0);

INSERT INTO `hotel_operational_settings` (`id`) VALUES ('system_default');

-- Single-node safe default. Configure cluster/sync explicitly after fresh UAT.
INSERT INTO `node_sync_settings` (`id`,`node_id`,`node_role`,`sync_enabled`)
VALUES ('system_default','property-primary','online_primary',0);

-- No hotel/business category labels are seeded. Each property creates its own
-- category/subcategory catalog and assigns protected semantic roles through categories.system_key.
-- Operational room types remain in the existing rooms.type master field and are independent from Finance.

-- TABLE: facility_types (R15 dynamic facility engine: capacity/pricing models are data, not code)
CREATE TABLE `facility_types` (
  `id` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `capacity_model` varchar(20) NOT NULL DEFAULT 'open',
  `pricing_model` varchar(20) NOT NULL DEFAULT 'visit',
  `requires_staff` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_type_code` (`code`),
  KEY `idx_facility_type_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- TABLE: facilities (units of a type: a pool, a karaoke room, a gym hall)
CREATE TABLE `facilities` (
  `id` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `facility_type_id` varchar(100) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 0,
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `base_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_code` (`code`),
  KEY `idx_facility_type` (`facility_type_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- TABLE: facility_bookings (capacity consumption; money posts as folio extras, never here)
CREATE TABLE `facility_bookings` (
  `id` varchar(100) NOT NULL,
  `facility_id` varchar(100) NOT NULL,
  `booking_id` varchar(100) DEFAULT NULL,
  `guest_name` varchar(150) NOT NULL,
  `use_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `pax` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'reserved',
  `payment_status` varchar(20) NOT NULL DEFAULT 'unpaid',
  `folio_extra_id` varchar(100) DEFAULT NULL,
  `operation_id` varchar(100) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_booking_operation` (`operation_id`),
  KEY `idx_facility_booking_slot` (`facility_id`,`use_date`,`status`),
  KEY `idx_facility_booking_guest` (`booking_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fresh baseline release marker. No production/legacy data migration is required.
INSERT INTO `schema_release_state`
(`id`,`current_release`,`patch_level`,`source_checksum`,`migration_run_id`,`maintenance_required`)
VALUES ('system_default','V137_FRESH_CANONICAL_MULTI_HOTEL','V137_STABLE_HARDENED_R7_CROSS_MENU_STATE_AUTHORITY_R1_20260815',NULL,'fresh_v137_property_setup_fix18',0);


INSERT INTO `schema_migrations` (`version`,`description`) VALUES ('2026.08.15.v137-r6-reservation-checkin-inventory-separation.1','R6 canonical fresh schema: preserves R5 operational domains and Guest Service context while separating reservation inventory from explicit physical check-in using canonical hotel check-in/check-out clocks.');
INSERT INTO `schema_migrations` (`version`,`description`) VALUES ('2026.08.15.v137-r7-cross-menu-state-authority.1','R7 canonical schema: rooms.status is derived operational projection only; adds configurable payment-before-key policy so check-in, access control, and finance remain coherent.');
INSERT INTO `schema_migrations` (`version`,`description`) VALUES ('2026.08.17.v137-r7-full-dynamic-finance-catalog-root-fix17.1','FIX17 staging successor: explicit finance semantic bindings, property-owned dynamic category/subcategory labels, room-type separation, transaction/allocation semantic identity, and no hotel-specific finance catalog seeds.');
INSERT INTO `schema_migrations` (`version`,`description`) VALUES ('2026.08.17.v137-r7-full-dynamic-catalog-database-hygiene-root-fix18.1','FIX18 staging successor: FIX17 dynamic finance catalog plus compact durable request receipts, bounded replay-body retention, receipt cleanup index, and removal of unused generic inbound Webhook Hub tables.');
INSERT INTO `schema_migrations` (`version`,`description`) VALUES ('2026.08.17.v137-r7-113-table-semantic-consolidation-root-fix19.1','FIX19 staging successor: consolidates semantic binding into categories.system_key and room type into rooms.type; removes two obsolete Webhook Hub tables with no replacement tables.');

-- IMPORTANT OPTIONAL MODULES:
-- The following migrations are intentionally NOT marked/applied in this baseline because
-- their optional tables are not part of the core database and they remain disabled by default:
--   2026.08.10.v137-optional-growth-suite.1
--   2026.08.10.v137-optional-enterprise-completion.1
-- Apply them later only through the documented optional module process if needed.

-- Restore the session FK setting after the fresh schema is complete.
SET FOREIGN_KEY_CHECKS = @TAMASYA_PREV_FOREIGN_KEY_CHECKS;
