<?php
/**
 * TAMASYA V137 - Flexible Primary / Standby synchronization support.
 *
 * Design rules:
 * - The cluster records one active Primary Writer; local or hosting may hold it.
 * - The Standby records/forwards replay-safe operations using the signed outbox.
 * - node_sync_agent.php reconciles the Standby with the current Primary using HMAC,
 *   leadership epoch, fencing token, revision checks, and idempotent receipts.
 * - Secrets, sessions, one-time Telegram codes, and node metadata are never mirrored.
 * - Telegram staff bindings are operational identity data and are mirrored so
 *   the hosting gateway resolves the same staff_id on either active primary.
 */

function tamasyaNodeRole(): string {
    $effective = $GLOBALS['tamasya_node_effective_role'] ?? null;
    if (in_array($effective, ['online_primary','local_backup'], true)) return $effective;
    $role = strtolower(trim((string)(getenv('TAMASYA_NODE_ROLE') ?: 'online_primary')));
    $mode = strtolower(trim((string)(getenv('TAMASYA_NODE_MODE') ?: '')));
    if (($role === 'flexible' || $mode === 'flexible') && function_exists('tamasyaNodeInitialPrimary')) {
        return tamasyaNodeInitialPrimary() ? 'online_primary' : 'local_backup';
    }
    return in_array($role, ['online_primary', 'local_backup'], true) ? $role : 'online_primary';
}

function tamasyaNodeId(): string {
    $nodeId = trim((string)(getenv('TAMASYA_NODE_ID') ?: 'online-primary'));
    $nodeId = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $nodeId) ?: 'unnamed-node';
    return substr($nodeId, 0, 100);
}

function tamasyaNodePropertyId(): string {
    $propertyId=strtolower(trim((string)(getenv('TAMASYA_PROPERTY_ID') ?: getenv('APP_PROPERTY_ID') ?: '')));
    return preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$propertyId)?$propertyId:'';
}

