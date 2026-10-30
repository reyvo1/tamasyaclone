<?php
/**
 * Runtime observability for staging and production-safe correlation.
 * Stores metadata only: never payload bodies, passwords, bearer tokens, Telegram IDs,
 * secrets, guest identity documents, or financial descriptions.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaRuntimeTraceMode(): string {
    $raw = strtolower(trim((string)(getenv('APP_RUNTIME_TRACE') ?: '')));
    if (in_array($raw, ['off','errors','mutations','all'], true)) return $raw;
    $env = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
    return in_array($env, ['staging','development','dev','test','local'], true) ? 'mutations' : 'errors';
}

function tamasyaRuntimeRequestId(): string {
    $current = trim((string)($GLOBALS['tamasya_runtime_request_id'] ?? ''));
    if ($current !== '') return $current;
    $incoming = trim((string)($_SERVER['HTTP_X_TAMASYA_REQUEST_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $incoming)) {
        $current = $incoming;
    } else {
        try { $random = bin2hex(random_bytes(8)); }
        catch (Throwable $ignored) { $random = str_replace('.', '', uniqid('', true)); }
        $current = 'req_' . gmdate('Ymd_His') . '_' . substr($random, 0, 16);
    }
    $GLOBALS['tamasya_runtime_request_id'] = $current;
    return $current;
}

function tamasyaRuntimeInit(): void {
    $GLOBALS['tamasya_runtime_started_at'] = microtime(true);
    $GLOBALS['tamasya_runtime_stage'] = 'bootstrap:init';
    $GLOBALS['tamasya_runtime_action'] = '';
    $GLOBALS['tamasya_runtime_actor'] = null;
    $GLOBALS['tamasya_runtime_persisted'] = false;
    $GLOBALS['tamasya_runtime_failed_stage'] = null;
    $GLOBALS['tamasya_runtime_source_file'] = null;
    $GLOBALS['tamasya_runtime_source_line'] = null;
    $GLOBALS['tamasya_runtime_caught_error_class'] = null;
    $GLOBALS['tamasya_runtime_caught_sql_state'] = null;
    $GLOBALS['tamasya_runtime_error_severity'] = null;
    $GLOBALS['tamasya_runtime_request_id'] = tamasyaRuntimeRequestId();
    if (!headers_sent()) header('X-Tamasya-Request-ID: ' . tamasyaRuntimeRequestId());
}

function tamasyaRuntimeSetStage(string $stage): void {
    $stage = strtolower(trim(preg_replace('/[^A-Za-z0-9._:-]+/', '_', $stage)));
    $GLOBALS['tamasya_runtime_stage'] = substr($stage !== '' ? $stage : 'unknown', 0, 100);
}


function tamasyaRuntimeRelativeSourceFile(string $file): string {
    $file = str_replace('\\', '/', trim($file));
    $root = defined('TAMASYA_APP_ROOT') ? str_replace('\\', '/', rtrim((string)TAMASYA_APP_ROOT, '/\\')) : '';
    if ($root !== '' && str_starts_with($file, $root . '/')) {
        $file = substr($file, strlen($root) + 1);
    } elseif (str_starts_with($file, '/') || preg_match('/^[A-Za-z]:\//', $file)) {
        $file = basename($file);
    }
    $file = preg_replace('~[^A-Za-z0-9._/\-]+~', '_', $file) ?? 'unknown.php';
    return substr($file !== '' ? $file : 'unknown.php', 0, 255);
}

function tamasyaRuntimeCaptureThrowable(Throwable $error, ?string $failedStage = null): void {
    $stage = trim((string)($failedStage ?? ($GLOBALS['tamasya_runtime_stage'] ?? 'unknown')));
    $GLOBALS['tamasya_runtime_failed_stage'] = substr($stage !== '' ? $stage : 'unknown', 0, 100);
    $GLOBALS['tamasya_runtime_source_file'] = tamasyaRuntimeRelativeSourceFile($error->getFile());
    $GLOBALS['tamasya_runtime_source_line'] = max(0, (int)$error->getLine());
    // Caught routes (e.g. Telegram webhook) still need exception metadata at shutdown.
    // Persist only class/SQLSTATE, never untrusted exception messages or credentials.
    $GLOBALS['tamasya_runtime_caught_error_class'] = substr(get_class($error), 0, 100);
    $sqlState = $error instanceof PDOException ? (string)($error->errorInfo[0] ?? $error->getCode() ?? '') : '';
    $GLOBALS['tamasya_runtime_caught_sql_state'] = preg_match('/^[A-Z0-9]{5}$/', $sqlState) ? $sqlState : null;
    $GLOBALS['tamasya_runtime_error_severity'] = $error instanceof ErrorException ? (int)$error->getSeverity() : null;
}

function tamasyaRuntimeCaptureFatal(array $fatal, ?string $failedStage = null): void {
    $stage = trim((string)($failedStage ?? ($GLOBALS['tamasya_runtime_stage'] ?? 'unknown')));
    $GLOBALS['tamasya_runtime_failed_stage'] = substr($stage !== '' ? $stage : 'unknown', 0, 100);
    $GLOBALS['tamasya_runtime_source_file'] = tamasyaRuntimeRelativeSourceFile((string)($fatal['file'] ?? 'unknown.php'));
    $GLOBALS['tamasya_runtime_source_line'] = max(0, (int)($fatal['line'] ?? 0));
    $GLOBALS['tamasya_runtime_error_severity'] = isset($fatal['type']) ? (int)$fatal['type'] : null;
}

function tamasyaRuntimeSetAction(string $action): void {
    $GLOBALS['tamasya_runtime_action'] = substr(trim($action), 0, 100);
}

function tamasyaRuntimeSetActor(?array $staff): void {
    if (!$staff) { $GLOBALS['tamasya_runtime_actor'] = null; return; }
    $GLOBALS['tamasya_runtime_actor'] = [
        'staffId' => substr(trim((string)($staff['id'] ?? '')), 0, 50),
        'role' => substr(trim((string)($staff['role'] ?? '')), 0, 50),
    ];
}

function tamasyaRuntimeDurationMs(): int {
    $started = (float)($GLOBALS['tamasya_runtime_started_at'] ?? microtime(true));
    return max(0, (int)round((microtime(true) - $started) * 1000));
}

function tamasyaRuntimeOperationHash(): ?string {
    $operationId = trim((string)($GLOBALS['tamasya_request_operation_id'] ?? ''));
    return $operationId === '' ? null : hash('sha256', $operationId);
}

function tamasyaRuntimeSanitizeMessage(string $message): string {
    $message = trim(preg_replace('/\s+/', ' ', $message));
    $patterns = [
        '/(authorization\s*[:=]\s*bearer\s+)[^\s,;]+/i' => '$1[REDACTED]',
        '/((?:password|passwd|pwd|token|secret|api[_-]?key|telegram[_-]?token)\s*[:=]\s*)[^\s,;]+/i' => '$1[REDACTED]',
        '/(mysql:\s*host=[^;]+;dbname=)[^;\s]+/i' => '$1[REDACTED]',
        '~(?:[A-Za-z]:\\\\|/)(?:[^\s:]+[\\\\/])+[^\s:]*~' => '[SERVER_PATH]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $message = preg_replace($pattern, $replacement, $message) ?? $message;
    }
    return substr($message, 0, 500);
}

function tamasyaRuntimeSafeError(Throwable $error): array {
    $sqlState = null;
    if ($error instanceof PDOException) {
        $candidate = (string)($error->errorInfo[0] ?? $error->getCode() ?? '');
        if (preg_match('/^[A-Z0-9]{5}$/', $candidate)) $sqlState = $candidate;
    }
    $failedStage = trim((string)($GLOBALS['tamasya_runtime_failed_stage'] ?? ($GLOBALS['tamasya_runtime_stage'] ?? 'unknown')));
    $sourceFile = trim((string)($GLOBALS['tamasya_runtime_source_file'] ?? ''));
    $sourceLine = (int)($GLOBALS['tamasya_runtime_source_line'] ?? 0);
    $severity = $GLOBALS['tamasya_runtime_error_severity'] ?? ($error instanceof ErrorException ? $error->getSeverity() : null);
    return [
        'errorClass' => substr(get_class($error), 0, 100),
        'sqlState' => $sqlState,
        'errorMessage' => tamasyaRuntimeSanitizeMessage($error->getMessage()),
        'failedStage' => substr($failedStage !== '' ? $failedStage : 'unknown', 0, 100),
        'sourceFile' => $sourceFile !== '' ? tamasyaRuntimeRelativeSourceFile($sourceFile) : tamasyaRuntimeRelativeSourceFile($error->getFile()),
        'sourceLine' => $sourceLine > 0 ? $sourceLine : max(0, (int)$error->getLine()),
        'errorSeverity' => $severity !== null ? (int)$severity : null,
    ];
}

function tamasyaRuntimeContext(array $extra = []): array {
    $actor = is_array($GLOBALS['tamasya_runtime_actor'] ?? null) ? $GLOBALS['tamasya_runtime_actor'] : [];
    $context = [
        'requestId' => tamasyaRuntimeRequestId(),
        'action' => (string)($GLOBALS['tamasya_runtime_action'] ?? ''),
        'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'stage' => (string)($GLOBALS['tamasya_runtime_stage'] ?? 'unknown'),
        'staffId' => (string)($actor['staffId'] ?? ''),
        'role' => (string)($actor['role'] ?? ''),
        'nodeId' => function_exists('tamasyaNodeId') ? (string)tamasyaNodeId() : '',
        'operationHash' => tamasyaRuntimeOperationHash(),
        'durationMs' => tamasyaRuntimeDurationMs(),
    ];
    foreach ($extra as $key => $value) {
        if (is_scalar($value) || $value === null) $context[$key] = $value;
    }
    return $context;
}

function tamasyaRuntimeLog(string $event, array $extra = [], ?Throwable $error = null): void {
    $payload = tamasyaRuntimeContext(['event' => substr($event, 0, 80)] + $extra);
    if ($error) $payload += tamasyaRuntimeSafeError($error);
    error_log('[TAMASYA-RUNTIME] ' . tamasyaJsonEncode($payload));
}

/** Fresh V137: observability schema is part of database_setup.sql; runtime never mutates DDL. */
function tamasyaRuntimeEnsureSchema(PDO $pdo): void {
    tamasyaAssertTablesExist($pdo, ['runtime_request_events'], 'Runtime observability');
}

