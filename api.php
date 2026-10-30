<?php
/**
 * ====================================================================
 * BACKEND API FOR TAMASYA HOTEL SYSTEM - PHP & MYSQL
 * ====================================================================
 * File ini berfungsi sebagai Backend API untuk dihosting di Shared Hosting
 * (cPanel, VPS, dsb.) dengan koneksi ke database MySQL.
 * ====================================================================
 */

// Runtime minimum ditetapkan berdasarkan syntax dan fungsi yang benar-benar dipakai.
// PHP 8.2 adalah batas minimum hardening; PHP 8.3/8.4/8.5 direkomendasikan.
if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'PHP 8.2 atau lebih baru diperlukan. Versi aktif: ' . PHP_VERSION,
        'minimumPhpVersion' => '8.2.0'
    ]);
    exit;
}

if (!defined('TAMASYA_API_ENTRY')) { define('TAMASYA_API_ENTRY', true); }
if (!defined('TAMASYA_APP_ROOT')) { define('TAMASYA_APP_ROOT', __DIR__); }
require_once __DIR__ . DIRECTORY_SEPARATOR . 'release_contract.php';

// Load .env/process environment before reading property runtime settings.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . 'autoload_domains.php';

// APP_TIMEZONE adalah identitas operasional property dan tidak boleh mengikuti asumsi hotel asal.
// Fresh V137 fail-closed: setiap hotel wajib menentukan timezone IANA sendiri.
$appTimezone = trim((string)(getenv('APP_TIMEZONE') ?: ''));
if ($appTimezone === '' || !in_array($appTimezone, DateTimeZone::listIdentifiers(), true)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error' => 'APP_TIMEZONE_REQUIRED',
        'message' => 'APP_TIMEZONE wajib diisi dengan timezone IANA property yang valid, misalnya Asia/Jakarta, Asia/Makassar, atau Asia/Jayapura.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
date_default_timezone_set($appTimezone);
$GLOBALS['tamasya_app_timezone'] = $appTimezone;

require_once TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'runtime_crypto.php';
try { tamasyaAssertSecretEncryptionConfigured(); } catch (Throwable $cryptoConfigError) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success'=>false,'error'=>'APP_ENCRYPTION_KEY_INVALID','message'=>$cryptoConfigError->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '001_runtime_security.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '002_enterprise_hardening.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '003_property_identity.php';
$__f = tamasyaResolveDomainSupportFile('004_property_setup.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '005_runtime_observability.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '009_schema_doctor.php';
$__f = tamasyaResolveDomainSupportFile('010_schema_contract.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '012_domain_primitives.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '015_biometric_attendance.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '016_approval_policy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '017_authorization_policy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '021_role_presets.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '023_smtp_diagnostics.php';
$__f = tamasyaResolveDomainSupportFile('018_canonical_business_policy.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('0185_finance_catalog_identity.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . '/api/modules/finance/021_universal_catalog.php';
require_once __DIR__ . '/api/modules/finance/022_catalog_transaction_snapshots.php';
$__f = tamasyaResolveDomainSupportFile('019_canonical_financial_semantics.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('020_identity_access_audit.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('025_financial_posting_authority.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('026_financial_mutation_authority.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('030_booking_finance.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('031_wholesale_contract_rates.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('035_guest_security_deposits.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('039_operational_domain_records.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('040_rooms_checkout_housekeeping.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('042_operational_lifecycle_invariants.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('045_communication_core.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('050_integrations_telegram_mail.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '055_public_support_chat.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '057_website_cms_schema.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '060_shift_receipts.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '070_payment_connector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '071_channel_connector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '070_data_projection_scope.php';
$__f = tamasyaResolveDomainSupportFile('075_flexible_historical_backfill.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('085_pos_minibar.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '090_security_cluster_smartlock.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . '091_room_access_issue.php';
$__f = tamasyaResolveDomainSupportFile('100_cleanup_tools.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('110_daily_summary.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('105_growth_suite.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('106_multi_property_foundation.php'); if ($__f) { require_once $__f; } unset($__f);
$__f = tamasyaResolveDomainSupportFile('107_enterprise_completion.php'); if ($__f) { require_once $__f; } unset($__f);
require_once __DIR__ . '/api/support/120_promotion_codes.php';
require_once __DIR__.'/api/modules/front_office/043_multi_room_reservations.php';
require_once __DIR__.'/api/modules/front_office/044_multi_room_telegram.php';

require_once __DIR__ . '/api/modules/setup_admin/108_hybrid_architecture.php';
require_once __DIR__ . '/api/modules/setup_admin/109_hybrid_outbox.php';

tamasyaRuntimeInit();
tamasyaRuntimeSetStage('bootstrap:credentials');

/* tamasyaRuntimeRequirements moved to api/support/001_runtime_security.php */


// 1. SETTING KONEKSI DATABASE
// Resolver tunggal dipakai oleh API, setup wizard, dan pemeriksaan staging.
// Ia mendukung environment DB_*, file kredensial lama, instalasi subfolder,
// format array/variabel, serta port MySQL non-default tanpa mengekspos secret.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_sync_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_cluster_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'consistency_guard_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'canonical_report_support.php';
$db_config = tamasyaResolveDatabaseConfig(__DIR__);
$db_host = (string)$db_config['host'];
$db_port = (int)$db_config['port'];
$db_name = (string)$db_config['name'];
$db_user = (string)$db_config['user'];
$db_pass = (string)$db_config['pass'];
$GLOBALS['tamasya_runtime_database_name'] = $db_name;
$propertyId = strtolower(trim((string)(getenv('TAMASYA_PROPERTY_ID') ?: getenv('APP_PROPERTY_ID') ?: '')));
$propertyCode = strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_CODE') ?: '')));
$propertyName = trim((string)(getenv('TAMASYA_PROPERTY_NAME') ?: ''));
if ($propertyId === '' || $propertyId === 'default' || !preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/', $propertyId) || $propertyCode === '' || !preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $propertyCode) || $propertyName === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error' => 'PROPERTY_IDENTITY_REQUIRED',
        'message' => 'TAMASYA_PROPERTY_NAME, TAMASYA_PROPERTY_ID, dan TAMASYA_PROPERTY_CODE wajib diisi valid untuk setiap property.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$GLOBALS['tamasya_property_id'] = $propertyId;
$runtime_credentials_file = !empty($db_config['source'])
    ? (string)$db_config['source']
    : tamasyaDefaultCredentialTarget(__DIR__);

// 2. ERROR REPORTING: detail hanya aktif saat APP_DEBUG=1.
// Pada production, detail file/line tidak boleh dikirim ke browser.
$appDebug = filter_var(getenv("APP_DEBUG") ?: "0", FILTER_VALIDATE_BOOLEAN);
error_reporting(E_ALL);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');

// Global Exception & Error Handler (fatal error tetap menjadi JSON, tetapi aman untuk production).
set_exception_handler(function ($e) use ($appDebug) {
    $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    $failedStage = (string)($GLOBALS['tamasya_runtime_stage'] ?? 'unknown');
    tamasyaRuntimeCaptureThrowable($e, $failedStage);
    tamasyaRuntimeSetStage('exception:unhandled');
    $requestId = tamasyaRuntimeRequestId();
    error_log(sprintf(
        '[api.php][%s][%s][%s] %s in %s:%d',
        $reference,
        $requestId,
        $failedStage,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    if (($GLOBALS['tamasya_runtime_pdo'] ?? null) instanceof PDO) {
        $runtimePdo=$GLOBALS['tamasya_runtime_pdo'];
        if($runtimePdo->inTransaction()){
            try{$runtimePdo->rollBack();}catch(Throwable $rollbackError){error_log('[api.php]['.$reference.'] rollback failed: '.$rollbackError->getMessage());}
        }
        tamasyaRuntimePersist($runtimePdo, tamasyaExceptionHttpStatus($e, 500), $e, null, $reference);
    }

    // Jangan pernah menambahkan JSON kedua setelah body sebelumnya sudah
    // terkirim. Kasus inilah yang memicu "Unexpected non-whitespace character
    // after JSON" pada browser.
    if (headers_sent()) {
        error_log('[api.php]['.$reference.'] Exception terjadi setelah respons dikirim; body tambahan diblokir.');
        return;
    }

    tamasyaDiscardOutputBuffers();
    http_response_code(tamasyaExceptionHttpStatus($e, 500));
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Tamasya-Error-Reference: '.$reference);
    $payload = [
        "success" => false,
        "message" => $appDebug
            ? clientExceptionMessage("Fatal Error", $e)
            : "Terjadi kesalahan internal pada server. Referensi: " . $reference,
        "requestId" => $requestId,
        "stage" => $failedStage,
        "reference" => $reference
    ];

    if ($appDebug) {
        $payload["file"] = basename($e->getFile());
        $payload["line"] = $e->getLine();
    }

    echo tamasyaJsonEncode($payload);
    exit();
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Pesan exception yang aman untuk client. Error database/infrastruktur tidak
 * pernah membuka query, path file, host, atau kredensial saat APP_DEBUG=0.
 * Kesalahan validasi bisnis tetap dapat ditampilkan agar user tahu tindakan
 * yang harus diperbaiki.
 */

/* clientExceptionMessage moved to api/support/001_runtime_security.php */


/**
 * Enkripsi rahasia konfigurasi secara backward-compatible.
 * Nilai plaintext lama tetap dapat dibaca, lalu otomatis tersimpan terenkripsi
 * ketika Admin menyimpan konfigurasi kembali. Pada production, APP_ENCRYPTION_KEY
 * wajib diisi minimal 32 karakter (atau base64 32 byte).
 */

/* appSecretEncryptionKey moved to api/support/001_runtime_security.php */



/* decryptStoredSecret moved to api/support/001_runtime_security.php */



/* encryptStoredSecret moved to api/support/001_runtime_security.php */


// 3. Enterprise transport boundary: Host allow-list, strict Origin validation,
// trusted security headers, and explicit CORS. Environment variables are loaded
// by database_bootstrap.php before this gate is evaluated.
tamasyaEnterpriseApplyApiHeaders();
tamasyaEnterpriseValidateHost();
$validatedOrigin = tamasyaEnterpriseValidateOrigin();
if ($validatedOrigin !== null) {
    header('Access-Control-Allow-Origin: ' . $validatedOrigin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Device-ID, X-Device-Name, X-App-Version, X-Tamasya-Operation-ID, X-Tamasya-Request-ID, X-Tamasya-Hotel-Scope, X-Tamasya-Company-ID, X-Tamasya-Property-ID, If-None-Match, X-Tamasya-Offline-Session-Scope, X-HTTP-Method-Override, X-Telegram-Bot-Api-Secret-Token, X-Tamasya-Channel-ID, X-Tamasya-Bridge-Signature, X-Tamasya-Bridge-Contract');
header('Access-Control-Expose-Headers: ETag, X-Tamasya-Request-ID, X-Tamasya-Error-Reference, X-Tamasya-Idempotent-Replay, X-Tamasya-Execution-Node');
header('X-Tamasya-Request-ID: ' . tamasyaRuntimeRequestId());
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

tamasyaRejectUnsafeHttpMethods();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// 4. KONEKSI KE DATABASE MENGGUNAKAN PDO
tamasyaRuntimeSetStage('bootstrap:database_connect');
[$pdo, $raw_pdo_error, $database_connection_stage] = tamasyaConnectDatabase($db_config);
$GLOBALS['tamasya_runtime_pdo'] = $pdo;
$pdo_error = null;
$database_safety = null;
if ($pdo) {
    try {
        // Satu sumber kebenaran timezone: APP_TIMEZONE property. Offset MySQL dihitung
        // per request dari timezone IANA sehingga instalasi tidak membawa asumsi zona hotel lama.
        $timezoneNow = new DateTimeImmutable('now', new DateTimeZone($appTimezone));
        $offsetSeconds = $timezoneNow->getOffset();
        $offsetSign = $offsetSeconds < 0 ? '-' : '+';
        $offsetAbs = abs($offsetSeconds);
        $databaseTimezoneOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($offsetAbs, 3600), intdiv($offsetAbs % 3600, 60));
        if (!preg_match('/^[+-](?:0\d|1[0-3]):[0-5]\d$|^\+14:00$/', $databaseTimezoneOffset)) {
            throw new RuntimeException('Offset timezone property berada di luar rentang yang didukung MySQL: ' . $databaseTimezoneOffset);
        }
        $pdo->exec('SET time_zone = ' . $pdo->quote($databaseTimezoneOffset));
        $GLOBALS['tamasya_database_timezone_offset'] = $databaseTimezoneOffset;

        tamasyaRuntimeSetStage('bootstrap:database_safety');
        $database_safety = tamasyaAssertDatabaseSafety($pdo, $db_config);
    } catch (Throwable $databaseSafetyError) {
        error_log('[api.php] Database safety gate blocked startup: ' . $databaseSafetyError->getMessage());
        http_response_code(503);
        echo json_encode([
            'success'=>false,
            'error'=>'Database safety gate menolak koneksi. Periksa APP_CREDENTIALS_FILE, APP_EXPECTED_DB_NAME, dan APP_FORBIDDEN_DB_NAMES.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!$pdo) {
    if ($database_connection_stage === 'credential_file_required') {
        $pdo_error = 'APP_CREDENTIALS_FILE tidak valid. Perbaiki path/file credential; fallback otomatis dinonaktifkan.';
    } elseif ($database_connection_stage === 'missing_credentials') {
        $pdo_error = 'Konfigurasi database belum ditemukan.';
    } elseif ($database_connection_stage === 'missing_driver') {
        $pdo_error = 'Driver PHP pdo_mysql belum aktif pada server.';
    } else {
        $pdo_error = $appDebug && $raw_pdo_error
            ? $raw_pdo_error
            : 'Koneksi database gagal. Periksa host, port, nama database, username, password, dan izin user MySQL.';
    }
    if ($raw_pdo_error) {
        error_log('[api.php] Database connection failed: ' . $raw_pdo_error);
    }
}

// Error-only telemetry is registered before authentication/routing so failures
// in session, permission, or bootstrap remain traceable by request_id.
if ($pdo instanceof PDO) {
    register_shutdown_function(function() use ($pdo) {
        if (!empty($GLOBALS['tamasya_runtime_persisted']) || !empty($GLOBALS['tamasya_runtime_has_mutation_finalizer'])) return;
        $status = http_response_code();
        if ($status === false || $status === 0) $status = 200;
        $fatal = error_get_last();
        $fatalTypes = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
        $hasFatal = is_array($fatal) && in_array((int)($fatal['type'] ?? 0), $fatalTypes, true);
        if ($hasFatal) {
            $failedStage = (string)($GLOBALS['tamasya_runtime_stage'] ?? 'unknown');
            tamasyaRuntimeCaptureFatal($fatal, $failedStage);
            tamasyaRuntimeSetStage('shutdown:fatal');
            $status = 500;
        }
        if ($status >= 400) {
            tamasyaRuntimePersist($pdo, (int)$status, null, $hasFatal ? substr((string)($fatal['message'] ?? 'fatal error'),0,500) : null);
        }
    });
}

tamasyaRuntimeSetStage('bootstrap:schema_contract');

if ($pdo instanceof PDO) {
    tamasyaRuntimeSetStage('bootstrap:schema_version_gate');
    tamasyaAssertProductionSchemaReady($pdo);
    $propertyDeploymentIdentity=tamasyaAssertPropertyDeploymentIdentity($pdo);
    if(empty($propertyDeploymentIdentity['ok'])){
        http_response_code(503);
        echo json_encode([
            'success'=>false,
            'error'=>'PROPERTY_DEPLOYMENT_IDENTITY_MISMATCH',
            'message'=>'Identitas deployment ENV tidak sama dengan identity yang tersimpan pada database property. Database tidak boleh dipakai oleh hotel/cabang lain.',
            'mismatches'=>$propertyDeploymentIdentity['mismatches']??[]
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Cluster/sync static configuration is fail-closed before any leadership
    // initialization. Offline operation is still supported: this validates the
    // local configuration only and does NOT require the peer to be reachable.
    if(tamasyaNodeSyncEnabled() || tamasyaClusterEnabled()){
        $clusterEnvironment=tamasyaNodeSyncEnvironmentCheck();
        if(empty($clusterEnvironment['ok'])){
            http_response_code(503);
            echo json_encode([
                'success'=>false,
                'error'=>'NODE_CLUSTER_CONFIGURATION_INVALID',
                'message'=>'Konfigurasi two-server/cluster tidak aman. Perbaiki ENV sebelum operasi.',
                'problems'=>$clusterEnvironment['errors']??[]
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    // Runtime initialization may update leases/member heartbeat, but its table
    // ensure helpers are read-only when schema mutation is disabled.
    if (tamasyaNodeSyncEnabled()) tamasyaEnsureNodeSyncTables($pdo);
    try {
        tamasyaInitializeClusterState($pdo);
    } catch (Throwable $clusterInitError) {
        error_log(clientExceptionMessage('[cluster] initialization failed',$clusterInitError));
        if(tamasyaNodeSyncEnabled() || tamasyaClusterEnabled()){
            http_response_code(503);
            echo json_encode([
                'success'=>false,
                'error'=>'NODE_CLUSTER_INITIALIZATION_FAILED',
                'message'=>'Cluster aktif tetapi leadership state gagal diinisialisasi. Operasi diblokir agar tidak terjadi writer tidak tervalidasi.'
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    tamasyaRuntimeSetStage('bootstrap:runtime_ready');
}

// Request envelope is bounded before php://input is read. This prevents memory
// exhaustion through oversized JSON while keeping KTP/biometric limits explicit.
tamasyaRuntimeSetStage('request:parse');
tamasyaEnterpriseAssertContentLength();
$rawRequestBody = file_get_contents('php://input', false, null, 0, tamasyaEnterpriseGlobalRequestLimit() + 1);
if ($rawRequestBody === false) $rawRequestBody = '';
$decodedInput = $rawRequestBody === '' ? [] : json_decode($rawRequestBody, true);
$input = is_array($decodedInput) ? $decodedInput : [];
tamasyaEnterpriseResolveMethodOverride($input);

// Correlation ID lintas perangkat/node. Invalid values are ignored and replaced
// by the server request ID; they are never persisted as attacker-controlled keys.
$requestOperationId = trim((string)($_SERVER['HTTP_X_TAMASYA_OPERATION_ID'] ?? ($input['operationId'] ?? $input['operation_id'] ?? '')));
if ($requestOperationId !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestOperationId)) {
    $GLOBALS['tamasya_request_operation_id'] = $requestOperationId;
}

// Ambil action dari parameter URL, contoh: api.php?action=hotel-data
$action = $_GET['action'] ?? '';

// Jika path info digunakan (contoh: api.php/hotel-data)
if (empty($action) && isset($_SERVER['PATH_INFO'])) {
    $action = trim($_SERVER['PATH_INFO'], '/');
}

// Normalize fixed nested routes before generic REST parsing. This keeps direct
// requests through .htaccess consistent with the frontend fetch interceptor.
$fixedNestedActions = [
    'categories/delete' => 'categories-delete',
    'subcategories/delete' => 'subcategories-delete',
    'notifications/read' => 'notifications-read',
    'db/reset' => 'db-reset',
];
if (isset($fixedNestedActions[$action])) {
    $action = $fixedNestedActions[$action];
}

// RESTful Route Parsing (Contoh: bookings/b_123, bookings/b_123/status, transactions/tx_123)
if (!empty($action)) {
    $parts = explode('/', $action);
    if (count($parts) > 1) {
        if ($parts[0] === 'bookings' && isset($parts[2]) && $parts[2] === 'status') {
            $action = 'bookings-status';
            if (empty($_GET['id']) && empty($input['id'])) {
                $_GET['id'] = $parts[1];
            }
        } elseif ($parts[0] === 'bookings' && isset($parts[2]) && $parts[2] === 'security-deposit') {
            $action = 'guest-security-deposits';
            if (empty($_GET['id']) && empty($input['id'])) { $_GET['id'] = $parts[1]; }
        } elseif ($parts[0] === 'bookings' && isset($parts[2]) && $parts[2] === 'payments') {
            // Frontend canonical route: /api/bookings/{id}/payments.
            // Keep REST routing aligned with the booking-payments authority instead
            // of falling through to generic booking edit/create handling.
            $action = 'booking-payments';
            if (empty($_GET['id']) && empty($input['id'])) { $_GET['id'] = $parts[1]; }
        } else {
            $action = $parts[0];
            if (empty($_GET['id']) && empty($input['id'])) {
                $_GET['id'] = $parts[1];
            }
        }
    }
}

tamasyaRuntimeSetAction((string)$action);
tamasyaRuntimeSetStage('request:action_resolved');
$input = tamasyaEnterpriseValidateParsedRequest((string)$action, (string)$rawRequestBody, $decodedInput);
$GLOBALS['tamasya_client_ip'] = tamasyaClientIp();

/* currentDeviceId moved to api/support/020_identity_access_audit.php */



/* currentAppVersion moved to api/support/020_identity_access_audit.php */



/* tamasyaOperationCanonicalize moved to api/support/020_identity_access_audit.php */



/* tamasyaRequestOperationPayloadHash moved to api/support/020_identity_access_audit.php */


/**
 * Claim one authenticated browser mutation. The receipt is replicated with the
 * operational dataset, so the same operation cannot be executed again after a
 * primary switchover/failover.
 */

/* tamasyaClaimRequestOperation moved to api/support/020_identity_access_audit.php */



/* tamasyaCompleteRequestOperation moved to api/support/020_identity_access_audit.php */



/* sanitizeAuditValue moved to api/support/020_identity_access_audit.php */



/* writeEnterpriseAudit moved to api/support/020_identity_access_audit.php */



/* resolveConfiguredTaxRate moved to api/support/020_identity_access_audit.php */



/* refreshOperationalAlerts moved to api/support/020_identity_access_audit.php */



/* nextDocumentNumber moved to api/support/020_identity_access_audit.php */






/* recalculateBookingFinancials moved to api/support/020_identity_access_audit.php */



/* transactionJournalAccounts moved to api/support/020_identity_access_audit.php */


/** Membangun jurnal debit-kredit dari transaksi lama tanpa mengubah transaksi sumber. */

/* syncJournalProjections moved to api/support/020_identity_access_audit.php */



/* getOperationsCenterData moved to api/support/020_identity_access_audit.php */



/* getRoleScopedOperationsData moved to api/support/020_identity_access_audit.php */


// Fungsi untuk autentikasi token Bearer

/* requireAuth moved to api/support/020_identity_access_audit.php */




/* requireRoles moved to api/support/020_identity_access_audit.php */



/* hasCapability moved to api/support/020_identity_access_audit.php */



/* requireCapability moved to api/support/020_identity_access_audit.php */


/**
 * Membaca bukti identitas tamu tanpa pernah mengekspos lokasi file server atau
 * token bot. Payload umum hotel-data hanya membawa hasKtpPhoto; gambar dibaca
 * secara online, berdasarkan capability, dan setiap akses dicatat audit.
 */

/* bookingIdentityDataUrl moved to api/support/020_identity_access_audit.php */


/**
 * Menormalkan referensi KTP sebelum disimpan. Hanya tiga bentuk yang boleh
 * masuk database: data URL gambar tervalidasi, Telegram file_id, atau file
 * lokal khusus KTP di folder uploads. URL luar, path arbitrer, dan teks bebas
 * ditolak agar field identitas tidak menjadi jalur penyisipan/eksfiltrasi.
 */

/* normalizeBookingIdentityReferenceForStorage moved to api/support/020_identity_access_audit.php */



/* hasDesktopTabAccess moved to api/support/020_identity_access_audit.php */



/* requireDesktopTabAccess moved to api/support/020_identity_access_audit.php */


/** Rate limiter atomik berbasis database untuk login, OTP, dan Telegram. */

/* consumeRateLimit moved to api/support/020_identity_access_audit.php */


/* enforceRateLimit moved to api/support/020_identity_access_audit.php */



/* requireSensitiveApproval moved to api/support/020_identity_access_audit.php */


/* markApprovalUsed moved to api/support/020_identity_access_audit.php */



/* createMaintenanceExpense moved to api/support/020_identity_access_audit.php */



/* createInventoryMaintenanceExpense moved to api/support/020_identity_access_audit.php */



/* currentStaffLabel moved to api/support/020_identity_access_audit.php */




/* canManageStaffSavings moved to api/support/020_identity_access_audit.php */



/* normalizeStaffSavingsAmount moved to api/support/020_identity_access_audit.php */



/* normalizeStaffSavingsMethod moved to api/support/020_identity_access_audit.php */



/* normalizeStaffSavingsSource moved to api/support/020_identity_access_audit.php */



/* ensureStaffSavingsAccount moved to api/support/020_identity_access_audit.php */



/* lockStaffSavingsAccount moved to api/support/020_identity_access_audit.php */



/* staffSavingsReservedAmount moved to api/support/020_identity_access_audit.php */



/* staffSavingsTargetStaff moved to api/support/020_identity_access_audit.php */



/* staffSavingsReceiptPrefix moved to api/support/020_identity_access_audit.php */



/* assertStaffSavingsClosedBeforeDeactivation moved to api/support/020_identity_access_audit.php */



/* validIsoDate moved to api/support/020_identity_access_audit.php */



/* tamasyaStringLength moved to api/support/020_identity_access_audit.php */



/* generateServerId moved to api/support/020_identity_access_audit.php */



/* getBootstrapAdminPassword moved to api/support/020_identity_access_audit.php */



/* createTelegramBindingCode moved to api/support/020_identity_access_audit.php */



/* consumeTelegramBindingCode moved to api/support/020_identity_access_audit.php */



/* bindTelegramIdentity moved to api/support/020_identity_access_audit.php */


/** Resolve Telegram identity from the verified binding table first. */

/* resolveTelegramUserIdForStaff moved to api/support/020_identity_access_audit.php */



/* unbindTelegramIdentity moved to api/support/020_identity_access_audit.php */



/* findActiveStaffByTelegramUserId moved to api/support/020_identity_access_audit.php */



/* bumpServerRevision moved to api/support/030_booking_finance.php */



/* issueSessionTokens moved to api/support/030_booking_finance.php */



/* isPlaceholderProof moved to api/support/030_booking_finance.php */



/* inferTransactionKind moved to api/support/030_booking_finance.php */



/* transactionIsProtected moved to api/support/030_booking_finance.php */



/* normalizeTransactionKind moved to api/support/030_booking_finance.php */



/* moneyMatches moved to api/support/030_booking_finance.php */



/* bookingLedgerTotals moved to api/support/030_booking_finance.php */



/** V137: catat panjar berkali-kali sebagai ledger, bukan overwrite booking. */

/* recordBookingDeposit moved to api/support/030_booking_finance.php */


/**
 * Membuat transaksi pembalik untuk setiap penerimaan booking tanpa menghapus histori asli.
 * operationId dan id transaksi deterministik, sehingga retry online/offline tetap idempoten.
 */

/* createBookingRefundTransactions moved to api/support/030_booking_finance.php */



/* assertBookingLedgerInvariant moved to api/support/030_booking_finance.php */




/* isOtaBookingSource moved to api/support/030_booking_finance.php */



/* inferBookingChargeAction moved to api/support/030_booking_finance.php */



/* calculateInclusiveTaxBreakdown moved to api/support/030_booking_finance.php */



/* resolveBookingChargeTaxPolicy moved to api/support/030_booking_finance.php */


/**
 * Kebijakan pajak transaksi manual yang tidak terhubung booking. Tarif dari
 * browser diabaikan; sumber, jenis transaksi, dan tanggal dipetakan ke
 * tax_rules server. Pengeluaran tidak dikenai PBJT penjualan.
 */

/* resolveStandaloneTransactionTaxPolicy moved to api/support/030_booking_finance.php */


/**
 * Kebijakan PBJT penjualan kamar yang otoritatif di server. Nilai total dianggap
 * bruto (sudah termasuk pajak), sehingga koreksi metadata tidak mengubah uang kas.
 * Aturan tax_rules dapat menonaktifkan atau mengganti tarif tanpa mengubah source.
 */
// V137: pajak transaksi live hanya berasal dari tax_rules aktif; tidak ada tarif bawaan.

/* resolveRoomSaleTaxBreakdown moved to api/support/030_booking_finance.php */



/* signedTransactionTaxAmount moved to api/support/030_booking_finance.php */


/**
 * Rekonsiliasi metadata PBJT reservasi dengan keyset pagination agar database
 * dengan riwayat lebih dari 2.000 booking tetap selesai tanpa query besar.
 */






/**
 * Melengkapi metadata PBJT transaksi kamar yang hilang/tidak konsisten tanpa
 * mengubah nominal kas/bruto atau menulis ulang pajak historis yang sudah valid.
 * Dipakai oleh migrasi satu kali dan housekeeping ringan webhook Telegram.
 */




/**
 * Ubah satu transaksi kas manual menjadi biaya reservasi secara atomik.
 * Dipakai oleh endpoint online dan sync offline agar aturan bisnis tidak bercabang.
 * Fungsi ini tidak melakukan commit/rollback; caller wajib membungkusnya dalam transaksi DB.
 */

/* lockAvailableRoomForNewBooking moved to api/support/040_rooms_checkout_housekeeping.php */



/* lockActiveBookingForRoom moved to api/support/040_rooms_checkout_housekeeping.php */



/* updateRoomStatusSafely moved to api/support/040_rooms_checkout_housekeeping.php */


/**
 * Satu jalur perubahan housekeeping untuk web dan Telegram. Status available
 * hanya boleh terjadi setelah task ready dan seluruh blocker kamar selesai.
 */

/* applyHousekeepingAction moved to api/support/040_rooms_checkout_housekeeping.php */


class TelegramDuplicateOperationException extends RuntimeException {}


/* claimTelegramMutation moved to api/support/040_rooms_checkout_housekeeping.php */



/* completeTelegramMutation moved to api/support/040_rooms_checkout_housekeeping.php */



/* failTelegramMutation moved to api/support/040_rooms_checkout_housekeeping.php */




/* createTelegramCashTransaction moved to api/support/040_rooms_checkout_housekeeping.php */



/**
 * Memuat pasangan kategori/subkategori langsung dari relasi database.
 * Callback Telegram dapat dimanipulasi manual, sehingga ID kategori dan
 * subkategori tidak boleh dipercaya hanya karena berasal dari inline button.
 */

/* requireTelegramFinancialCategoryPair moved to api/support/040_rooms_checkout_housekeeping.php */



/* requireTelegramRoom moved to api/support/040_rooms_checkout_housekeeping.php */



/** V137: semua kanal checkout memakai lifecycle kamar yang sama. */

/* checkoutAccessSnapshot moved to api/support/040_rooms_checkout_housekeeping.php */



/* normalizeCheckoutKeyDisposition moved to api/support/040_rooms_checkout_housekeeping.php */



/* ensureCheckoutHousekeepingTask moved to api/support/040_rooms_checkout_housekeeping.php */



/* closeVacancyReportsForCheckout moved to api/support/040_rooms_checkout_housekeeping.php */



/* checkoutVacancyFindings moved to api/support/040_rooms_checkout_housekeeping.php */



/* createCheckoutFindingSafeguards moved to api/support/040_rooms_checkout_housekeeping.php */


/**
 * Finalisasi status operasional setelah pembayaran/ledger valid.
 * Fungsi ini wajib dipanggil di dalam transaksi yang juga mengunci booking.
 */

/* finalizeCheckoutOperationalLifecycle moved to api/support/040_rooms_checkout_housekeeping.php */



/* createOrRefreshRoomVacancyReport moved to api/support/040_rooms_checkout_housekeeping.php */



/* prepareTelegramCheckoutFinancialStep moved to api/support/040_rooms_checkout_housekeeping.php */


/**
 * Menyediakan pilihan pelunasan checkout tanpa menaruh ID akun bank panjang
 * di callback_data. Pemetaan indeks disimpan pada state staf server-side.
 */

/* prepareTelegramCheckoutPaymentChoice moved to api/support/040_rooms_checkout_housekeeping.php */


/**
 * Checkout Telegram yang bersumber dari ledger server. Tidak pernah menandai
 * lunas bila jumlah penerimaan bersih belum sama dengan total tagihan.
 */

/* finalizeTelegramCheckout moved to api/support/040_rooms_checkout_housekeeping.php */



/* processManualBookingAction moved to api/support/040_rooms_checkout_housekeeping.php */


// Fungsi untuk mengirimkan request POST secara aman (Fallbacks dari cURL ke file_get_contents)

/* sendHttpPost moved to api/support/050_integrations_telegram_mail.php */




// -----------------------------------------------------------------------------
// Telegram webhook helpers
// -----------------------------------------------------------------------------

/* ensureTelegramWebhookColumns moved to api/support/050_integrations_telegram_mail.php */



/* getTelegramApiBaseUrl moved to api/support/050_integrations_telegram_mail.php */



/* telegramApiCall moved to api/support/050_integrations_telegram_mail.php */




/* telegramSendBookingIdentityPhoto moved to api/support/050_integrations_telegram_mail.php */



/* telegramApiErrorMessage moved to api/support/050_integrations_telegram_mail.php */



/* buildPublicWebhookUrl moved to api/support/050_integrations_telegram_mail.php */



/* validateTelegramWebhookUrl moved to api/support/050_integrations_telegram_mail.php */


// Helper Log Audit Aktivitas Staf (Activity Trail Logs)

/* logActivity moved to api/support/050_integrations_telegram_mail.php */


// Helper SMTP untuk mengirimkan email secara handal tanpa SMTP server lokal

/* sendSmtpMail moved to api/support/050_integrations_telegram_mail.php */



/* parseTelegramChatIds moved to api/support/050_integrations_telegram_mail.php */


// Fungsi untuk mengirim notifikasi broadcast ke seluruh Telegram Chat ID terdaftar dengan penyaringan peran untuk input manual/keuangan

/* broadcastTelegramNotification moved to api/support/050_integrations_telegram_mail.php */


// Helper untuk menyamakan format & kelengkapan notifikasi Check-in di Telegram (HP & Komputer)

/* getCheckInTelegramMessage moved to api/support/050_integrations_telegram_mail.php */


// Helper untuk menyamakan format & kelengkapan notifikasi Check-out di Telegram (HP & Komputer)

/* getCheckOutTelegramMessage moved to api/support/050_integrations_telegram_mail.php */


/** V137: satu sesi kas dapat dipakai satu atau dua resepsionis. */

/* getOpenShiftForStaff moved to api/support/060_shift_receipts.php */



/* getShiftParticipantIds moved to api/support/060_shift_receipts.php */



/* getShiftDisplayName moved to api/support/060_shift_receipts.php */



/* resolveOpenShiftSessionId moved to api/support/060_shift_receipts.php */


/** Kaitkan transaksi baru ke sesi kas yang dipakai petugas utama atau pendamping. */

/* attachTransactionToActorShift moved to api/support/060_shift_receipts.php */



/* validateOptionalShiftCompanion moved to api/support/060_shift_receipts.php */


// Fungsi untuk menghitung laporan arus kas per shift di Telegram

/* getShiftFinancials moved to api/support/060_shift_receipts.php */



/* upsertShiftReportForSession moved to api/support/060_shift_receipts.php */



/* finalizeTelegramShiftReport moved to api/support/060_shift_receipts.php */


// Fungsi dinamis untuk mendapatkan nama kategori sewa kamar dari database (mengikuti logika getRoomRentalCategoryName di frontend)

/* getRoomRentalCategoryName moved to api/support/060_shift_receipts.php */


// Fungsi bantu cetak nota format thermal untuk Telegram Bot

/* generateBotThermalReceipt moved to api/support/060_shift_receipts.php */


// Fungsi bantu untuk merender konfirmasi booking dengan sumber pemesanan dan harga nego

/* renderBookingConfirmation moved to api/support/060_shift_receipts.php */


// Fungsi bantu untuk mengambil seluruh data hotel terupdate (seperti db.json di Node.js)

/* getFullHotelData moved to api/support/070_data_projection_scope.php */



/**
 * Payload hotel-data wajib mengikuti role server. Menyembunyikan tab di React
 * bukan pengamanan karena endpoint dapat dipanggil langsung.
 */

/* getRoleScopedHotelData moved to api/support/070_data_projection_scope.php */



/** Validate the schema actually used by login, hotel-data, finance, payroll,
 * Telegram, offline sync, KTP, activity and operations. */

/* validateApplicationSchema moved to api/support/090_security_cluster_smartlock.php */



/* getOperationalSettings moved to api/support/090_security_cluster_smartlock.php */



/* enforceOpenShiftForRoomSale moved to api/support/090_security_cluster_smartlock.php */



/* isNightShiftRow moved to api/support/090_security_cluster_smartlock.php */



/* insertRoomKeyEvent moved to api/support/090_security_cluster_smartlock.php */



/* queueSmartLockBridgeJob moved to api/support/090_security_cluster_smartlock.php */



/* processSmartLockBridgeJob moved to api/support/090_security_cluster_smartlock.php */



/* dispatchSmartLockBridge moved to api/support/090_security_cluster_smartlock.php */


/**
 * Menjalankan job smart-lock checkout setelah transaksi database commit.
 * Payload checkout hanya berisi perintah revoke (tanpa secret sekali pakai),
 * sehingga aman dibaca kembali dari jurnal job.
 */

/* processDeferredCheckoutSmartLockJob moved to api/support/090_security_cluster_smartlock.php */


/**
 * Konfirmasi manual hanya dipakai bila petugas berwenang benar-benar sudah
 * mencabut akses pada perangkat/portal smart-lock di luar bridge otomatis.
 * Histori job tidak dihapus; status dan alasan tetap dapat diaudit.
 */

/* completeSmartLockRevokeJobManually moved to api/support/090_security_cluster_smartlock.php */



/* refreshRoomSecurityState moved to api/support/090_security_cluster_smartlock.php */



/* claimTelegramUpdate moved to api/support/090_security_cluster_smartlock.php */



/* registerTelegramUpdateCompletion moved to api/support/090_security_cluster_smartlock.php */


// Private CLI service bootstrap returns before browser authentication/dispatch.
if (defined('TAMASYA_SERVICE_BOOTSTRAP') && TAMASYA_SERVICE_BOOTSTRAP===true && PHP_SAPI==='cli') {
    if (!($pdo instanceof PDO)) throw new RuntimeException('Worker database unavailable.');
    return;
}

// 4. ROUTING ENDPOINT API
/**
 * F-09: Review 26 public actions yang bypass requireAuth().
 * Security posture per action:
 *
 * CLUSTER (node-verified via tamasyaVerifyNodeRequest):
 * - node-sync-snapshot: GET, node signature + primary lock
 * - node-cluster-peer-status: GET, node signature + clusterId match
 * - node-cluster-control: POST, node signature + primary lease
 * - node-cluster-telegram-webhook: POST, node signature + forwarded update
 * - node-cluster-telegram-delivery: POST, node signature + sealed OTP envelope
 * - node-cluster-channel-webhook: POST, node signature + forwarded channel payload
 *
 * WEBHOOKS (provider-authenticated):
 * - telegram-webhook: POST, secret token header (X-Telegram-Bot-Api-Secret-Token) + update_id idempotency
 * - communication-webhook: POST, channelId + adapter verifyInbound() HMAC, Telegram blocked
 * - growth-payment-webhook: POST, provider HMAC via tamasyaPaymentWebhookAuthenticate(), rate limit
 * - growth-channel-webhook: POST, provider HMAC, rate limit
 * - biometric-device-webhook: POST, device HMAC via tamasyaBiometricAuthenticateDeviceWebhook()
 *
 * PUBLIC WEBSITE (rate-limited, origin-validated):
 * - public-site-config: GET, public settings only
 * - public-bootstrap: GET, public bootstrap data
 * - public-room-types: GET, published room types
 * - public-room-availability: GET, availability for dates
 * - public-promotions: GET, public promotions
 * - public-reservation-request: POST, honeypot + operation_id + rate limit + payload validation
 * - public-contact: GET, public contact info
 * - public-help-chat: POST, origin check + rate limit + conversation token + row lock
 * - public-help-chat-sync: POST, origin check + rate limit + conversation token
 *
 * CORE (no auth, minimal):
 * - '' (empty): status ping via 010_cluster_status.php
 * - status: GET, server status
 * - ping: GET, health check
 * - login: POST, rate limit + account lock + bcrypt
 * - verify-2fa: POST, OTP verification
 * - refresh-session: POST, session token refresh
 *
 * All public actions reviewed 2026-10-20. No unauthenticated mutation endpoints found.
 */
$public_actions = ['', 'status', 'ping', 'login', 'verify-2fa', 'refresh-session', 'telegram-webhook', 'node-sync-snapshot', 'node-cluster-peer-status', 'node-cluster-control', 'node-cluster-telegram-webhook', 'node-cluster-telegram-delivery', 'communication-webhook', 'node-cluster-channel-webhook', 'growth-payment-webhook', 'growth-channel-webhook', 'public-site-config', 'public-bootstrap', 'public-room-types', 'public-room-availability', 'public-promotions', 'public-reservation-request', 'public-contact', 'public-help-chat', 'public-help-chat-sync', 'biometric-device-webhook','public-review'];

// Public endpoints (terutama Telegram webhook) tidak memiliki sesi browser.
// Inisialisasi eksplisit mencegah warning undefined variable berubah menjadi
// ErrorException/HTTP 500 oleh global error handler production.
$loggedInStaff = null;

if (!$pdo && !in_array($action, ['', 'status', 'ping', 'telegram-webhook'], true)) {
    http_response_code(503);
    echo json_encode([
        "success" => false,
        "error" => ($pdo_error ?: "Koneksi database gagal.") . " Gunakan database_connection_setup.php atau pasang file kredensial server."
    ]);
    exit();
}

if (!in_array($action, $public_actions, true)) {
    tamasyaRuntimeSetStage('auth:session');
    $loggedInStaff = requireAuth($pdo);
    tamasyaRuntimeSetActor($loggedInStaff);
} else {
    tamasyaRuntimeSetActor(null);
}
// Verify the Standby public gateway before endpoint rate limiting so the
// Primary uses the original visitor's opaque fingerprint instead of collapsing
// every forwarded guest into the Standby's single IP bucket.
if (in_array((string)$action,['public-reservation-request','public-help-chat','public-help-chat-sync'],true)) {
    try {
        $verifiedPublicGatewayFingerprint=tamasyaClusterVerifyPublicGatewayFingerprint((string)$action,(string)$rawRequestBody);
        if ($verifiedPublicGatewayFingerprint!==null) {
            $GLOBALS['tamasya_public_gateway_fingerprint']=$verifiedPublicGatewayFingerprint;
            $GLOBALS['tamasya_public_gateway_verified']=true;
        }
    } catch (Throwable $publicGatewayError) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Public gateway ditolak',$publicGatewayError)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if ($loggedInStaff) tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action);
if ($pdo instanceof PDO) tamasyaEnterpriseEndpointRateLimit($pdo, (string)$action, is_array($loggedInStaff) ? $loggedInStaff : null);

// Bind every authenticated browser request to the same opaque hotel namespace
// returned during login. This stops an endpoint resolver, stale browser setting,
// reverse proxy, or copied deployment from silently serving another database.
if ($loggedInStaff) {
    $clientHotelScope = strtolower(trim((string)($_SERVER['HTTP_X_TAMASYA_HOTEL_SCOPE'] ?? '')));
    $serverHotelScope = strtolower(tamasyaHotelScopeId());
    if ($clientHotelScope !== '' && !preg_match('/^hotel_[a-f0-9]{32}$/', $clientHotelScope)) {
        http_response_code(400);
        echo json_encode([
            'success'=>false,
            'code'=>'INVALID_HOTEL_SCOPE_HEADER',
            'error'=>'Header scope hotel tidak valid.',
            'hotelScopeId'=>$serverHotelScope,
            'requestId'=>tamasyaRuntimeRequestId()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($clientHotelScope !== '' && !hash_equals($serverHotelScope, $clientHotelScope)) {
        http_response_code(409);
        echo json_encode([
            'success'=>false,
            'code'=>'HOTEL_SCOPE_MISMATCH',
            'error'=>'Identitas server/database berubah atau request diarahkan ke endpoint hotel yang berbeda. Sesi wajib diikat ulang melalui login.',
            'hotelScopeId'=>$serverHotelScope,
            'requestId'=>tamasyaRuntimeRequestId()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Explicit company/property scope is additional to the opaque login hotel scope.
if ($loggedInStaff) {
    try { tamasyaHybridAssertRequestScope(tamasyaMultiPropertyIdentity(), $_SERVER); }
    catch (InvalidArgumentException $scopeError) {
        http_response_code(409);
        echo json_encode(['success'=>false,'code'=>'PROPERTY_SCOPE_MISMATCH','retryable'=>false,'operation_id'=>$GLOBALS['tamasya_request_operation_id']??null,'receipt'=>null,'status'=>'rejected','message'=>$scopeError->getMessage()]);
        exit;
    }
}

// Hak akses menu bukan sekadar penyembunyian tombol React. Endpoint khusus
// menu juga menolak request langsung bila Admin secara eksplisit mematikan tab
// tersebut. Role/capability di setiap route tetap menjadi lapisan otorisasi
// utama; fallback semua role di sini hanya menjaga kompatibilitas permission
// JSON lama yang belum mempunyai key desktopTabs lengkap.
if ($loggedInStaff) {
    $allDesktopPermissionRoles = ['admin','manager','receptionist','finance','owner','koki','tukang_kebun','cleaning_service','keamanan','lain_lain'];
    $actionDesktopTabs = [
        'booking-identity'=>['rooms'],
        'bookings'=>['rooms'],
        'facilities'=>['rooms','operations'],
        'multi-room-bookings'=>['rooms'],
        'rooms'=>['rooms'],
        'room-transfers'=>['rooms'],
        'booking-payments'=>['rooms','finance'],
        'bookings-status'=>['rooms'],
        'booking-negotiated-price'=>['rooms'],
        'guest-security-deposits'=>['rooms','finance'],
        'transaction-booking-action'=>['rooms','finance'],
        'transaction-allocation-void'=>['finance'],
        'transactions'=>['finance'],
        'transaction-proof'=>['finance','report'],
        'ota-disbursements'=>['finance','report'],
        'ota-receivables'=>['finance','report'],
        'categories'=>['finance'],
        'categories-update'=>['finance'],
        'categories/update'=>['finance'],
        'categories-semantic-bind'=>['finance'],
        'categories/semantic-bind'=>['finance'],
        'categories-delete'=>['finance'],
        'categories/delete'=>['finance'],
        'subcategories'=>['finance'],
        'subcategories-update'=>['finance'],
        'subcategories/update'=>['finance'],
        'subcategories-delete'=>['finance'],
        'subcategories/delete'=>['finance'],
        'bank-accounts'=>['finance','config'],
        'salary-slips'=>['staff','finance'],
        'salary-payment-correction'=>['staff','finance'],
        'staff-savings'=>['savings'],
        'pos-bootstrap'=>['pos'],
        'pos-products'=>['pos'],
        'pos-category-save'=>['pos'],
        'pos-product-save'=>['pos'],
        'pos-stock-adjust'=>['pos'],
        'pos-sale-create'=>['pos'],
        'pos-sale-void'=>['pos'],
        'pos-sales'=>['pos'],
        'pos-report'=>['pos','report'],
        'pos-sale-detail'=>['pos'],
        'pos-sale-print-log'=>['pos'],
        'pos-delivery-update'=>['pos'],
        'pos-deliveries'=>['pos'],
        'inventory'=>['inventory'],
        'inventory-maintenance'=>['inventory'],
        'staff'=>['staff','leaves'],
        'attendance'=>['attendance'],
        'growth-suite'=>['rooms','finance','report','operations','inventory'],
        'enterprise-suite'=>['rooms','finance','report','operations','inventory','config'],
        'reporting-periods'=>['report','finance'],
        'historical-backfill-review'=>['report','finance'],
        'biometric-status'=>['attendance'],
        'biometric-face-enroll'=>['attendance','staff'],
        'biometric-face-verify'=>['attendance'],
        'biometric-fingerprint-confirm'=>['attendance'],
        'biometric-devices'=>['attendance','staff'],
        'biometric-device-users'=>['attendance','staff'],
        'property-setup'=>['config','db_config'],
        'config'=>['config','db_config'],
        'communication-channels'=>['config'],
        'communication-channel-save'=>['config'],
        'communication-channel-toggle'=>['config'],
        'communication-channel-health'=>['config'],
        'communication-bindings'=>['config','staff'],
        'communication-binding-code'=>['config','staff'],
        'communication-unbind'=>['config','staff'],
        'communication-inbox'=>['config'],
        'communication-outbox'=>['config'],
        'communication-outbox-process'=>['config'],
        'communication-deliveries'=>['config'],
        'telegram-set-webhook'=>['config'],
        'telegram-delete-webhook'=>['config'],
        'telegram-webhook-info'=>['config'],
        'telegram-bot'=>['telegram'],
        'telegram-callback'=>['telegram'],
        'telegram-clear'=>['telegram'],
        'website-cms-data'=>['website'],
        'website-cms-settings-save'=>['website'],
        'website-cms-room-type-save'=>['website'],
        'website-cms-room-type-delete'=>['website'],
        'website-cms-promotion-save'=>['website'],
        'website-cms-promotion-delete'=>['website'],
        'website-cms-media-upload'=>['website'],
        'website-cms-media-delete'=>['website'],
        'public-support-inbox'=>['support'],
        'public-support-reply'=>['support'],
        'public-support-close'=>['support'],
        'public-reservation-review'=>['rooms','website'],
        'email-report'=>['report','finance'],
        'canonical-report'=>['report','finance'],
        'canonical-report-types'=>['report','finance'],
        'canonical-report-email'=>['report','finance'],
        'send-shift-report'=>['report','finance'],
        'data-cleanup-preview'=>['operations'],
        'data-cleanup-mode'=>['operations'],
        'data-cleanup-execute'=>['operations'],
        'data-cleanup-history'=>['operations'],
        'test-data-purge-preview'=>['operations'],
        'test-data-purge'=>['operations'],
        'download-schema'=>['db_config'],
        'download-backup'=>['db_config'],
        'download-json-backup'=>['db_config'],
        'db-reset'=>['db_config'],
        // Cluster/failover controls appear from Config and Operations Center.
        // Admin role alone must not bypass an explicit desktop-tab deny.
        'node-cluster-status'=>['config','operations'],
        'node-cluster-switch-primary'=>['config','operations'],
        'node-cluster-emergency-promote'=>['config','operations'],
        'node-cluster-recover-expired-primary'=>['config','operations'],
        'node-cluster-reconcile'=>['config','operations'],
        'node-sync-conflict-resolve'=>['config','operations'],
        'node-sync-status'=>['config','operations'],
        'runtime-scope-diagnostics'=>['config','db_config'],
        // Jejak audit dan inspector hak akses mengikuti tab staf, sama seperti
        // tool governance lainnya: kalau menu staf dimatikan untuk property ini,
        // keduanya ikut tertutup.
        'audit-logs'=>['staff'],
        'permission-inspector'=>['staff'],
        'system-health'=>['staff','config','db_config'],
        'guest-reviews'=>['staff','operations'],
        'wholesale-rates'=>['operations','report','rooms'],
        'exchange-rates'=>['finance','report','operations'],
    ];
    $requiredDesktopTabs = $actionDesktopTabs[(string)$action] ?? [];
    if ($requiredDesktopTabs) {
        $hasAnyRequiredDesktopTab = false;
        foreach ($requiredDesktopTabs as $requiredDesktopTab) {
            if (hasDesktopTabAccess($loggedInStaff, $requiredDesktopTab, $allDesktopPermissionRoles)) {
                $hasAnyRequiredDesktopTab = true;
                break;
            }
        }
        if (!$hasAnyRequiredDesktopTab) {
            http_response_code(403);
            echo json_encode([
                'success'=>false,
                'error'=>'Forbidden: hak akses menu untuk endpoint ini dinonaktifkan.',
                'requiredDesktopTabs'=>$requiredDesktopTabs
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

// Fresh V137 lifecycle gate: setup/master data may be configured before READY,
// but live booking/finance/POS mutations are fail-closed until the Admin explicitly
// finalizes this property. This keeps a new hotel/branch from operating on a
// half-configured identity, tax, payment, room-type, or room master.
if ($pdo instanceof PDO && function_exists('tamasyaPropertyActionRequiresReady')) {
    $propertyMethod=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $propertyCommand=trim((string)($input['command']??$_GET['command']??''));
    $propertyRequiresReady=tamasyaPropertyActionRequiresReady((string)$action,$propertyMethod,$propertyCommand);
    $GLOBALS['tamasya_financial_mutation_requires_ready']=$propertyRequiresReady;
    // In a fenced two-node cluster the standby is only a gateway/read replica.
    // It must not reject a write merely because its setup snapshot is a few
    // seconds behind the Primary. The active Primary re-runs this exact READY
    // gate after the request is forwarded, before any business mutation.
    $deferReadyToPrimary=$propertyRequiresReady
        && function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()
        && function_exists('tamasyaNodeRole') && tamasyaNodeRole()==='local_backup';
    if ($propertyRequiresReady && !$deferReadyToPrimary) {
        $propertySetup=tamasyaPropertySetupSnapshot($pdo);
        if (empty($propertySetup['ready'])) {
            http_response_code(409);
            echo json_encode([
                'success'=>false,
                'code'=>'PROPERTY_SETUP_REQUIRED',
                'error'=>'Property belum READY. Selesaikan Wizard Setup Hotel dan master minimum sebelum transaksi live.',
                'setupStatus'=>$propertySetup['setupStatus']??'not_initialized',
                'checks'=>$propertySetup['checks']??[]
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}
tamasyaRuntimeSetStage('policy:node_guard');

// Server lokal hanya menerima pekerjaan operasional. Perubahan identitas,
// konfigurasi, purge, webhook, dan kebijakan hanya dilakukan pada primary aktif.
if (!tamasyaNodeReplayActive()) {
    $requestMethodForPolicy = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    // Sync chat publik hanya membaca. Pengiriman chat dan reservasi adalah mutasi
    // idempoten yang wajib memiliki operation ID dan, ketika request tiba di
    // Standby, diteruskan ke Primary aktif tanpa menulis database Standby.
    $statelessPostActions = ['public-help-chat-sync','consistency-guard-maintenance'];
    $publicClusterGatewayActions=['public-reservation-request','public-help-chat','public-help-chat-sync'];
    if ($pdo && tamasyaClusterEnabled() && tamasyaNodeRole()==='local_backup' && in_array((string)$action,$publicClusterGatewayActions,true)) {
        $publicOperationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
        $publicWrite=in_array((string)$action,['public-reservation-request','public-help-chat'],true);
        if ($publicWrite && $publicOperationId==='') {
            http_response_code(428);
            echo json_encode(['success'=>false,'error'=>'X-Tamasya-Operation-ID wajib agar request website publik aman saat diteruskan ke Primary.','requiredHeader'=>'X-Tamasya-Operation-ID'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (tamasyaNodePreferOnline()) {
            $publicForward=tamasyaClusterForwardPublicWebsiteRequest((string)$action,(string)$rawRequestBody,$publicOperationId);
            if (!empty($publicForward['transportOk'])) {
                http_response_code((int)$publicForward['status']);
                header('X-Tamasya-Execution-Node: active-primary-public-gateway');
                echo (string)$publicForward['body'];
                exit;
            }
            if ($publicWrite) {
                http_response_code(!empty($publicForward['ambiguous'])?202:503);
                echo json_encode([
                    'success'=>false,
                    'pendingOnlineConfirmation'=>!empty($publicForward['ambiguous']),
                    'retryable'=>true,
                    'operationId'=>$publicOperationId,
                    'error'=>!empty($publicForward['ambiguous'])
                        ? 'Respons Primary belum dapat dipastikan. Ulangi dari tombol yang sama; operation ID yang sama mencegah data ganda.'
                        : 'Primary aktif tidak dapat dijangkau. Standby tetap read-only; lakukan failover resmi sebelum menerima mutasi website publik.',
                    'detail'=>$publicForward['error']??null
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            }
            // public-help-chat-sync boleh membaca snapshot lokal saat Primary tidak
            // dapat dijangkau; endpoint ini tidak mengubah data.
        }
    }
    $isStatelessPostAction = in_array((string)$action, $statelessPostActions, true);
    if ($pdo && !$isStatelessPostAction && in_array(strtoupper($requestMethodForPolicy), ['POST','PUT','PATCH','DELETE'], true)) {
        $mirrorGuardError = tamasyaLocalMirrorGuard($pdo);
        if ($mirrorGuardError !== null) {
            http_response_code(503);
            echo json_encode(['success'=>false,'error'=>$mirrorGuardError,'nodeRole'=>tamasyaNodeRole()]);
            exit;
        }
        $clusterGuardError = tamasyaClusterMutationGuard($pdo);
        if ($clusterGuardError !== null && tamasyaNodeRole() === 'online_primary' && !in_array((string)$action,['node-cluster-switch-primary','node-cluster-recover-expired-primary'],true)) {
            http_response_code(423);
            echo json_encode(['success'=>false,'error'=>$clusterGuardError,'cluster'=>tamasyaClusterPublicState($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $uncertainGuardError = tamasyaLocalUncertainMutationGuard($pdo, (string)$action, $input, $requestMethodForPolicy);
        if ($uncertainGuardError !== null) {
            http_response_code(423);
            echo json_encode([
                'success'=>false,
                'pendingOnlineConfirmation'=>true,
                'error'=>$uncertainGuardError,
                'nodeRole'=>tamasyaNodeRole()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    $localPolicyError = $isStatelessPostAction ? null : tamasyaLocalNodeBlockedAction((string)$action, $input, $requestMethodForPolicy);
    if ($localPolicyError !== null) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>$localPolicyError,'nodeRole'=>tamasyaNodeRole()]);
        exit;
    }
    if ($pdo && !$isStatelessPostAction && tamasyaNodeRole()==='local_backup' && tamasyaNodeSyncEnabled() && tamasyaNodeSyncAllowedAction((string)$action,$input,$requestMethodForPolicy)) {
        $settingsForward=$pdo->query("SELECT last_primary_revision FROM node_sync_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC)?:[];
        $GLOBALS['tamasya_local_primary_revision']=(int)($settingsForward['last_primary_revision']??0);
        // One event ID follows the request through online-forward, offline local
        // execution, outbox persistence, and online replay. generateServerId()
        // derives stable IDs from this event so dependent operations keep their
        // booking/shift/task references after failover.
        $browserOperationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
        if (tamasyaClusterEnabled() && $browserOperationId==='') {
            http_response_code(428);
            echo json_encode([
                'success'=>false,
                'error'=>'X-Tamasya-Operation-ID wajib sebelum Standby meneruskan mutasi ke Primary aktif.',
                'requiredHeader'=>'X-Tamasya-Operation-ID'
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        $forwardEventId=$browserOperationId!==''
            ? 'nodesync_op_'.hash('sha256',$browserOperationId)
            : 'nodesync_'.bin2hex(random_bytes(16));
        $GLOBALS['tamasya_node_operation_event_id']=$forwardEventId;
        $GLOBALS['tamasya_node_id_counters']=[];
        if (tamasyaNodePreferOnline()) {
            // Selaraskan epoch, fencing token, dan lease sebelum memakai standby
            // sebagai gateway. Ini menutup jendela startup ketika kedua database
            // masih memiliki token bootstrap yang berbeda.
            if (tamasyaClusterEnabled()) {
                try { tamasyaClusterAdoptHigherPeerEpoch($pdo); } catch (Throwable $ignored) {}
            }
            $forwardQuery=$_GET;unset($forwardQuery['action']);
            $forwardBody=trim((string)$rawRequestBody)!==''?(string)$rawRequestBody:(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
            $forward=tamasyaForwardCommandToPrimary(
                (string)$action,$requestMethodForPolicy,$forwardQuery,$forwardBody,
                (string)($loggedInStaff['id']??''),$forwardEventId,(string)($loggedInStaff['session_id']??'')
            );
            if (!empty($forward['ok'])) {
                http_response_code((int)($forward['status']??200));
                header('X-Tamasya-Execution-Node: active-primary');
                echo (string)($forward['body']??'');
                exit;
            }
            if (!empty($forward['attempted']) && empty($forward['definitelyOffline'])) {
                if (!empty($forward['ambiguous'])) {
                    tamasyaQueueAmbiguousForward($pdo,$forwardEventId,(string)$action,$requestMethodForPolicy,$forwardQuery,$input,$loggedInStaff,'Respons primary aktif tidak dapat dipastikan: '.((string)($forward['error']??'transport error')),$forwardBody);
                    http_response_code(202);
                    echo json_encode([
                        'success'=>false,'pendingOnlineConfirmation'=>true,'eventId'=>$forwardEventId,
                        'error'=>'Hasil transaksi pada primary aktif belum dapat dipastikan. Jangan mengulangi tindakan. Mutasi baru diblokir sampai agent memastikan event ini.',
                        'message'=>'Koneksi ke primary aktif terputus setelah pengiriman dimulai. Tindakan tidak dijalankan ulang secara lokal untuk mencegah transaksi ganda; agent akan memeriksa dan menyelesaikannya.'
                    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    exit;
                }
                http_response_code((int)($forward['status']??0)>0?(int)$forward['status']:503);
                header('X-Tamasya-Execution-Node: active-primary-rejected');
                if (trim((string)($forward['body']??''))!=='') echo (string)$forward['body'];
                else echo json_encode(['success'=>false,'error'=>'Primary aktif tidak dapat memproses request dan fallback lokal dihentikan demi mencegah split-brain.','detail'=>$forward['error']??null]);
                exit;
            }
            // Legacy local_backup boleh memakai fallback lokal. Flexible cluster
            // tidak pernah menulis pada standby; Admin harus mempromosikan node ini
            // terlebih dahulu agar hanya ada satu writer aktif.
            if (tamasyaClusterEnabled()) {
                http_response_code(503);
                echo json_encode([
                    'success'=>false,
                    'error'=>'Primary aktif tidak dapat dijangkau. Node standby tetap read-only untuk mencegah split-brain. Gunakan failover darurat hanya setelah primary lama dipastikan terisolasi.',
                    'cluster'=>tamasyaClusterPublicState($pdo)
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            }
            // Hanya kegagalan koneksi yang dipastikan terjadi sebelum request
            // mencapai online yang boleh jatuh ke eksekusi database lokal pada mode legacy.
        }
        $lockName='tamasya-node-mutation-'.substr(hash('sha256',tamasyaNodeId()),0,24);
        $lockStmt=$pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([$lockName]);
        if ((int)$lockStmt->fetchColumn()!==1) {
            http_response_code(503);
            echo json_encode(['success'=>false,'error'=>'Server lokal sedang menyelesaikan transaksi lain. Ulangi beberapa detik lagi.','nodeRole'=>tamasyaNodeRole()]);
            exit;
        }
        $GLOBALS['tamasya_local_mutation_counter_start']=(int)$pdo->query("SELECT local_mutation_counter FROM node_sync_settings WHERE id='system_default'")->fetchColumn();
    }
} else {
    $replayConflict = tamasyaNodeReplayConflict($pdo, (string)$action, (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'), $input, $_GET);
    if ($replayConflict !== null) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>$replayConflict,'eventId'=>$GLOBALS['tamasya_node_replay_event_id'] ?? null]);
        exit;
    }
}

// Every successful state-changing request announces a new server revision. This
// lets web clients follow changes from Telegram and other devices without relying
// on the development-only SSE server. Explicit bumps inside handlers are harmless;
// a revision is a monotonic change signal, not a transaction sequence number.
$revisionExcludedActions = ['', 'status', 'ping', 'login', 'verify-2fa', 'refresh-session', 'telegram-webhook', 'server-revision', 'download-backup', 'download-json-backup', 'transaction-proof', 'chat-messages', 'node-sync-snapshot', 'node-sync-status', 'node-sync-conflict-resolve', 'consistency-guard-maintenance', 'node-cluster-peer-status', 'node-cluster-control', 'node-cluster-status', 'node-cluster-switch-primary', 'node-cluster-emergency-promote', 'node-cluster-recover-expired-primary', 'node-cluster-reconcile', 'node-cluster-telegram-webhook', 'node-cluster-telegram-delivery', 'communication-webhook', 'node-cluster-channel-webhook', 'growth-payment-webhook', 'growth-channel-webhook', 'public-help-chat-sync', 'biometric-device-webhook'];
$isLocalOnlyNodeMutation = $pdo && tamasyaNodeRole()==='local_backup' && tamasyaLocalOnlyMutationAllowed((string)$action,$input,(string)($_SERVER['REQUEST_METHOD']??'GET'));
// Heartbeat hanya telemetry perangkat. Ia tidak boleh mengambil global mutation
// lock, membuat durable receipt, menaikkan server revision, atau masuk node outbox
// setiap menit. Perubahan owner/conflict tetap diaudit di handler-nya sendiri.
$isEphemeralMutation = (string)$action === 'operations-center'
    && strtolower(trim((string)($input['command'] ?? ''))) === 'device-heartbeat';
$revisionlessControlCommands=['push-summary','queue-snapshot','deliver-snapshot','requeue-snapshot'];
$isRevisionlessControlMutation=(string)$action==='multi-property'
    && in_array(strtolower(trim((string)($input['command']??''))),$revisionlessControlCommands,true);
$isMutatingRequest = $pdo && !$isLocalOnlyNodeMutation && !$isEphemeralMutation
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST','PUT','PATCH','DELETE'], true)
    && !in_array($action, $revisionExcludedActions, true);

// Tenant billing dummy: saat tenant suspended, semua mutasi data ditolak
// (kecuali reopen/status) agar token lama tidak mengubah data hotel.
$tenantSuspendedForMutation = false;
$tenantBillingCommand = trim((string)($_GET['command'] ?? $_POST['command'] ?? ($input['command'] ?? '')));
$tenantBillingAllowedWhileSuspended = ((string)$action === 'tenant-billing'
    && in_array(strtolower($tenantBillingCommand), ['reopen','status'], true));
if ($isMutatingRequest && !$tenantBillingAllowedWhileSuspended) {
    try {
        $propStmt = $pdo->query("SELECT property_id FROM property_settings WHERE id='system_default' LIMIT 1");
        $propId = trim((string)$propStmt->fetchColumn());
        if ($propId !== '') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_billing_accounts (property_id VARCHAR(190) NOT NULL PRIMARY KEY,status VARCHAR(20) NOT NULL DEFAULT 'active',plan VARCHAR(50) NOT NULL DEFAULT 'dummy',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $tb = $pdo->prepare("SELECT status FROM tenant_billing_accounts WHERE property_id=? LIMIT 1");
            $tb->execute([$propId]);
            if ((string)$tb->fetchColumn() === 'suspended') { $tenantSuspendedForMutation = true; }
        }
    } catch (Throwable $e) { $tenantSuspendedForMutation = false; }
}
if ($isMutatingRequest && $tenantSuspendedForMutation) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Tenant suspended'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$primaryMutationLockHeld = false;
if ($isMutatingRequest && tamasyaNodeRole() === 'online_primary') {
    $primaryMutationLockHeld = tamasyaAcquirePrimaryMutationLock($pdo, 10);
    if (!$primaryMutationLockHeld) {
        http_response_code(503);
        echo json_encode([
            'success'=>false,
            'retryable'=>true,
            'error'=>'Primary aktif sedang menyelesaikan transaksi atau snapshot. Ulangi beberapa detik lagi.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
$requestOperationClaim = null;
if ($isMutatingRequest && !tamasyaNodeReplayActive()) {
    if (trim((string)($GLOBALS['tamasya_request_operation_id'] ?? '')) === '') {
        if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
        http_response_code(428);
        echo json_encode([
            'success'=>false,
            'error'=>'X-Tamasya-Operation-ID wajib untuk mutasi agar retry tidak menggandakan atau menimpa data.',
            'requiredHeader'=>'X-Tamasya-Operation-ID'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (trim((string)($GLOBALS['tamasya_request_operation_id'] ?? '')) !== '') {
        $requestOperationIdForClaim = trim((string)($GLOBALS['tamasya_request_operation_id'] ?? ''));
        // Reserve an explicit namespace before claiming a public receipt. This
        // prevents unauthenticated website requests from occupying operation IDs
        // intended for authenticated staff mutations.
        $publicOperationPattern=null;$publicOperationLabel=null;
        if ((string)$action === 'public-reservation-request') { $publicOperationPattern='/^public_reservation_[A-Za-z0-9._:-]{8,80}$/'; $publicOperationLabel='reservasi publik'; }
        if ((string)$action === 'public-help-chat') { $publicOperationPattern='/^public_chat_[A-Za-z0-9._:-]{8,80}$/'; $publicOperationLabel='chat publik'; }
        if ($publicOperationPattern!==null && !preg_match($publicOperationPattern,$requestOperationIdForClaim)) {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
            http_response_code(422);
            echo json_encode([
                'success'=>false,
                'retryable'=>false,
                'error'=>'Operation ID '.$publicOperationLabel.' tidak valid.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        try {
            tamasyaRuntimeSetStage('operation:claim');
            // Public reservation requests have no authenticated staff session.
            // Use a server-resolved synthetic actor so the durable operation
            // receipt remains idempotent without trusting staff_id from the
            // public browser payload.
            $requestOperationActor = (array)$loggedInStaff;
            if (in_array((string)$action,['public-reservation-request','public-help-chat'],true)) {
                $requestOperationActor = [
                    'id' => 'public:website',
                    'session_id' => null,
                ];
            }
            $requestOperationClaim = tamasyaClaimRequestOperation($pdo, $requestOperationActor, (string)$action, (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'), (array)$_GET, (array)$input);
        } catch (Throwable $claimError) {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
            http_response_code(503);
            echo json_encode(['success'=>false,'retryable'=>false,'error'=>clientExceptionMessage('Receipt operation_id gagal diklaim',$claimError)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (($requestOperationClaim['state'] ?? '') === 'terminal') {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
            $row = $requestOperationClaim['row'] ?? [];
            $storedStatus = (int)($row['http_status'] ?? 200);
            if ($storedStatus <= 0) $storedStatus = 200;
            http_response_code($storedStatus);
            header('X-Tamasya-Idempotent-Replay: 1');
            $storedBody = tamasyaReplayDurableReceiptBody((string)($row['response_body'] ?? ''),static fn()=>getRoleScopedHotelData($pdo,$loggedInStaff));
            echo $storedBody !== '' ? $storedBody : json_encode(['success'=>(string)($row['status']??'')==='completed','duplicate'=>true,'operationId'=>$requestOperationClaim['operationId']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (($requestOperationClaim['state'] ?? '') === 'mismatch') {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
            http_response_code(409);
            echo json_encode(['success'=>false,'error'=>'operation_id pernah digunakan oleh payload, staf, perangkat, sesi, action, atau method yang berbeda.','operationId'=>$requestOperationClaim['operationId']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (in_array(($requestOperationClaim['state'] ?? ''), ['processing','uncertain'], true)) {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
            http_response_code(409);
            echo json_encode([
                'success'=>false,
                'pendingOnlineConfirmation'=>true,
                'receiptStatus'=>$requestOperationClaim['state'],
                'operationId'=>$requestOperationClaim['operationId']??null,
                'error'=>($requestOperationClaim['state']??'')==='uncertain'
                    ? 'Hasil operation_id ini tidak pasti dan wajib direkonsiliasi manual; request tidak dieksekusi ulang.'
                    : 'operation_id yang sama masih diproses; request kedua tidak dieksekusi.'
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}
if ($isMutatingRequest) {
    $GLOBALS['tamasya_runtime_has_mutation_finalizer'] = true;
    // Normalize the legacy JSON contract: a mutating handler that returns
    // HTTP 200 with {success:false} is a failure, not an auditable/outbox success.
    // Replay already owns a response buffer from requireAuth().
    if (!tamasyaNodeReplayActive()) ob_start();
    $auditInputSnapshot = tamasyaMutationAttemptAuditSnapshot((string)$action, (array)$input);
    $auditActionSnapshot = $action;
    $auditUserSnapshot = $loggedInStaff;
    $nodeInputSnapshot = $input;
    $nodeQuerySnapshot = $_GET;
    $nodeMethodSnapshot = (string)($_SERVER['REQUEST_METHOD'] ?? 'POST');
    $requestOperationIdSnapshot = (string)($requestOperationClaim['operationId'] ?? '');
    $requestOperationPayloadHashSnapshot = (string)($requestOperationClaim['payloadHash'] ?? '');
    register_shutdown_function(function() use ($pdo, $auditInputSnapshot, $auditActionSnapshot, $auditUserSnapshot, $nodeInputSnapshot, $nodeQuerySnapshot, $nodeMethodSnapshot, $primaryMutationLockHeld, $requestOperationIdSnapshot, $requestOperationPayloadHashSnapshot, $isRevisionlessControlMutation) {
        try {
            tamasyaRuntimeSetStage('mutation:finalize');
            $status = http_response_code();
            if ($status === false || $status === 0) $status = 200;
            $responseBody = (string)(ob_get_contents() ?: '');
            $fatalError = error_get_last();
            $fatalTypes = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
            $hasFatal = is_array($fatalError) && in_array((int)($fatalError['type'] ?? 0), $fatalTypes, true);

            if ($hasFatal) {
                $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                error_log('[api.php]['.$reference.'] Fatal mutation shutdown: '.(string)($fatalError['message'] ?? 'unknown fatal error'));
                $status = 500;
                $responseBody = tamasyaJsonEncode([
                    'success'=>false,
                    'error'=>'Mutasi gagal diproses oleh server. Referensi: '.$reference
                ]);
                if (ob_get_level() > 0) {
                    @ob_clean();
                    echo $responseBody;
                } elseif (!headers_sent()) {
                    echo $responseBody;
                }
                if (!headers_sent()) header('X-Tamasya-Error-Reference: '.$reference);
                http_response_code(500);
            }

            $classification = tamasyaNodeClassifyResponse((int)$status, $responseBody);
            if (empty($classification['jsonValid'])) {
                $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                error_log('[api.php]['.$reference.'] Handler menghasilkan respons JSON tidak valid untuk action '.$auditActionSnapshot.'.');
                $status = 500;
                $responseBody = tamasyaJsonEncode([
                    'success'=>false,
                    'error'=>'Respons API tidak valid dan diblokir agar tidak merusak data client. Referensi: '.$reference
                ]);
                $classification = tamasyaNodeClassifyResponse(500, $responseBody);
                if (ob_get_level() > 0) {
                    @ob_clean();
                    echo $responseBody;
                }
                if (!headers_sent()) header('X-Tamasya-Error-Reference: '.$reference);
                http_response_code(500);
            } elseif (!$classification['completed'] && $status >= 200 && $status < 400 && !headers_sent()) {
                http_response_code((int)$classification['httpStatus']);
            }
            $successful = !empty($classification['completed']);

            if ($requestOperationIdSnapshot !== '' && $requestOperationPayloadHashSnapshot !== '') {
                try {
                    tamasyaRuntimeSetStage('mutation:receipt');
                    tamasyaCompleteRequestOperation($pdo,$requestOperationIdSnapshot,$requestOperationPayloadHashSnapshot,(int)$status,$responseBody,$classification,$fatalError,(string)$auditActionSnapshot);
                } catch (Throwable $receiptError) {
                    $receiptMessage = clientExceptionMessage('Penyelesaian receipt operation_id gagal', $receiptError);
                    tamasyaMarkRequestOperationUncertain($pdo,$requestOperationIdSnapshot,$receiptMessage);
                    error_log('[api.php] '.$receiptMessage);
                    // Jangan melempar ulang: respons handler sudah final dan tidak
                    // boleh ditambah JSON kedua.
                }
            }

            // Revision hanya berubah bila data bisnis benar-benar berhasil berubah.
            // Percobaan yang ditolak/gagal tetap masuk audit, tetapi tidak memicu
            // reload semua perangkat atau node-sync palsu.
            if (!$pdo->inTransaction() && $successful && !$isRevisionlessControlMutation && !tamasyaServerRevisionWasBumped()) {
                // Banyak workflow menaikkan revision di dalam transaksi yang sama
                // dengan mutasi bisnis. Finalizer hanya menjadi fallback untuk route
                // yang belum melakukannya; jangan menaikkan dua kali untuk satu request.
                bumpServerRevision($pdo);
            }
            $source = str_contains($auditActionSnapshot, 'telegram') ? 'telegram' : ($auditActionSnapshot === 'sync' ? 'offline-sync' : (tamasyaNodeReplayActive() ? 'node-replay' : 'web'));
            $entityId = $auditInputSnapshot['id'] ?? $auditInputSnapshot['bookingId'] ?? $auditInputSnapshot['transactionId'] ?? ($_GET['id'] ?? null);
            $outcome = $successful ? 'success' : (((int)$status >= 400 && (int)$status < 500) ? 'rejected' : 'failed');
            writeEnterpriseAudit(
                $pdo,
                $auditUserSnapshot,
                'MUTATION ATTEMPT '.strtoupper($nodeMethodSnapshot).' '.$auditActionSnapshot,
                'mutation_attempt',
                $entityId,
                null,
                ['action'=>$auditActionSnapshot,'method'=>$nodeMethodSnapshot,'httpStatus'=>(int)$status,'request'=>$auditInputSnapshot],
                $source,
                ['outcome'=>$outcome]
            );
            if ($successful && !$isRevisionlessControlMutation) {
                tamasyaRuntimeSetStage('mutation:audit_outbox');
                try {
                    tamasyaEnqueueNodeCommand($pdo, (string)$auditActionSnapshot, $nodeMethodSnapshot, $nodeQuerySnapshot, $nodeInputSnapshot, $auditUserSnapshot);
                } catch (Throwable $nodeError) {
                    $nodeMessage=clientExceptionMessage('[node-sync] gagal menulis outbox', $nodeError);
                    error_log($nodeMessage);
                    // Mutasi Primary sudah final dan receipt tidak boleh dibuat retryable.
                    // Catat kegagalan replikasi sebagai issue terpisah agar Admin dapat
                    // melakukan snapshot/replay tanpa menggandakan transaksi bisnis.
                    try{
                        $issueEntity=$requestOperationIdSnapshot!==''?$requestOperationIdSnapshot:($requestIdSnapshot??tamasyaRuntimeRequestId());
                        $issueId='issue_node_outbox_'.substr(hash('sha256',(string)$issueEntity.'|'.(string)$auditActionSnapshot),0,32);
                        $stmtIssue=$pdo->prepare("INSERT INTO data_integrity_issues
                            (id,issue_type,entity_type,entity_id,severity,details,status,detected_at)
                            VALUES (?,'node_outbox_enqueue_failure','mutation',?,'critical',?,'open',CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE severity='critical',details=VALUES(details),status='open',resolved_at=NULL,resolution_note=NULL,detected_at=CURRENT_TIMESTAMP");
                        $stmtIssue->execute([$issueId,(string)$issueEntity,substr('action='.$auditActionSnapshot.'; '.$nodeMessage,0,4000)]);
                    }catch(Throwable $issueError){
                        error_log(clientExceptionMessage('[node-sync] gagal menyimpan issue outbox', $issueError));
                    }
                }
            }
            tamasyaRuntimeSetStage('mutation:complete');
            tamasyaRuntimePersist($pdo, (int)$status, null, $successful ? null : 'Mutation rejected or failed');
        } catch (Throwable $shutdownError) {
            tamasyaRuntimeSetStage('mutation:finalize_error');
            $shutdownMessage = clientExceptionMessage('Finalisasi mutasi gagal', $shutdownError);
            tamasyaRuntimePersist($pdo, 500, $shutdownError, $shutdownMessage);
            tamasyaMarkRequestOperationUncertain($pdo,$requestOperationIdSnapshot,$shutdownMessage);
            error_log('[api.php] '.$shutdownMessage);
            // Sangat penting: jangan echo apa pun di sini. Body API yang sudah
            // dibuat handler harus tetap satu dokumen JSON.
        } finally {
            if ($primaryMutationLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
        }
    });
}



/** Build a read-only impact preview for permanent removal of one test booking. */

/* testDataPurgePreview moved to api/support/100_cleanup_tools.php */



/* verifyActiveAdminPassword moved to api/support/100_cleanup_tools.php */



/* recalculatePurgedShiftSessions moved to api/support/100_cleanup_tools.php */




/* normalizeCleanupIds moved to api/support/100_cleanup_tools.php */



/* getImplementationCleanupMode moved to api/support/100_cleanup_tools.php */



/* cleanupPlaceholders moved to api/support/100_cleanup_tools.php */



/** Return OTA receivable rows after explicit allocations and legacy FIFO reservation. */

/* buildOtaReceivableState moved to api/support/100_cleanup_tools.php */



/* otaReceivablePublicState moved to api/support/100_cleanup_tools.php */


/** Find OTA disbursement dependencies for selected receivable transactions. */

/* buildOtaCleanupAdjustments moved to api/support/100_cleanup_tools.php */


/**
 * Preview a selected cleanup batch. Booking-linked transactions are expanded to
 * the whole booking group. Standalone manual transactions may be selected on
 * their own. Sensitive source workflows remain blocked.
 */

/* buildImplementationCleanupPreview moved to api/support/100_cleanup_tools.php */



/* recalculateCleanupShiftSessions moved to api/support/100_cleanup_tools.php */



/* insertSyncTombstones moved to api/support/100_cleanup_tools.php */



/* syncEntityHasTombstone moved to api/support/100_cleanup_tools.php */


/** Relasi operasional yang membuat hard-delete booking biasa tidak aman. */

/* bookingOperationalDeletionBlockers moved to api/support/100_cleanup_tools.php */


// A Standby can keep browser sessions local, but it cannot hold or use the
// Telegram bot token. This signed+encrypted RPC asks only the active Primary to
// deliver a short-lived login OTP. The Primary resolves the verified binding
// from staff_id again; telegram_user_id/chat_id is never accepted from the peer.
if ($action === 'node-cluster-telegram-delivery') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); exit;
    }
    try {
        $verifiedDelivery=tamasyaVerifyNodeRequest($pdo,'node-cluster-telegram-delivery',(string)$rawRequestBody,true);
        if (!tamasyaClusterEnabled() || tamasyaNodeRole()!=='online_primary') throw new RuntimeException('Pengiriman Telegram cluster hanya diproses oleh Primary aktif.');
        $leaseError=tamasyaClusterEnsureWriteLease($pdo);
        if ($leaseError!==null || !tamasyaExternalSideEffectsAllowed()) throw new RuntimeException($leaseError ?: 'Efek eksternal Telegram diblokir karena lease Primary tidak aktif.');
        if (trim((string)($input['clusterId']??''))!==tamasyaClusterId()) throw new RuntimeException('Cluster ID pengiriman Telegram tidak cocok.');
        $sealed=$input['sealed']??null;
        if (!is_array($sealed)) throw new InvalidArgumentException('Envelope OTP Telegram tidak tersedia.');
        $delivery=tamasyaClusterOpenSensitivePayload($sealed);
        $purpose=trim((string)($delivery['purpose']??''));
        $staffId=trim((string)($delivery['staffId']??''));
        $otp=trim((string)($delivery['otp']??''));
        $deliveryId=strtolower(trim((string)($delivery['deliveryId']??'')));
        $expiresAt=(int)($delivery['expiresAt']??0);
        if ($purpose!=='login_2fa' || !preg_match('/^[A-Za-z0-9._:-]{1,50}$/',$staffId) || !preg_match('/^[0-9]{6}$/',$otp) || !preg_match('/^[a-f0-9]{64}$/',$deliveryId)) {
            throw new InvalidArgumentException('Isi OTP Telegram cluster tidak valid.');
        }
        if ($expiresAt<time()-15 || $expiresAt>time()+600) throw new RuntimeException('OTP Telegram cluster sudah kedaluwarsa atau masa berlakunya tidak wajar.');
        $expectedEvent='cluster_2fa_'.substr($deliveryId,0,64);
        if (!hash_equals($expectedEvent,(string)($verifiedDelivery['eventId']??''))) throw new RuntimeException('Delivery ID OTP tidak terikat pada nonce request node.');

        $staffStmt=$pdo->prepare("SELECT id,name,status,two_factor_active,telegram_chat_id FROM staff WHERE id=? LIMIT 1");
        $staffStmt->execute([$staffId]);
        $deliveryStaff=$staffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$deliveryStaff || (string)($deliveryStaff['status']??'')!=='active' || (int)($deliveryStaff['two_factor_active']??0)!==1) {
            throw new RuntimeException('Akun staff tujuan OTP tidak aktif atau 2FA tidak aktif pada Primary.');
        }
        $telegramUserId=resolveTelegramUserIdForStaff($pdo,$staffId,$deliveryStaff['telegram_chat_id']??null);
        if ($telegramUserId==='') throw new RuntimeException('Binding Telegram terverifikasi untuk staff tujuan tidak ditemukan pada Primary.');
        $confStmt=$pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1");
        $conf=$confStmt?($confStmt->fetch(PDO::FETCH_ASSOC)?:[]):[];
        $token=trim(decryptStoredSecret($conf['telegram_bot_token']??''));
        if ($token==='') throw new RuntimeException('Token bot Telegram tidak tersedia pada Primary aktif.');
        $remaining=max(1,(int)ceil(($expiresAt-time())/60));
        $safeName=trim((string)($deliveryStaff['name']??'Staff'));
        $message="🔐 KODE VERIFIKASI (OTP) LOGIN

Halo {$safeName},
Kode OTP TAMASYA Anda: {$otp}

Kode berlaku sekitar {$remaining} menit. Jangan bagikan kode ini kepada siapa pun.";
        $sendResult=telegramApiCall($token,'sendMessage',['chat_id'=>$telegramUserId,'text'=>$message]);
        if (empty($sendResult['ok'])) throw new RuntimeException('Telegram menolak pengiriman OTP: '.telegramApiErrorMessage($sendResult,'pengiriman gagal'));
        logActivity($pdo,'cluster_login_2fa_delivered','OTP login 2FA dikirim oleh Primary aktif untuk request node '.substr(hash('sha256',(string)($verifiedDelivery['nodeId']??'')),0,12),$staffId,$safeName);
        tamasyaClusterRecordEvent($pdo,'telegram_2fa_delivery',(string)($verifiedDelivery['nodeId']??''),tamasyaNodeId(),(int)(($GLOBALS['tamasya_cluster_state']['leadership_epoch']??0)),'OTP login dikirim oleh Primary aktif.',$staffId,['deliveryHash'=>substr(hash('sha256',$deliveryId),0,24)]);
        echo json_encode(['success'=>true,'delivered'=>true,'deliveryId'=>$deliveryId,'primaryNodeId'=>tamasyaNodeId()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $deliveryError) {
        http_response_code($deliveryError instanceof InvalidArgumentException?400:503);
        echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Pengiriman OTP Telegram cluster gagal',$deliveryError)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// HMAC-forwarded provider-neutral webhook from a standby gateway is normalized
// into the public Communication Core endpoint. The primary verifies the bridge
// signature again before resolving any staff identity or executing a command.
if ($action === 'node-cluster-channel-webhook') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); exit;
    }
    try {
        $verifiedChannel=tamasyaVerifyNodeRequest($pdo,'node-cluster-channel-webhook',(string)$rawRequestBody,true);
        if(!tamasyaClusterEnabled()||tamasyaNodeRole()!=='online_primary')throw new RuntimeException('Channel webhook cluster hanya diproses oleh primary aktif.');
        if(trim((string)($input['clusterId']??''))!==tamasyaClusterId())throw new RuntimeException('Cluster ID channel webhook tidak cocok.');
        $channelId=trim((string)($input['channelId']??''));
        $forwardHeaders=is_array($input['headers']??null)?$input['headers']:[];
        $decodedBody=base64_decode((string)($input['rawBodyBase64']??''),true);
        if(!preg_match('/^[A-Za-z0-9._:-]{3,80}$/',$channelId)||$decodedBody===false)throw new InvalidArgumentException('Payload channel webhook cluster tidak valid.');
        $GLOBALS['tamasya_cluster_forwarded_channel']=true;
        $GLOBALS['tamasya_cluster_forwarded_from']=$verifiedChannel['nodeId']??null;
        $GLOBALS['tamasya_forwarded_channel_id']=$channelId;
        $GLOBALS['tamasya_forwarded_channel_headers']=$forwardHeaders;
        $_GET['channelId']=$channelId;
        $rawRequestBody=$decodedBody;
        $input=json_decode($decodedBody,true);if(!is_array($input))$input=[];
        $action='communication-webhook';
    } catch(Throwable $clusterChannelError) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Forward channel webhook ditolak',$clusterChannelError)]);
        exit;
    }
}

// HMAC-forwarded Telegram webhook from a standby gateway is normalized
// into the existing Telegram handler. The primary processes business logic and
// sends replies using its own independently configured bot token.
if ($action === 'node-cluster-telegram-webhook') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); exit;
    }
    try {
        $verifiedClusterTelegram = tamasyaVerifyNodeRequest($pdo, 'node-cluster-telegram-webhook', (string)$rawRequestBody, true);
        if (!tamasyaClusterEnabled() || tamasyaNodeRole() !== 'online_primary') throw new RuntimeException('Webhook cluster hanya diproses oleh primary aktif.');
        if (trim((string)($input['clusterId'] ?? '')) !== tamasyaClusterId()) throw new RuntimeException('Cluster ID webhook tidak cocok.');
        $forwardedUpdate = $input['update'] ?? null;
        if (!is_array($forwardedUpdate)) throw new InvalidArgumentException('Payload update Telegram tidak valid.');
        $GLOBALS['tamasya_cluster_forwarded_telegram'] = true;
        $GLOBALS['tamasya_cluster_forwarded_from'] = $verifiedClusterTelegram['nodeId'] ?? null;
        $input = $forwardedUpdate;
        $rawRequestBody = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $action = 'telegram-webhook';
    } catch (Throwable $clusterTelegramError) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Forward Telegram cluster ditolak',$clusterTelegramError)]);
        exit;
    }
}

tamasyaRuntimeSetStage('route:' . ((string)$action !== '' ? (string)$action : 'status'));
require __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'router.php';