function tamasyaNodeSyncEnabled(): bool {
    return filter_var(getenv('NODE_SYNC_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
}

function tamasyaNodePreferOnline(): bool {
    return filter_var(getenv('NODE_SYNC_FORWARD_WHEN_ONLINE') ?: '1', FILTER_VALIDATE_BOOLEAN);
}

function tamasyaExternalSideEffectsAllowed(): bool {
    // Telegram, SMTP, AI eksternal, cron, dan smart-lock hanya boleh berjalan
    // pada pemegang lease yang masih valid. On a cluster, re-read the lightweight
    // leadership row from DB before an external effect so a long-running request
    // cannot keep sending with a stale in-memory Primary role after switchover.
    if (function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()) {
        $state=$GLOBALS['tamasya_cluster_state']??[];
        $pdo=$GLOBALS['tamasya_runtime_pdo']??null;
        // An in-memory lease snapshot cannot authorize an external side effect:
        // the node may have lost leadership during this request. Without a DB
        // handle/fresh lease read, fail closed rather than trusting stale state.
        if(!($pdo instanceof PDO)) return false;
        try{
            $fresh=$pdo->query("SELECT current_primary_node_id,leadership_epoch,fencing_token,transfer_state,lease_owner_node_id,lease_id,lease_renewed_at,lease_expires_at FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if(!is_array($fresh))return false;
            $state=array_merge($state,$fresh);$GLOBALS['tamasya_cluster_state']=$state;
        }catch(Throwable $e){return false;}
        if((string)($state['current_primary_node_id']??'')!==tamasyaNodeId())return false;
        return function_exists('tamasyaClusterLeaseIsValid')
            && tamasyaClusterLeaseIsValid($state)
            && in_array((string)($state['transfer_state']??'active'),['active','emergency'],true);
    }
    return tamasyaNodeRole()==='online_primary';
}


function tamasyaNodePrimaryUrl(): string {
    $clusterUrl = trim((string)($GLOBALS['tamasya_cluster_primary_url'] ?? ''));
    if ($clusterUrl !== '') return rtrim($clusterUrl, '/');
    return rtrim(trim((string)(getenv('NODE_SYNC_PRIMARY_URL') ?: '')), '/');
}

function tamasyaNodeSelfUrl(): string {
    return rtrim(trim((string)(getenv('NODE_SYNC_SELF_URL') ?: '')), '/');
}

function tamasyaNodeSyncSecret(): string {
    return trim((string)(getenv('NODE_SYNC_SHARED_SECRET') ?: ''));
}

function tamasyaNodeAllowedIds(): array {
    $raw = trim((string)(getenv('TAMASYA_ALLOWED_NODE_IDS') ?: ''));
    if ($raw === '') return [];
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
}

function tamasyaNodeReplayActive(): bool {
    return !empty($GLOBALS['tamasya_node_replay_active']);
}

function tamasyaNodeHeader(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

/** Safe scalar identity for an outbound HTTP header. */
function tamasyaNodeSafeHeaderIdentity(string $value, string $fallback): string {
    $value=trim($value);
    if ($value==='' || strlen($value)>190 || preg_match('/[\x00-\x20\x7f]/',$value)) {
        return $fallback!==''?$fallback:'sha256:'.hash('sha256',$value);
    }
    if (!preg_match('/^[A-Za-z0-9._:@-]+$/',$value)) return 'sha256:'.hash('sha256',$value);
    return $value;
}

function tamasyaNodePayloadHash(string $rawBody): string {
    return hash('sha256', $rawBody);
}

function tamasyaNodeCanonicalQuery(array $query): string {
    unset($query['action']);
    $normalize=function($value) use (&$normalize) {
        if (!is_array($value)) return $value;
        ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=$normalize($item);
        return $value;
    };
    $query=$normalize($query);
    return http_build_query($query,'','&',PHP_QUERY_RFC3986);
}

function tamasyaNodeQueryHash(array $query): string {
    return hash('sha256',tamasyaNodeCanonicalQuery($query));
}

function tamasyaNodeEncodeCursor(array $values): string {
    if (count($values) === 1) return (string)$values[0];
    $json = json_encode(array_values(array_map('strval',$values)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return '';
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function tamasyaNodeDecodeCursor(string $cursor, int $columnCount): array {
    if ($cursor === '') return [];
    if ($columnCount < 1 || $columnCount > 8 || strlen($cursor) > 2048 || preg_match('/[\x00-\x1f\x7f]/',$cursor))
        throw new InvalidArgumentException('Cursor snapshot tidak valid atau melampaui batas.');
    if ($columnCount === 1) return [$cursor];
    if (!preg_match('/^[A-Za-z0-9_-]+$/D',$cursor))
        throw new InvalidArgumentException('Encoding cursor komposit tidak valid.');
    $encoded=$cursor;
    $padding = strlen($encoded) % 4;
    if ($padding > 0) $encoded .= str_repeat('=', 4 - $padding);
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    $values = $decoded === false ? null : json_decode($decoded, true);
    if (!is_array($values) || array_keys($values)!==range(0,$columnCount-1))
        throw new InvalidArgumentException('Cursor snapshot komposit tidak valid.');
    foreach($values as $value){
        if(!is_string($value) || strlen($value)>512)
            throw new InvalidArgumentException('Nilai cursor snapshot komposit tidak valid.');
    }
    if (!hash_equals($cursor,tamasyaNodeEncodeCursor($values)))
        throw new InvalidArgumentException('Cursor snapshot komposit tidak canonical.');
    return $values;
}

function tamasyaNodeCompositeCursorWhere(array $pkColumns, array $cursorValues, array &$params): string {
    if (!$cursorValues) return '';
    if (count($pkColumns) !== count($cursorValues)) throw new InvalidArgumentException('Jumlah nilai cursor tidak sesuai primary key.');
    $orParts=[];
    foreach($pkColumns as $index=>$column){
        $andParts=[];
        for($prefix=0;$prefix<$index;$prefix++){
            $andParts[]='`'.str_replace('`','',$pkColumns[$prefix]).'` = ?';
            $params[]=$cursorValues[$prefix];
        }
        $andParts[]='`'.str_replace('`','',$column).'` > ?';
        $params[]=$cursorValues[$index];
        $orParts[]='('.implode(' AND ',$andParts).')';
    }
    return '('.implode(' OR ',$orParts).')';
}

/**
 * Context identity signed together with method/query/body. This prevents a valid
 * node HMAC from being replayed after changing device_id, session_id,
 * operation_id, epoch, fencing token, or base revision headers in transit.
 */
function tamasyaNodeContextHash(array $context): string {
    $keys=['mode','nodeId','propertyId','deviceId','operationId','sessionId','clusterId','clusterEpoch','fencingToken','baseRevision'];
    $canonical=[];
    foreach($keys as $key) $canonical[$key]=trim((string)($context[$key]??''));
    $json=json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) throw new RuntimeException('Konteks signature node tidak dapat dinormalisasi.');
    return hash('sha256',$json);
}

function tamasyaNodeContextFromHeaders(): array {
    return [
        'mode'=>strtolower(tamasyaNodeHeader('X-Tamasya-Node-Mode')),
        'nodeId'=>tamasyaNodeHeader('X-Tamasya-Node-ID'),
        'propertyId'=>tamasyaNodeHeader('X-Tamasya-Property-ID'),
        'deviceId'=>tamasyaNodeHeader('X-Device-ID'),
        'operationId'=>tamasyaNodeHeader('X-Tamasya-Operation-ID'),
        'sessionId'=>tamasyaNodeHeader('X-Tamasya-Origin-Session-ID'),
        'clusterId'=>tamasyaNodeHeader('X-Tamasya-Cluster-ID'),
        'clusterEpoch'=>tamasyaNodeHeader('X-Tamasya-Cluster-Epoch'),
        'fencingToken'=>tamasyaNodeHeader('X-Tamasya-Fencing-Token'),
        'baseRevision'=>tamasyaNodeHeader('X-Tamasya-Base-Revision'),
    ];
}

function tamasyaNodeSignatureMessage(string $method, string $action, string $eventId, string $actorId, string $timestamp, string $queryHash, string $bodyHash, string $contextHash = ''): string {
    return strtoupper($method) . "\n" . $action . "\n" . $eventId . "\n" . $actorId . "\n" . $timestamp . "\n" . $queryHash . "\n" . $bodyHash . "\n" . $contextHash;
}

function tamasyaNodeSignature(string $method, string $action, string $eventId, string $actorId, string $timestamp, string $queryHash, string $bodyHash, string $contextHash = ''): string {
    $secret = tamasyaNodeSyncSecret();
    if (strlen($secret) < 32) return '';
    return hash_hmac('sha256', tamasyaNodeSignatureMessage($method, $action, $eventId, $actorId, $timestamp, $queryHash, $bodyHash, $contextHash), $secret);
}

/** Classify a replay response using both HTTP and the JSON contract. */
function tamasyaNodeClassifyResponse(int $httpStatus, string $body): array {
    // Status zero is a transport failure (timeout, DNS, TLS, or socket error),
    // never an implicit HTTP 200. A stale/forged JSON body must not acknowledge
    // a Hybrid replay when the transport did not confirm an HTTP response.
    if ($httpStatus < 100 || $httpStatus > 599) {
        return [
            'completed'=>false, 'jsonValid'=>false, 'httpStatus'=>503,
            'message'=>'Tidak ada status HTTP valid dari Primary; operasi menunggu retry aman.',
            'decoded'=>null,
        ];
    }
    $trimmed = trim($body);
    $decoded = $trimmed !== '' ? json_decode($trimmed, true) : null;
    $jsonValid = $trimmed !== '' && json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    $jsonFailure = $jsonValid && array_key_exists('success', $decoded) && $decoded['success'] === false;
    $httpSuccess = $httpStatus >= 200 && $httpStatus < 400;
    $completed = $httpSuccess && $jsonValid && !$jsonFailure;
    $message = '';
    if ($jsonValid) {
        $candidate = $decoded['error'] ?? $decoded['message'] ?? '';
        // Successful endpoints may return a structured object in `message`.
        // Never cast arrays/objects to string because the global warning handler
        // promotes that warning to ErrorException during mutation finalization.
        if (is_scalar($candidate) || $candidate === null) {
            $message = trim((string)$candidate);
        }
    }
    if ($message === '' && !$jsonValid) $message = 'Respons API bukan satu dokumen JSON yang valid.';
    if ($message === '' && !$completed) $message = 'HTTP ' . $httpStatus;
    return [
        'completed'=>$completed,
        'jsonValid'=>$jsonValid,
        'httpStatus'=>$completed ? $httpStatus : ($httpSuccess ? ($jsonFailure ? 409 : 500) : $httpStatus),
        'message'=>$message,
        'decoded'=>$jsonValid ? $decoded : null,
    ];
}

function tamasyaPrimaryMutationLockName(): string {
    return 'tamasya-primary-mutation-' . substr(hash('sha256', tamasyaNodeId()), 0, 24);
}

function tamasyaAcquirePrimaryMutationLock(PDO $pdo, int $timeoutSeconds = 10): bool {
    if (tamasyaNodeRole() !== 'online_primary') return true;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?,?)');
    $stmt->execute([tamasyaPrimaryMutationLockName(), max(0, min(30, $timeoutSeconds))]);
    return (int)$stmt->fetchColumn() === 1;
}

function tamasyaReleasePrimaryMutationLock(PDO $pdo): void {
    // Always attempt release. During a planned switchover the effective role may
    // change to standby before the request shutdown handler releases the lock.
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([tamasyaPrimaryMutationLockName()]);
    } catch (Throwable $ignored) {}
}

/** Fresh V137: node-sync tables and columns come exclusively from database_setup.sql. */
function tamasyaEnsureNodeSyncTables(?PDO $pdo): void {
    if (!$pdo) return;
    tamasyaAssertTablesExist($pdo, [
        'node_sync_settings','node_sync_outbox','node_sync_conflicts','node_sync_runs',
        'node_sync_nonces','node_sync_receipts'
    ], 'Node sync');
    $requiredColumns = [
        ['node_sync_settings','local_mutation_counter'],['node_sync_settings','last_mirrored_local_counter'],
        ['node_sync_settings','mutation_guard_ready'],['node_sync_settings','mirror_in_progress'],
        ['node_sync_outbox','mutation_counter_start'],['node_sync_outbox','mutation_counter'],['node_sync_outbox','session_id'],
        ['node_sync_receipts','device_id'],['node_sync_receipts','session_id'],['node_sync_receipts','operation_id'],
        ['node_sync_receipts','http_method'],['node_sync_receipts','query_hash']
    ];
    $missing=[];
    foreach ($requiredColumns as [$table,$column]) {
        if (tamasyaSchemaColumnMeta($pdo,$table,$column) === null) $missing[]=$table.'.'.$column;
    }
    if ($missing) throw new RuntimeException('Schema Node Sync tidak sesuai baseline fresh V137: '.implode(', ',$missing));
    $settings=$pdo->query("SELECT id FROM node_sync_settings WHERE id='system_default' LIMIT 1")->fetchColumn();
    if (!$settings) throw new RuntimeException('Node Sync baseline tidak memiliki row system_default. Import ulang database_setup.sql pada database fresh.');
}

function tamasyaNodeSyncStatus(PDO $pdo): array {
    tamasyaEnsureNodeSyncTables($pdo);
    $settings = $pdo->query("SELECT * FROM node_sync_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = ['pending'=>0,'uncertain'=>0,'sending'=>0,'completed'=>0,'conflict'=>0,'failed'=>0];
    foreach ($pdo->query("SELECT status,COUNT(*) total FROM node_sync_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
    $openConflicts = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn();
    $primaryRevision = 0;
    try { $primaryRevision = (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn(); } catch (Throwable $ignored) {}
    return [
        'nodeId'=>tamasyaNodeId(),
        'nodeRole'=>tamasyaNodeRole(),
        'enabled'=>tamasyaNodeSyncEnabled(),
        'primaryUrlConfigured'=>tamasyaNodePrimaryUrl() !== '',
        'selfUrlConfigured'=>tamasyaNodeSelfUrl() !== '',
        'sharedSecretConfigured'=>strlen(tamasyaNodeSyncSecret()) >= 32,
        'allowedNodeIds'=>tamasyaNodeAllowedIds(),
        'localServerRevision'=>$primaryRevision,
        'lastPrimaryRevision'=>(int)($settings['last_primary_revision'] ?? 0),
        'localMutationCounter'=>(int)($settings['local_mutation_counter'] ?? 0),
        'lastMirroredLocalCounter'=>(int)($settings['last_mirrored_local_counter'] ?? 0),
        'mutationGuardReady'=>(bool)($settings['mutation_guard_ready'] ?? false),
        'mirrorInProgress'=>(bool)($settings['mirror_in_progress'] ?? false),
        'mirrorStartedAt'=>$settings['mirror_started_at'] ?? null,
        'localSecurityInitialized'=>(bool)($settings['local_security_initialized'] ?? false),
        'preferOnline'=>tamasyaNodePreferOnline(),
        'lastPushAt'=>$settings['last_push_at'] ?? null,
        'lastPullAt'=>$settings['last_pull_at'] ?? null,
        'lastSuccessAt'=>$settings['last_success_at'] ?? null,
        'lastError'=>$settings['last_error'] ?? null,
        'outbox'=>$counts,
        'openConflicts'=>$openConflicts,
        'cluster'=>function_exists('tamasyaClusterPublicState') ? tamasyaClusterPublicState($pdo) : null
    ];
}

function tamasyaNodeSyncAllowedAction(string $action, array $input, string $method): bool {
    if (!in_array(strtoupper($method), ['POST','PUT','PATCH','DELETE'], true)) return false;
    $allowed = [
        'bookings','multi-room-bookings','rooms','room-transfers','booking-negotiated-price','booking-payments','bookings-status','guest-security-deposits','transaction-booking-action',
        'ota-disbursements','booking-audit-correction','transactions','sync','attendance',
        'salary-slips','employee-self-service','staff-savings','inventory','inventory-maintenance','operations-center',
        'pos-category-save','pos-product-save','pos-stock-adjust','pos-sale-create','pos-sale-void',
        'chat-messages','notifications-read','growth-suite','enterprise-suite','internal-memos'
    ];
    if (!in_array($action, $allowed, true)) return false;
    if ($action === 'internal-memos') {
        // Memo writes use the same signed forwarding, receipt and fencing path
        // as other business data; only commands implemented by the route qualify.
        $method = strtoupper($method);
        if (!in_array($method, ['POST','PUT'], true)) return false;
        $command = strtolower(trim((string)($input['command'] ?? ($method === 'POST' ? 'create' : 'update'))));
        return in_array($command, ['create','update','archive','restore'], true);
    }
    if ($action !== 'operations-center') return true;
    $command = strtolower(trim((string)($input['command'] ?? '')));
    $allowedCommands = [
        'shift-open','shift-close','shift-cash-revise','approval-create','approval-decide','reconciliation-import',
        'reconciliation-save','reconciliation-status','vacancy-report-create','vacancy-report-detail-update','vacancy-report-review',
        'housekeeping-save','housekeeping-status','maintenance-ticket-save','maintenance-ticket-status',
        'guest-profile-save','guest-sync','guest-service-open','guest-service-progress','guest-service-close','lost-found-secure','lost-found-notify','lost-found-close','maintenance-cancellation-review','operational-incident-open','operational-incident-progress','operational-incident-resolve','room-hold-open','room-hold-release','alert-ack','room-access-save','key-issue','key-return',
        'smart-lock-job-retry','smart-lock-job-manual-complete','late-checkout-record','night-audit-start',
        'night-audit-check-room','night-audit-resolve','night-audit-finalize','document-save'
    ];
    return in_array($command, $allowedCommands, true);
}

function tamasyaLocalMirrorGuard(PDO $pdo): ?string {
    if (tamasyaNodeRole() !== 'local_backup') return null;
    tamasyaEnsureNodeSyncTables($pdo);
    $row = $pdo->query("SELECT mirror_in_progress,mirror_started_at FROM node_sync_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($row['mirror_in_progress'])) return null;
    $started = strtotime((string)($row['mirror_started_at'] ?? '')) ?: 0;
    if ($started > 0 && $started < time() - 900) {
        $pdo->exec("UPDATE node_sync_settings SET mirror_in_progress=0,mirror_started_at=NULL,last_error='Mirror sebelumnya terhenti dan lock kedaluwarsa dibersihkan otomatis.' WHERE id='system_default'");
        return null;
    }
    return 'Node standby sedang memperbarui mirror dari primary aktif. Tunggu beberapa saat lalu ulangi tindakan.';
}


function tamasyaLocalUncertainMutationGuard(PDO $pdo, string $action, array $input, string $method): ?string {
    if (tamasyaNodeRole() !== 'local_backup') return null;
    if (!in_array(strtoupper($method), ['POST','PUT','PATCH','DELETE'], true)) return null;
    if (tamasyaLocalOnlyMutationAllowed($action, $input, $method)) return null;
    tamasyaEnsureNodeSyncTables($pdo);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_outbox WHERE status='uncertain'")->fetchColumn();
    if ($count <= 0) return null;
    return 'Ada transaksi dengan hasil primary aktif yang belum pasti. Mutasi baru diblokir sampai agent memastikan event tersebut agar urutan transaksi dan kas tidak bercabang.';
}

function tamasyaLocalOnlyMutationAllowed(string $action, array $input, string $method): bool {
    if (tamasyaNodeRole() !== 'local_backup') return false;
    if (!in_array(strtoupper($method), ['POST','PUT','PATCH','DELETE'], true)) return false;
    // Sesi dan presence sengaja hidup hanya pada database lokal. Data ini tidak
    // dimirror dan tidak boleh masuk outbox sebagai operasi bisnis hotel.
    if (in_array($action, ['login','verify-2fa','refresh-session','telegram-webhook','communication-webhook','node-sync-conflict-resolve','node-cluster-control','node-cluster-telegram-webhook','node-cluster-channel-webhook','node-cluster-emergency-promote','node-cluster-reconcile'], true)) return true;
    if ($action === 'operations-center') {
        $command = strtolower(trim((string)($input['command'] ?? '')));
        return in_array($command, ['device-heartbeat','logout-current'], true);
    }
    return false;
}

function tamasyaLocalNodeBlockedAction(string $action, array $input, string $method): ?string {
    if (tamasyaNodeRole() !== 'local_backup') return null;
    if (!in_array(strtoupper($method), ['POST','PUT','PATCH','DELETE'], true)) return null;
    if (function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()) {
        $clusterTransfer=(string)(($GLOBALS['tamasya_cluster_state']['transfer_state']??'active'));
        if (in_array($clusterTransfer,['split_brain','draining'],true) && !in_array($action,['node-cluster-emergency-promote','node-cluster-recover-expired-primary','node-cluster-reconcile','node-cluster-control'],true)) {
            return 'Cluster berada pada status '.$clusterTransfer.'. Mutasi diblokir sampai leadership direkonsiliasi.';
        }
    }
    $blocked = [
        'staff','config','telegram-webhook','data-cleanup-mode','data-cleanup-execute','test-data-purge','db-reset',
        'telegram-set-webhook','telegram-delete-webhook','telegram-clear','email-report',
        'bank-accounts','categories','categories-update','categories/update','categories-semantic-bind','categories/semantic-bind',
        'subcategories','subcategories-update','subcategories/update','categories-delete','categories/delete','subcategories-delete','subcategories/delete',
        'catalog-item-save','catalog-item-archive','catalog-rate-save'
    ];
    if (in_array($action, $blocked, true)) {
        return 'Operasi administrasi ini hanya boleh dilakukan pada primary aktif agar konfigurasi dan identitas tidak bercabang.';
    }
    if ($action === 'operations-center') {
        $command = strtolower(trim((string)($input['command'] ?? '')));
        $blockedCommands = ['approval-policy-save','session-revoke','device-disable','tax-rule-save','tax-rule-toggle','operational-settings-save','night-audit-mode-save','backup-record','backup-restore-tested','telegram-binding-code','telegram-unbind'];
        if (in_array($command, $blockedCommands, true)) {
            return 'Perubahan kebijakan, Telegram, atau konfigurasi harus dilakukan pada server online utama.';
        }
    }
    if (!tamasyaNodeSyncAllowedAction($action, $input, $method) && !tamasyaLocalOnlyMutationAllowed($action, $input, $method)) {
        return 'Operasi ini belum memiliki kontrak replay yang aman untuk server lokal. Jalankan melalui server online utama.';
    }
    return null;
}

function tamasyaReplicationKeyIsSensitive(string $key): bool {
    $normalized = strtolower(preg_replace('/[^a-z0-9]+/i','',trim($key)) ?? '');
    if ($normalized === '') return false;
    $exact = [
        'password','passwordhash','authorization','authheader','bearertoken',
        'token','accesstoken','refreshtoken','sessiontoken','sessionid',
        'secret','credentials','credential','apikey','dbpassword',
        'twofactorcode','twofactorcodehash','twofactorchallengehash','twofactordeviceid'
    ];
    if (in_array($normalized,$exact,true)) return true;
    // Suffix matching catches telegramBotToken/smtpPassword without deleting
    // business fields such as shiftSessionId or credentialStatus.
    if (str_ends_with($normalized,'password') || str_ends_with($normalized,'secret') || str_ends_with($normalized,'apikey')) return true;
    if (str_ends_with($normalized,'token') && !in_array($normalized,['fingerprinttoken'],true)) return true;
    if (str_starts_with($normalized,'twofactor') || str_starts_with($normalized,'2fa')) return true;
    return false;
}

function tamasyaReplicationPayload($value, int $depth = 0) {
    if ($depth > 8) return null;
    if (!is_array($value)) return $value;
    $result = [];
    foreach ($value as $key => $item) {
        if (tamasyaReplicationKeyIsSensitive((string)$key)) continue;
        $result[$key] = is_array($item) ? tamasyaReplicationPayload($item, $depth + 1) : $item;
    }
    return $result;
}

function tamasyaFindOperationId(array $input): ?string {
    foreach (['operationId','operation_id','requestId','request_id'] as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') return substr($value, 0, 100);
    }
    foreach ($input as $item) {
        if (is_array($item)) {
            $found = tamasyaFindOperationId($item);
            if ($found) return $found;
        }
    }
    return null;
}

function tamasyaEnqueueNodeCommand(PDO $pdo, string $action, string $method, array $query, array $input, ?array $actor): ?string {
    if (!tamasyaNodeSyncEnabled() || tamasyaNodeRole() !== 'local_backup' || tamasyaNodeReplayActive()) return null;
    if (!tamasyaNodeSyncAllowedAction($action, $input, $method)) return null;
    tamasyaEnsureNodeSyncTables($pdo);
    $safePayload = tamasyaReplicationPayload($input);
    $generationEventId = trim((string)($GLOBALS['tamasya_node_operation_event_id'] ?? ''));
    if ($generationEventId !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $generationEventId)) {
        $safePayload['_nodeGenerationEventId'] = $generationEventId;
    }
    $safeQuery = tamasyaReplicationPayload($query);
    unset($safeQuery['action']);
    $payloadJson = json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $queryJson = json_encode($safeQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false || $queryJson === false) {
        throw new RuntimeException('Payload node-sync tidak dapat dienkode sebagai JSON; outbox tidak boleh dianggap berhasil.');
    }
    $eventId = trim((string)($GLOBALS['tamasya_node_operation_event_id'] ?? ''));
    if ($eventId === '' || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $eventId)) {
        $eventId = 'nodesync_' . bin2hex(random_bytes(16));
        $GLOBALS['tamasya_node_operation_event_id'] = $eventId;
    }
    $operationId = trim((string)($GLOBALS['tamasya_request_operation_id']??''));
    if ($operationId==='') $operationId=tamasyaFindOperationId($safePayload);
    $sessionId=trim((string)($actor['session_id']??''));
    $settingsRow = $pdo->query("SELECT last_primary_revision,local_mutation_counter FROM node_sync_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $baseRevision = (int)($settingsRow['last_primary_revision'] ?? 0);
    $mutationCounter = (int)($settingsRow['local_mutation_counter'] ?? 0);
    $mutationCounterStart = (int)($GLOBALS['tamasya_local_mutation_counter_start'] ?? $mutationCounter);
    $stmt = $pdo->prepare("INSERT INTO node_sync_outbox
        (event_id,origin_node_id,actor_staff_id,device_id,session_id,action,http_method,query_json,payload_json,payload_hash,operation_id,base_primary_revision,mutation_counter_start,mutation_counter,status,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $stmt->execute([
        $eventId,tamasyaNodeId(),$actor['id'] ?? null,
        function_exists('currentDeviceId') ? currentDeviceId() : null,
        $sessionId!==''?$sessionId:null,
        $action,strtoupper($method),$queryJson,$payloadJson,hash('sha256',$payloadJson),$operationId,$baseRevision,$mutationCounterStart,$mutationCounter
    ]);
    return $eventId;
}

function tamasyaNodeApiBaseUrl(string $base): string {
    $base=rtrim(trim($base),'/');
    if (preg_match('#/api\.php$#i',$base)) return $base;
    return $base.'/api.php';
}

function tamasyaForwardCommandToPrimary(string $action, string $method, array $query, string $rawBody, string $actorId, string $eventId, string $originSessionId = ''): array {
    if (tamasyaNodeRole()!=='local_backup' || !tamasyaNodeSyncEnabled() || !tamasyaNodePreferOnline()) {
        return ['attempted'=>false,'ok'=>false,'definitelyOffline'=>true,'ambiguous'=>false,'status'=>0,'body'=>'','eventId'=>$eventId];
    }
    if (!function_exists('curl_init')) {
        return ['attempted'=>true,'ok'=>false,'definitelyOffline'=>false,'ambiguous'=>false,'status'=>0,'body'=>'','error'=>'Extension cURL tidak tersedia.','eventId'=>$eventId];
    }
    $query['action']=$action;
    $url=tamasyaNodeApiBaseUrl(tamasyaNodePrimaryUrl()).'?'.http_build_query($query);
    $timestamp=(string)time();
    $bodyHash=tamasyaNodePayloadHash($rawBody);
    $queryHash=tamasyaNodeQueryHash($query);
    $deviceId=tamasyaNodeSafeHeaderIdentity(function_exists('currentDeviceId')?currentDeviceId():'','node-forward-'.tamasyaNodeId());
    $operationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
    $originSessionId=trim($originSessionId);
    if ($originSessionId!=='' && !preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$originSessionId)) {
        $originSessionId='sha256:'.hash('sha256',$originSessionId);
    }
    $clusterId=function_exists('tamasyaClusterId')?tamasyaClusterId():'';
    $clusterEpoch=(string)(int)(($GLOBALS['tamasya_cluster_state']['leadership_epoch']??0));
    $fencingToken=(string)(($GLOBALS['tamasya_cluster_state']['fencing_token']??''));
    $baseRevision=(string)(int)($GLOBALS['tamasya_local_primary_revision'] ?? 0);
    $contextHash=tamasyaNodeContextHash([
        'mode'=>'replay','nodeId'=>tamasyaNodeId(),'propertyId'=>tamasyaNodePropertyId(),'deviceId'=>$deviceId,
        'operationId'=>$operationId,'sessionId'=>$originSessionId,'clusterId'=>$clusterId,
        'clusterEpoch'=>$clusterEpoch,'fencingToken'=>$fencingToken,'baseRevision'=>$baseRevision,
    ]);
    $signature=tamasyaNodeSignature($method,$action,$eventId,$actorId,$timestamp,$queryHash,$bodyHash,$contextHash);
    if ($signature==='') return ['attempted'=>true,'ok'=>false,'definitelyOffline'=>false,'ambiguous'=>false,'status'=>0,'body'=>'','error'=>'NODE_SYNC_SHARED_SECRET belum valid.','eventId'=>$eventId];
    $headers=[
        'Accept: application/json','Content-Type: application/json',
        'X-Tamasya-Node-Mode: replay','X-Tamasya-Node-ID: '.tamasyaNodeId(),
        'X-Tamasya-Property-ID: '.tamasyaNodePropertyId(),
        'X-Tamasya-Node-Timestamp: '.$timestamp,'X-Tamasya-Node-Event: '.$eventId,
        'X-Tamasya-Node-Actor: '.$actorId,'X-Tamasya-Node-Query-Hash: '.$queryHash,
        'X-Tamasya-Node-Context-Hash: '.$contextHash,'X-Tamasya-Node-Signature: '.$signature,
        'X-Tamasya-Base-Revision: '.$baseRevision,
        'X-Device-ID: '.$deviceId,
        'X-Tamasya-Cluster-ID: '.$clusterId,
        'X-Tamasya-Cluster-Epoch: '.$clusterEpoch,
        'X-Tamasya-Fencing-Token: '.$fencingToken,
        'X-App-Version: V137'
    ];
    if ($operationId!=='') $headers[]='X-Tamasya-Operation-ID: '.$operationId;
    if ($originSessionId!=='') $headers[]='X-Tamasya-Origin-Session-ID: '.$originSessionId;
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'TAMASYA-Standby-Forward/V137'
    ]);
    if (strtoupper($method)!=='GET') curl_setopt($ch,CURLOPT_POSTFIELDS,$rawBody);
    $body=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$connectTime=(float)curl_getinfo($ch,CURLINFO_CONNECT_TIME);
    unset($ch);
    $body=$body===false?'':(string)$body;
    $definitelyOffline=in_array($errno,[5,6,7],true) || ($errno===28 && $connectTime<=0.001);
    $ambiguous=$errno!==0 && !$definitelyOffline;
    $classification=tamasyaNodeClassifyResponse($status,$body);
    $effectiveStatus=$errno===0?(int)$classification['httpStatus']:$status;
    return [
        'attempted'=>true,'ok'=>$errno===0 && !empty($classification['completed']),
        'definitelyOffline'=>$definitelyOffline,'ambiguous'=>$ambiguous,'status'=>$effectiveStatus,
        'body'=>$body,'error'=>$error,'curlErrno'=>$errno,'eventId'=>$eventId,
        'businessMessage'=>$classification['message']??''
    ];
}

function tamasyaQueueAmbiguousForward(PDO $pdo, string $eventId, string $action, string $method, array $query, array $input, array $actor, string $message, string $rawBody): void {
    tamasyaEnsureNodeSyncTables($pdo);
    $safePayload=tamasyaReplicationPayload($input);$safeQuery=tamasyaReplicationPayload($query);unset($safeQuery['action']);
    // Pertahankan byte body persis seperti forward pertama. Receipt online
    // mengikat event_id ke SHA-256 body; normalisasi JSON akan membuat retry
    // ambigu terlihat sebagai payload berbeda.
    $payloadJson=trim($rawBody)!==''?$rawBody:'{}';
    $queryJson=json_encode($safeQuery,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}';
    $settings=$pdo->query("SELECT last_primary_revision,local_mutation_counter FROM node_sync_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC)?:[];
    $counter=(int)($settings['local_mutation_counter']??0);
    $operationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
    if ($operationId==='') $operationId=tamasyaFindOperationId($safePayload);
    $sessionId=trim((string)($actor['session_id']??''));
    $pdo->prepare("INSERT INTO node_sync_outbox (event_id,origin_node_id,actor_staff_id,device_id,session_id,action,http_method,query_json,payload_json,payload_hash,operation_id,base_primary_revision,mutation_counter_start,mutation_counter,status,attempts,last_error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'uncertain',1,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),session_id=VALUES(session_id),operation_id=VALUES(operation_id),status='uncertain',last_error=VALUES(last_error),updated_at=CURRENT_TIMESTAMP")
        ->execute([$eventId,tamasyaNodeId(),$actor['id']??null,function_exists('currentDeviceId')?currentDeviceId():null,$sessionId!==''?$sessionId:null,$action,strtoupper($method),$queryJson,$payloadJson,hash('sha256',$payloadJson),$operationId,(int)($settings['last_primary_revision']??0),$counter,$counter,substr($message,0,1000)]);
}

function tamasyaVerifyNodeRequest(PDO $pdo, string $action, string $rawBody, bool $consumeNonce = true): array {
    tamasyaEnsureNodeSyncTables($pdo);
    $nodeId = tamasyaNodeHeader('X-Tamasya-Node-ID');
    $timestamp = tamasyaNodeHeader('X-Tamasya-Node-Timestamp');
    $eventId = tamasyaNodeHeader('X-Tamasya-Node-Event');
    $actorId = tamasyaNodeHeader('X-Tamasya-Node-Actor');
    $signature = tamasyaNodeHeader('X-Tamasya-Node-Signature');
    $queryHashHeader = tamasyaNodeHeader('X-Tamasya-Node-Query-Hash');
    $contextHashHeader = tamasyaNodeHeader('X-Tamasya-Node-Context-Hash');
    $operationId = tamasyaNodeHeader('X-Tamasya-Operation-ID');
    $originSessionId = tamasyaNodeHeader('X-Tamasya-Origin-Session-ID');
    $originDeviceId = tamasyaNodeHeader('X-Device-ID');
    if ($operationId !== '') {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $operationId)) throw new RuntimeException('operation_id replay tidak valid.');
        $expectedEventId = 'nodesync_op_' . hash('sha256', $operationId);
        if (!hash_equals($expectedEventId, $eventId)) throw new RuntimeException('Event replay tidak terikat pada operation_id yang ditandatangani.');
    }
    if ($originSessionId !== '' && !preg_match('/^(?:[A-Za-z0-9._:-]{8,80}|sha256:[a-f0-9]{64})$/', $originSessionId)) {
        throw new RuntimeException('Session ID asal replay tidak valid.');
    }
    if ($originDeviceId === '' || strlen($originDeviceId) > 190 || !preg_match('/^(?:[A-Za-z0-9._:@-]+|sha256:[a-f0-9]{64})$/',$originDeviceId)) throw new RuntimeException('Device ID asal replay tidak valid.');
    $incomingPropertyId=tamasyaNodeHeader('X-Tamasya-Property-ID');
    $expectedPropertyId=tamasyaNodePropertyId();
    if($expectedPropertyId==='' || $incomingPropertyId==='' || !hash_equals($expectedPropertyId,strtolower($incomingPropertyId))){
        throw new RuntimeException('Property ID request sinkronisasi tidak cocok. Cross-property sync ditolak.');
    }
    $computedContextHash=tamasyaNodeContextHash(tamasyaNodeContextFromHeaders());
    if ($contextHashHeader==='' || !hash_equals($computedContextHash,$contextHashHeader)) {
        throw new RuntimeException('Konteks identitas/leadership request node tidak sah.');
    }
    $allowed = tamasyaNodeAllowedIds();
    if (function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()) {
        $incomingCluster = tamasyaNodeHeader('X-Tamasya-Cluster-ID');
        if ($incomingCluster === '' || !hash_equals(tamasyaClusterId(), $incomingCluster)) {
            throw new RuntimeException('Cluster ID request sinkronisasi tidak sah.');
        }
        $nodeMode=strtolower(trim(tamasyaNodeHeader('X-Tamasya-Node-Mode')));
        if ($nodeMode==='replay') {
            $state=$pdo->query("SELECT current_primary_node_id,leadership_epoch,fencing_token,transfer_state,lease_owner_node_id,lease_id,lease_expires_at FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
            if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) {
                throw new RuntimeException('Replay ditolak karena node penerima bukan primary aktif.');
            }
            if (!in_array((string)($state['transfer_state']??'active'),['active','emergency'],true)) {
                throw new RuntimeException('Replay ditolak karena primary sedang draining atau cluster belum direkonsiliasi.');
            }
            if (!function_exists('tamasyaClusterLeaseIsValid') || !tamasyaClusterLeaseIsValid($state)) {
                throw new RuntimeException('Replay ditolak karena leadership lease primary tidak valid atau sudah kedaluwarsa.');
            }
            $incomingEpoch=(int)tamasyaNodeHeader('X-Tamasya-Cluster-Epoch');
            $incomingFence=tamasyaNodeHeader('X-Tamasya-Fencing-Token');
            $expectedEpoch=(int)($state['leadership_epoch']??0);
            $expectedFence=(string)($state['fencing_token']??'');
            if ($incomingEpoch!==$expectedEpoch || $incomingFence==='' || $expectedFence==='' || !hash_equals($expectedFence,$incomingFence)) {
                throw new RuntimeException('Replay ditolak oleh fencing token/leadership epoch. Jalankan sinkronisasi leadership sebelum mencoba ulang.');
            }
        }
    }
    if (((function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()) || tamasyaNodeRole() === 'online_primary') && !$allowed) {
        throw new RuntimeException('TAMASYA_ALLOWED_NODE_IDS wajib berisi ID peer yang diizinkan.');
    }
    if ($nodeId === '' || ($allowed && !in_array($nodeId, $allowed, true))) {
        throw new RuntimeException('Node sinkronisasi tidak diizinkan.');
    }
    if ($eventId === '' || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $eventId)) {
        throw new RuntimeException('Nonce/event sinkronisasi tidak valid.');
    }
    $ts = ctype_digit($timestamp) ? (int)$timestamp : 0;
    if ($ts <= 0 || abs(time() - $ts) > 300) {
        throw new RuntimeException('Timestamp sinkronisasi kedaluwarsa.');
    }
    $computedQueryHash=tamasyaNodeQueryHash($_GET);
    if ($queryHashHeader==='' || !hash_equals($computedQueryHash,$queryHashHeader)) throw new RuntimeException('Hash query sinkronisasi tidak sah.');
    $expected = tamasyaNodeSignature($_SERVER['REQUEST_METHOD'] ?? 'GET', $action, $eventId, $actorId, $timestamp, $computedQueryHash, tamasyaNodePayloadHash($rawBody), $computedContextHash);
    if ($expected === '' || $signature === '' || !hash_equals($expected, $signature)) {
        throw new RuntimeException('Tanda tangan sinkronisasi tidak sah.');
    }
    if ($consumeNonce) {
        try {
            $pdo->prepare("INSERT INTO node_sync_nonces (nonce,node_id,used_at,expires_at) VALUES (?,?,CURRENT_TIMESTAMP,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE))")
                ->execute([$eventId,$nodeId]);
        } catch (Throwable $e) {
            throw new RuntimeException('Request sinkronisasi yang sama sudah pernah diterima.');
        }
    }
    return [
        'nodeId'=>$nodeId,'eventId'=>$eventId,'actorId'=>$actorId,'timestamp'=>$ts,
        'operationId'=>$operationId,'deviceId'=>$originDeviceId,'sessionId'=>$originSessionId,
        'contextHash'=>$computedContextHash,
        'httpMethod'=>strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),'queryHash'=>$computedQueryHash
    ];
}

function tamasyaNodeReplayConflict(PDO $pdo, string $action, string $method, array $input, array $query): ?string {
    if (!tamasyaNodeReplayActive()) return null;
    $method = strtoupper($method);
    if (!in_array($method,['PUT','PATCH','DELETE'],true)) return null;
    $map = [
        'bookings'=>['table'=>'bookings','pk'=>'id'],
        'rooms'=>['table'=>'rooms','pk'=>'id'],
        'transactions'=>['table'=>'transactions','pk'=>'id'],
        'inventory'=>['table'=>'inventory','pk'=>'id'],
        'inventory-maintenance'=>['table'=>'inventory_maintenance','pk'=>'id']
    ];
    if (!isset($map[$action])) return null;
    $baseRevision = (int)tamasyaNodeHeader('X-Tamasya-Base-Revision');
    $currentRevision = 0;
    try { $currentRevision = (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn(); } catch (Throwable $ignored) {}
    if ($baseRevision > 0 && $currentRevision <= $baseRevision) return null;

    $id = trim((string)($query['id'] ?? $input['id'] ?? ''));
    if ($id === '') return 'Replay perubahan lama tidak memiliki ID entitas untuk pemeriksaan konflik.';
    $table = $map[$action]['table']; $pk = $map[$action]['pk'];
    if (!tamasyaNodeTableExists($pdo,$table)) return 'Tabel tujuan replay belum tersedia.';
    $columns = tamasyaNodeTableColumns($pdo,$table,[]);
    $select = [];
    foreach (['version','updatedAt','updated_at'] as $candidate) if (in_array($candidate,$columns,true)) $select[]=$candidate;
    if (!$select) return $baseRevision > 0 && $currentRevision > $baseRevision ? 'Data online berubah sejak mirror terakhir dan entitas tidak memiliki version/timestamp untuk validasi aman.' : null;
    $stmt = $pdo->prepare("SELECT `".implode('`,`',$select)."` FROM `{$table}` WHERE `{$pk}`=? LIMIT 1");
    $stmt->execute([$id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server) return null;
    if ($method === 'DELETE') {
        $incomingVersion = (int)($input['version'] ?? $input['_baseVersion'] ?? 0);
        if ($incomingVersion > 0 && (int)($server['version'] ?? 0) <= $incomingVersion) return null;
        return 'Penghapusan lokal ditahan karena data online berubah atau versi dasar tidak tersedia.';
    }
    $incomingVersion = (int)($input['version'] ?? 0);
    if ($incomingVersion > 0 && isset($server['version'])) {
        // Payload hasil edit lokal umumnya membawa version baru; online aman bila
        // masih berada tepat satu versi di belakang payload tersebut.
        if ((int)$server['version'] < $incomingVersion) return null;
        return 'Perubahan lokal bertabrakan dengan versi online yang sama atau lebih baru.';
    }
    $baseUpdated = trim((string)($input['_baseUpdatedAt'] ?? $input['updatedAt'] ?? $input['updated_at'] ?? ''));
    $serverUpdated = trim((string)($server['updatedAt'] ?? $server['updated_at'] ?? ''));
    if ($baseUpdated !== '' && $serverUpdated !== '') {
        $baseTs = strtotime($baseUpdated); $serverTs = strtotime($serverUpdated);
        if ($baseTs !== false && $serverTs !== false && $serverTs <= $baseTs + 1) return null;
        return 'Perubahan lokal bertabrakan dengan timestamp online yang lebih baru.';
    }
    return $baseRevision > 0 && $currentRevision > $baseRevision
        ? 'Data online berubah sejak mirror terakhir; perubahan lokal ditahan karena tidak membawa version/timestamp dasar.'
        : null;
}

function tamasyaNodeSnapshotTableMap(): array {
    // Authoritative business data mirrored between primary and standby.
    // Node-local security, session, rate-limit, schema/deployment and cluster
    // control tables are intentionally excluded to avoid replay loops and
    // credential/session leakage.
    return [
        'config'=>['pk'=>['id'],'exclude'=>['gemini_api_key','telegram_bot_token','telegram_webhook_secret','telegram_webhook_url','telegram_webhook_last_error','telegram_webhook_checked_at','telegram_chat_ids','telegram_webhook_active','db_host','db_port','db_name','db_user','db_password','smtp_host','smtp_port','smtp_user','smtp_password','smtp_secure','smtp_from','cleanup_mode_enabled_by']],
        'staff'=>['pk'=>['id'],'exclude'=>['telegram_chat_id','two_factor_code_hash','two_factor_challenge_hash','two_factor_device_id','two_factor_expires']],
        'rooms'=>['pk'=>['id']],
        'bookings'=>['pk'=>['id'],'exclude'=>['ktpPhoto']],
        'transactions'=>['pk'=>['id'],'exclude'=>['proofUrl']],
        'transaction_allocations'=>['pk'=>['id']],
        'guest_security_deposits'=>['pk'=>['id']],
        'guest_security_deposit_ledger'=>['pk'=>['id']],
        'financial_reporting_periods'=>['pk'=>['period_key']],
        'historical_backfill_adjustments'=>['pk'=>['id']],
        'categories'=>['pk'=>['id']],
        'subcategories'=>['pk'=>['id']],
        // Optional per-property catalog tables are authoritative business masters.
        // Mirror parent items before their price revisions; absent on BOTH nodes is valid.
        'tamasya_catalog_items'=>['pk'=>['id']],
        'tamasya_catalog_rates'=>['pk'=>['id']],
        'tamasya_catalog_transaction_lines'=>['pk'=>['transaction_id']],
        'notifications'=>['pk'=>['id']],
        'notification_reads'=>['pk'=>['notification_id','staff_id']],
        'activity_logs'=>['pk'=>['id']],
        'audit_logs'=>['pk'=>['id']],
        'sync_operations'=>['pk'=>['operation_id']],
        'sync_devices'=>['pk'=>['device_id']],
        'request_operation_receipts'=>['pk'=>['operation_id']],
        // backup_runs is node-local filesystem metadata. The SQL file is not mirrored,
        // so copying only the DB row could make a promoted standby believe a backup
        // exists locally when it does not. Each node records/verifies its own backups.
        'test_data_purge_logs'=>['pk'=>['id']],
        'data_purge_batches'=>['pk'=>['id']],
        'data_purge_batch_items'=>['pk'=>['id']],
        'data_integrity_issues'=>['pk'=>['id']],
        'chat_messages'=>['pk'=>['id']],
        'telegram_bindings'=>['pk'=>['telegram_user_id']],
        'telegram_binding_conflict_archive'=>['pk'=>['archive_key']],
        'telegram_messages'=>['pk'=>['id']],
        'telegram_update_log'=>['pk'=>['update_id']],
        'telegram_callback_tokens'=>['pk'=>['token']],
        'communication_channels'=>['pk'=>['id'],'exclude'=>['enabled','inbound_enabled','outbound_enabled','config_json','credential_encrypted','webhook_secret_encrypted','health_status','last_checked_at','last_error']],
        'communication_identities'=>['pk'=>['id']],
        'communication_sessions'=>['pk'=>['id']],
        'communication_webhook_events'=>['pk'=>['id']],
        'communication_outbox'=>['pk'=>['id']],
        'communication_delivery_attempts'=>['pk'=>['id']],
        'communication_inbox'=>['pk'=>['id']],
        'communication_preferences'=>['pk'=>['staff_id','event_type']],
        'communication_templates'=>['pk'=>['id']],
        'public_site_settings'=>['pk'=>['id']],
        'public_room_types'=>['pk'=>['id']],
        'public_promotions'=>['pk'=>['id']],
        'promotion_codes'=>['pk'=>['id']],
        'promotion_code_usages'=>['pk'=>['id']],
        'public_site_media'=>['pk'=>['id']],
        'public_reservation_requests'=>['pk'=>['id']],
        'public_support_conversations'=>['pk'=>['id']],
        'public_support_messages'=>['pk'=>['id']],
        'bank_accounts'=>['pk'=>['id']],
        'salary_slips'=>['pk'=>['id']],
        'staff_leave_requests'=>['pk'=>['id']],
        'staff_savings_accounts'=>['pk'=>['staff_id']],
        'staff_savings_requests'=>['pk'=>['id']],
        'staff_savings_ledger'=>['pk'=>['id']],
        'inventory'=>['pk'=>['id']],
        'inventory_maintenance'=>['pk'=>['id']],
        'pos_categories'=>['pk'=>['id']],
        'pos_products'=>['pk'=>['id']],
        'pos_sales'=>['pk'=>['id']],
        'pos_sale_items'=>['pk'=>['id']],
        'pos_stock_movements'=>['pk'=>['id']],
        'pos_print_logs'=>['pk'=>['id']],
        'pos_delivery_events'=>['pk'=>['id']],
        'shift_reports'=>['pk'=>['id']],
        'shift_sessions'=>['pk'=>['id']],
        'attendance'=>['pk'=>['id'],'exclude'=>['face_data_url','fingerprint_token']],
        'biometric_devices'=>['pk'=>['id']],
        'biometric_device_users'=>['pk'=>['id']],
        'staff_biometric_profiles'=>['pk'=>['id']],
        'biometric_verifications'=>['pk'=>['id']],
        'ota_disbursements'=>['pk'=>['id']],
        'ota_disbursement_items'=>['pk'=>['id']],
        'approval_requests'=>['pk'=>['id']],
        'reconciliation_items'=>['pk'=>['id']],
        'tax_rules'=>['pk'=>['id']],
        'housekeeping_tasks'=>['pk'=>['id']],
        'room_vacancy_reports'=>['pk'=>['id']],
        'maintenance_tickets'=>['pk'=>['id']],
        'guest_profiles'=>['pk'=>['id']],
        // Canonical operational workflow state must survive Primary -> Standby mirror
        // and planned switchover. These rows are not node-local metadata: several
        // directly affect room sellability, custody/review continuity, and incident work.
        'guest_service_requests'=>['pk'=>['id']],
        'lost_found_items'=>['pk'=>['id']],
        'maintenance_cancellation_reviews'=>['pk'=>['id']],
        'operational_entity_links'=>['pk'=>['id']],
        'operational_incidents'=>['pk'=>['id']],
        'room_operational_holds'=>['pk'=>['id']],
        'system_alerts'=>['pk'=>['id']],
        'app_documents'=>['pk'=>['id']],
        'journal_entries'=>['pk'=>['id']],
        'journal_lines'=>['pk'=>['id']],
        'property_settings'=>['pk'=>['id']],
        'hotel_operational_settings'=>['pk'=>['id'],'exclude'=>['smart_lock_bridge_token']],
        'room_access_control'=>['pk'=>['room_number']],
        'room_key_events'=>['pk'=>['id']],
        'smart_lock_jobs'=>['pk'=>['id']],
        'night_audit_runs'=>['pk'=>['id']],
        'night_audit_items'=>['pk'=>['id']],
        'growth_rate_plans'=>['pk'=>['id']],
        'growth_rate_rules'=>['pk'=>['id']],
        'growth_rate_overrides'=>['pk'=>['id']],
        'growth_companies'=>['pk'=>['id']],
        'growth_group_reservations'=>['pk'=>['id']],
        'growth_group_booking_links'=>['pk'=>['id']],
        'growth_vendors'=>['pk'=>['id']],
        'growth_purchase_orders'=>['pk'=>['id']],
        'growth_purchase_order_items'=>['pk'=>['id']],
        'growth_purchase_order_payments'=>['pk'=>['id']],
        'growth_channel_mappings'=>['pk'=>['id']],
        'growth_channel_events'=>['pk'=>['id']],
        'growth_payment_intents'=>['pk'=>['id']],
        'growth_payment_events'=>['pk'=>['id']],
        'growth_folios'=>['pk'=>['id']],
        'growth_folio_transaction_allocations'=>['pk'=>['id']],
        'growth_folio_charge_allocations'=>['pk'=>['id']],
        'growth_folio_routing_rules'=>['pk'=>['id']],
        'growth_folio_invoices'=>['pk'=>['id']],
        'growth_purchase_requests'=>['pk'=>['id']],
        'growth_purchase_request_items'=>['pk'=>['id']],
        'growth_purchase_request_po_links'=>['pk'=>['id']],
        'growth_goods_receipts'=>['pk'=>['id']],
        'growth_goods_receipt_items'=>['pk'=>['id']],
        'growth_supplier_invoices'=>['pk'=>['id']],
        'growth_supplier_invoice_lines'=>['pk'=>['id']],
        'growth_supplier_invoice_payments'=>['pk'=>['id']],
        'growth_guest_booking_links'=>['pk'=>['id']],
        'growth_loyalty_accounts'=>['pk'=>['id']],
        'growth_loyalty_ledger'=>['pk'=>['id']],
        'growth_loyalty_vouchers'=>['pk'=>['id']],
        'growth_guest_consents'=>['pk'=>['id']],
        'growth_crm_segments'=>['pk'=>['id']],
        'growth_crm_campaigns'=>['pk'=>['id']],
        'growth_crm_campaign_recipients'=>['pk'=>['id']],
        'growth_health_alert_rules'=>['pk'=>['id']],
        'growth_provider_adapters'=>['pk'=>['id']],
        'growth_internal_memos'=>['pk'=>['id']],
        'sync_tombstones'=>['pk'=>['id']],
        'number_sequences'=>['pk'=>['sequence_key']]
    ];
}

function tamasyaNodeTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function tamasyaNodeTableColumns(PDO $pdo, string $table, array $exclude = []): array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    $excludeMap = array_fill_keys(array_map('strtolower', $exclude), true);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
        if (!isset($excludeMap[strtolower((string)$column)])) $columns[] = (string)$column;
    }
    return $columns;
}

function tamasyaEnsureNodeMutationGuard(PDO $pdo): array {
    tamasyaEnsureNodeSyncTables($pdo);
    if (tamasyaNodeRole() !== 'local_backup' || !tamasyaNodeSyncEnabled()) {
        return ['ready'=>false,'mode'=>'inactive','failed'=>[]];
    }
    // Fresh V137 two-server mode is a fenced single-writer cluster. Standby API
    // mutations are forwarded to the active Primary and never executed locally;
    // therefore no runtime trigger DDL is needed. This keeps node-sync
    // lightweight and removes schema mutation from the agent itself.
    if (!function_exists('tamasyaClusterEnabled') || !tamasyaClusterEnabled()) {
        try { $pdo->prepare("UPDATE node_sync_settings SET mutation_guard_ready=0 WHERE id='system_default'")->execute(); } catch (Throwable $ignored) {}
        return [
            'ready'=>false,
            'mode'=>'unsupported_legacy_local_writer',
            'failed'=>['Fresh V137 two-server sync requires NODE_CLUSTER_ENABLED=1; legacy standby-local-write mode is disabled.']
        ];
    }
    $state=function_exists('tamasyaClusterRefreshGlobals')?tamasyaClusterRefreshGlobals($pdo):[];
    $isStandby=tamasyaNodeRole()==='local_backup';
    $singleWriter=($state['enabled']??false) && $isStandby;
    $pdo->prepare("UPDATE node_sync_settings SET mutation_guard_ready=? WHERE id='system_default'")->execute([$singleWriter?1:0]);
    return ['ready'=>$singleWriter,'mode'=>'cluster_single_writer','failed'=>$singleWriter?[]:['Node belum menjadi standby cluster yang tervalidasi.']];
}

function tamasyaNodeSyncEnvironmentCheck(): array {
    $errors=[];
    $clusterEnabled=function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled();
    $syncEnabled=tamasyaNodeSyncEnabled();
    if(!$clusterEnabled && !$syncEnabled) return ['ok'=>true,'errors'=>[],'enabled'=>false];

    // Fresh V137 supports two-server operation only as a single-writer cluster.
    // Do not silently fall back to the old local-writer mode.
    if($clusterEnabled && !$syncEnabled)$errors[]='NODE_CLUSTER_ENABLED/flexible membutuhkan NODE_SYNC_ENABLED=1.';
    if($syncEnabled && !$clusterEnabled)$errors[]='NODE_SYNC_ENABLED=1 membutuhkan NODE_CLUSTER_ENABLED=1 atau TAMASYA_NODE_MODE=flexible.';

    $rawNodeId=trim((string)(getenv('TAMASYA_NODE_ID')?:''));
    $rawClusterId=trim((string)(getenv('TAMASYA_CLUSTER_ID')?:''));
    $rawKind=strtolower(trim((string)(getenv('TAMASYA_NODE_KIND')?:'')));
    $rawInitial=strtolower(trim((string)(getenv('TAMASYA_NODE_INITIAL_ROLE')?:'')));
    $rawPeerId=trim((string)(getenv('NODE_CLUSTER_PEER_ID')?:''));
    $propertyId=tamasyaNodePropertyId();
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,99}$/',$rawNodeId))$errors[]='TAMASYA_NODE_ID wajib eksplisit dan valid (2-100 karakter: huruf/angka/._-).';
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,99}$/',$rawClusterId))$errors[]='TAMASYA_CLUSTER_ID wajib eksplisit dan valid (2-100 karakter: huruf/angka/._-).';
    if(!in_array($rawKind,['local','hosting'],true))$errors[]='TAMASYA_NODE_KIND wajib local atau hosting.';
    if(!in_array($rawInitial,['primary','online_primary','standby','local_backup'],true))$errors[]='TAMASYA_NODE_INITIAL_ROLE wajib primary atau standby pada cluster.';
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,99}$/',$rawPeerId))$errors[]='NODE_CLUSTER_PEER_ID wajib eksplisit dan valid.';
    if($rawNodeId!=='' && $rawPeerId!=='' && hash_equals($rawNodeId,$rawPeerId))$errors[]='NODE_CLUSTER_PEER_ID tidak boleh sama dengan TAMASYA_NODE_ID.';
    if($propertyId==='')$errors[]='TAMASYA_PROPERTY_ID wajib valid untuk sinkronisasi/cluster agar peer tidak tertukar antar-property.';
    if(strlen(tamasyaNodeSyncSecret())<32)$errors[]='NODE_SYNC_SHARED_SECRET minimal 32 karakter random dan harus sama pada dua node property.';
    if(!function_exists('curl_init'))$errors[]='Extension cURL wajib pada kedua node flexible cluster.';

    $allowed=tamasyaNodeAllowedIds();
    if(!$allowed)$errors[]='TAMASYA_ALLOWED_NODE_IDS wajib berisi ID peer yang diizinkan.';
    elseif($rawPeerId!=='' && !in_array($rawPeerId,$allowed,true))$errors[]='TAMASYA_ALLOWED_NODE_IDS wajib memasukkan NODE_CLUSTER_PEER_ID.';
    if($rawNodeId!=='' && in_array($rawNodeId,$allowed,true))$errors[]='TAMASYA_ALLOWED_NODE_IDS tidak perlu/ tidak boleh mengizinkan node sendiri.';

    $allowHttpLocal=filter_var(getenv('NODE_SYNC_ALLOW_HTTP_LOCAL')?:'0',FILTER_VALIDATE_BOOLEAN);
    $validateUrl=static function(string $name,string $value,bool $allowHttpLocal) use (&$errors): void {
        $value=trim($value);
        if($value===''){$errors[]=$name.' wajib diisi.';return;}
        $parts=parse_url($value);
        if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])){$errors[]=$name.' bukan URL absolut yang valid.';return;}
        $scheme=strtolower((string)$parts['scheme']);
        $host=strtolower((string)$parts['host']);
        if(!in_array($scheme,['http','https'],true)){$errors[]=$name.' hanya boleh http/https.';return;}
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))$errors[]=$name.' tidak boleh memuat user/password/query/fragment.';
        $isPrivateHost=in_array($host,['localhost','127.0.0.1','::1'],true)
            || (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/',$host)
            || str_ends_with($host,'.local')
            || !str_contains($host,'.');
        if($scheme!=='https' && !($allowHttpLocal && $isPrivateHost))$errors[]=$name.' wajib HTTPS. HTTP hanya boleh untuk host private/LAN dengan NODE_SYNC_ALLOW_HTTP_LOCAL=1.';
    };
    $publicUrl=trim((string)(getenv('NODE_CLUSTER_PUBLIC_URL')?:getenv('NODE_SYNC_SELF_URL')?:''));
    $peerUrl=trim((string)(getenv('NODE_CLUSTER_PEER_URL')?:getenv('NODE_SYNC_PRIMARY_URL')?:''));
    $validateUrl('NODE_CLUSTER_PUBLIC_URL',$publicUrl,$allowHttpLocal);
    $validateUrl('NODE_CLUSTER_PEER_URL',$peerUrl,$allowHttpLocal);
    if($publicUrl!=='' && $peerUrl!=='' && rtrim(strtolower($publicUrl),'/')===rtrim(strtolower($peerUrl),'/'))$errors[]='NODE_CLUSTER_PUBLIC_URL dan NODE_CLUSTER_PEER_URL tidak boleh menunjuk endpoint yang sama.';

    $ttl=function_exists('tamasyaClusterLeaseTtlSeconds')?tamasyaClusterLeaseTtlSeconds():300;
    $renew=function_exists('tamasyaClusterLeaseRenewWindowSeconds')?tamasyaClusterLeaseRenewWindowSeconds():120;
    if($renew>=$ttl)$errors[]='NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS harus lebih kecil dari lease TTL.';

    return [
        'ok'=>!$errors,
        'errors'=>array_values(array_unique($errors)),
        'enabled'=>true,
        'nodeId'=>$rawNodeId,
        'clusterId'=>$rawClusterId,
        'propertyId'=>$propertyId,
        'peerId'=>$rawPeerId,
        'nodeKind'=>$rawKind,
        'initialRole'=>$rawInitial,
    ];
}