function tamasyaRuntimeShouldPersist(int $status): bool {
    $mode = tamasyaRuntimeTraceMode();
    if ($mode === 'off') return false;
    if ($status >= 400) return true;
    if ($mode === 'all') return true;
    if ($mode === 'mutations') return in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST','PUT','PATCH','DELETE'], true);
    return false;
}

function tamasyaRuntimePersist(PDO $pdo, int $status, ?Throwable $error = null, ?string $safeMessage = null, ?string $errorReference = null): void {
    if (!empty($GLOBALS['tamasya_runtime_persisted'])) return;
    if (!tamasyaRuntimeShouldPersist($status)) return;
    $GLOBALS['tamasya_runtime_persisted'] = true;
    try {
        tamasyaRuntimeEnsureSchema($pdo);
        $context = tamasyaRuntimeContext();
        $errorData = $error ? tamasyaRuntimeSafeError($error) : [];
        $outcome = $status >= 500 ? 'server_error' : ($status >= 400 ? 'rejected' : 'success');
        $message = trim((string)($safeMessage ?? ($errorData['errorMessage'] ?? '')));
        $failedStage = trim((string)($errorData['failedStage'] ?? ($GLOBALS['tamasya_runtime_failed_stage'] ?? '')));
        $sourceFile = trim((string)($errorData['sourceFile'] ?? ($GLOBALS['tamasya_runtime_source_file'] ?? '')));
        $sourceLine = (int)($errorData['sourceLine'] ?? ($GLOBALS['tamasya_runtime_source_line'] ?? 0));
        $errorSeverity = $errorData['errorSeverity'] ?? ($GLOBALS['tamasya_runtime_error_severity'] ?? null);
        $stmt = $pdo->prepare("INSERT INTO runtime_request_events
            (request_id,operation_hash,action,http_method,stage,failed_stage,http_status,outcome,staff_id,staff_role,node_id,duration_ms,error_reference,error_class,source_file,source_line,error_severity,sql_state,safe_message,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE stage=VALUES(stage),failed_stage=VALUES(failed_stage),http_status=VALUES(http_status),outcome=VALUES(outcome),duration_ms=VALUES(duration_ms),error_reference=VALUES(error_reference),error_class=VALUES(error_class),source_file=VALUES(source_file),source_line=VALUES(source_line),error_severity=VALUES(error_severity),sql_state=VALUES(sql_state),safe_message=VALUES(safe_message)");
        $stmt->execute([
            $context['requestId'], $context['operationHash'], $context['action'], $context['method'], $context['stage'], $failedStage !== '' ? $failedStage : null, $status, $outcome,
            $context['staffId'] !== '' ? $context['staffId'] : null,
            $context['role'] !== '' ? $context['role'] : null,
            $context['nodeId'] !== '' ? $context['nodeId'] : null,
            $context['durationMs'], $errorReference ?: null,
            $errorData['errorClass'] ?? ($GLOBALS['tamasya_runtime_caught_error_class'] ?? null),
            $sourceFile !== '' ? tamasyaRuntimeRelativeSourceFile($sourceFile) : null,
            $sourceLine > 0 ? $sourceLine : null,
            $errorSeverity !== null ? (int)$errorSeverity : null,
            $errorData['sqlState'] ?? ($GLOBALS['tamasya_runtime_caught_sql_state'] ?? null),
            $message !== '' ? tamasyaRuntimeSanitizeMessage($message) : null,
        ]);
        tamasyaRuntimeLog('request_result', ['httpStatus'=>$status,'outcome'=>$outcome,'errorReference'=>$errorReference]);
    } catch (Throwable $persistError) {
        tamasyaRuntimeLog('telemetry_persist_error', ['httpStatus' => $status], $persistError);
    }
}

function tamasyaRuntimeCriticalSchemaContract(): array {
    return [
        'staff' => ['id','name','username','password','role','status','two_factor_active','permissions'],
        'user_sessions' => ['id','staff_id','token_hash','device_id','expires_at','refresh_token_hash','refresh_expires','revoked_at'],
        'config' => ['id','server_revision'],
        'rooms' => ['id','number','status'],
        'bookings' => ['id','roomNumber','guestName','checkIn','checkOut','status','totalAmount','amountPaid','paymentMethod','bankAccountId','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId','vatRate','vatAmount'],
        'transactions' => ['id','type','category','categoryId','categorySystemKey','subcategoryId','subcategorySystemKey','amount','date','serviceDate','bookingId','bankAccountId','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId','operationId','baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','taxNote','transactionKind','recordOrigin','shiftExempt','reportingPeriod','periodImpactStatus','requiresTaxAmendment','historicalReviewStatus','version'],
        'transaction_allocations' => ['id','operation_id','transaction_id','booking_id','allocation_type','amount','transaction_booking_link_added','transaction_room_link_added','transaction_source_link_added','status','created_by','created_at'],
        'notifications' => ['id','message','timestamp','read','type'],
        'tax_rules' => ['id','name','source_pattern','transaction_kind','taxable','rate','is_active'],
        'shift_sessions' => ['id','staff_id','status','opening_cash','expected_cash','actual_cash'],
        'request_operation_receipts' => ['operation_id','staff_id','device_id','action','http_method','payload_hash','status','http_status','response_body','error_message'],
        'runtime_request_events' => ['request_id','action','stage','failed_stage','http_status','outcome','source_file','source_line','error_severity','created_at'],
        'salary_slips' => ['id','staff_id','status','cancelled_at','reversal_transaction_id','correction_of_slip_id','corrected_by_slip_id'],
        'schema_migrations' => ['version','description','applied_at'],
    ];
}

function tamasyaRuntimeSchemaAudit(PDO $pdo): array {
    $contract = tamasyaRuntimeCriticalSchemaContract();
    $issues = [];
    $tables = [];
    foreach ($contract as $table => $columns) {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
        $stmt->execute([$table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $available = array_fill_keys(array_map(static fn($row) => (string)$row['COLUMN_NAME'], $rows), true);
        $missing = array_values(array_filter($columns, static fn($column) => !isset($available[$column])));
        if (!$rows) $issues[] = ['severity'=>'critical','table'=>$table,'issue'=>'missing_table'];
        foreach ($missing as $column) $issues[] = ['severity'=>'critical','table'=>$table,'column'=>$column,'issue'=>'missing_column'];
        $tables[$table] = ['exists'=>(bool)$rows,'columnCount'=>count($rows),'missingColumns'=>$missing,'columns'=>$rows];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM request_operation_receipts LIKE 'response_body'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = strtolower((string)($column['Type'] ?? ''));
        if ($column && !str_contains($type, 'longtext')) {
            $issues[] = ['severity'=>'critical','table'=>'request_operation_receipts','column'=>'response_body','issue'=>'must_be_longtext','actualType'=>$type];
        }
    } catch (Throwable $error) {
        $issues[] = ['severity'=>'critical','table'=>'request_operation_receipts','issue'=>'inspection_failed'];
    }
    return [
        'success' => count(array_filter($issues, static fn($issue) => ($issue['severity'] ?? '') === 'critical')) === 0,
        'database' => (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''),
        'issues' => $issues,
        'tables' => $tables,
    ];
}

function tamasyaRuntimeDiagnostics(PDO $pdo, int $limit = 100, bool $includeSchema = false): array {
    $limit = max(1, min(500, $limit));
    tamasyaRuntimeEnsureSchema($pdo);
    $recent = $pdo->query("SELECT request_id,action,http_method,stage,failed_stage,http_status,outcome,staff_id,staff_role,node_id,duration_ms,error_reference,error_class,source_file,source_line,error_severity,sql_state,safe_message,created_at
        FROM runtime_request_events ORDER BY id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $summary = $pdo->query("SELECT action,http_status,COUNT(*) AS total,MAX(created_at) AS last_seen
        FROM runtime_request_events WHERE created_at >= (NOW() - INTERVAL 24 HOUR)
        GROUP BY action,http_status ORDER BY total DESC,last_seen DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $staleReceipts = [];
    try {
        $staleReceipts = $pdo->query("SELECT operation_id,staff_id,action,status,http_status,error_message,created_at,updated_at
            FROM request_operation_receipts
            WHERE status IN ('processing','uncertain') AND updated_at < (NOW() - INTERVAL 10 MINUTE)
            ORDER BY updated_at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($staleReceipts as &$row) {
            $row['operation_id'] = substr(hash('sha256', (string)($row['operation_id'] ?? '')), 0, 24);
        }
        unset($row);
    } catch (Throwable $ignored) {}
    $migrations = [];
    try {
        $migrations = $pdo->query("SELECT version,description,applied_at FROM schema_migrations ORDER BY applied_at DESC,version DESC LIMIT 50")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ignored) {}

    $schema = null;
    if ($includeSchema) {
        $schema = tamasyaRuntimeSchemaAudit($pdo);
        if (function_exists('validateApplicationSchema')) {
            try {
                $applicationValidation = validateApplicationSchema($pdo);
                $schema['applicationContract'] = [
                    'ready' => (bool)($applicationValidation['ready'] ?? false),
                    'issues' => array_values($applicationValidation['issues'] ?? []),
                ];
                if (!$schema['applicationContract']['ready']) $schema['success'] = false;
            } catch (Throwable $error) {
                $schema['applicationContract'] = [
                    'ready' => false,
                    'issues' => ['inspection_failed'],
                ];
                $schema['success'] = false;
                tamasyaRuntimeLog('application_schema_audit_error', [], $error);
            }
        }
    }

    return [
        'requestId' => tamasyaRuntimeRequestId(),
        'traceMode' => tamasyaRuntimeTraceMode(),
        'recent' => $recent,
        'summary24h' => $summary,
        'staleReceipts' => $staleReceipts,
        'recentMigrations' => $migrations,
        'schema' => $schema,
    ];
}
