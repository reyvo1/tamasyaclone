<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 1335: currentDeviceId */
function currentDeviceId() {
    $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
    return $deviceId !== '' ? substr($deviceId, 0, 190) : 'unknown-device';
}

/** Source line 1340: currentAppVersion */
function currentAppVersion() {
    $version = trim((string)($_SERVER['HTTP_X_APP_VERSION'] ?? ''));
    return $version !== '' ? substr($version, 0, 50) : 'unknown';
}


/** Opaque hotel namespace used by browser offline storage and sync fingerprints. */
function tamasyaHotelScopeId(): string {
    $databaseName=strtolower(trim((string)($GLOBALS['tamasya_runtime_database_name']??'')));
    $propertyId=strtolower(trim((string)($GLOBALS['tamasya_property_id']??'')));
    if($propertyId==='' || $propertyId==='default') throw new RuntimeException('Property identity belum tersedia untuk hotel scope.');
    $clusterId=function_exists('tamasyaClusterId')
        ? strtolower(trim((string)tamasyaClusterId()))
        : strtolower(trim((string)(getenv('TAMASYA_CLUSTER_ID')?:'tamasya-hotel-cluster')));
    return 'hotel_'.substr(hash('sha256',$propertyId.'|'.$clusterId.'|'.$databaseName),0,32);
}

/** Validate an opaque browser session namespace without trusting it as identity. */
function tamasyaNormalizeOfflineSessionScopeId($value, bool $allowGenerate=true): string {
    $scope=trim((string)$value);
    if($scope===''&&$allowGenerate)$scope='offline_'.bin2hex(random_bytes(18));
    if(!preg_match('/^[A-Za-z0-9._:-]{16,100}$/',$scope)){
        throw new InvalidArgumentException('Scope sesi offline tidak valid.');
    }
    return $scope;
}

function currentOfflineSessionScopeId(): string {
    return tamasyaNormalizeOfflineSessionScopeId($_SERVER['HTTP_X_TAMASYA_OFFLINE_SESSION_SCOPE']??'',false);
}

/** Source line 1345: tamasyaOperationCanonicalize */
function tamasyaOperationCanonicalize($value, bool $isQuery = false) {
    if (!is_array($value)) {
        if (is_float($value) && !is_finite($value)) return (string)$value;
        return $value;
    }
    $keys = array_keys($value);
    $isList = $keys === ($keys ? range(0, count($keys) - 1) : []);
    if ($isList) {
        return array_map(static fn($item) => tamasyaOperationCanonicalize($item, false), $value);
    }
    $normalized = [];
    foreach ($value as $key => $item) {
        $keyString = (string)$key;
        if ($isQuery && in_array(strtolower($keyString), ['t','_ts','cachebust','endpointprobe'], true)) continue;
        $normalized[$keyString] = tamasyaOperationCanonicalize($item, false);
    }
    ksort($normalized, SORT_STRING);
    return $normalized;
}

/** Source line 1365: tamasyaRequestOperationPayloadHash */
function tamasyaRequestOperationPayloadHash(array $user, string $action, string $method, array $query, array $input): string {
    $payload = [
        'staffId'=>(string)($user['id'] ?? ''),
        'deviceId'=>currentDeviceId(),
        'sessionId'=>(string)($user['session_id'] ?? ''),
        'method'=>strtoupper($method),
        'action'=>$action,
        'query'=>tamasyaOperationCanonicalize($query, true),
        'input'=>tamasyaOperationCanonicalize($input, false),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) throw new RuntimeException('Payload mutasi tidak dapat dinormalisasi untuk idempotensi.');
    return hash('sha256', $json);
}

/** Source line 1385: tamasyaClaimRequestOperation */
function tamasyaClaimRequestOperation(PDO $pdo, array $user, string $action, string $method, array $query, array $input): array {
    $operationId = trim((string)($GLOBALS['tamasya_request_operation_id'] ?? ''));
    if ($operationId === '') return ['state'=>'missing'];
    $staffId = trim((string)($user['id'] ?? ''));
    if ($staffId === '') throw new RuntimeException('Staff ID tidak tersedia untuk receipt mutasi.');
    $deviceId = currentDeviceId();
    $sessionId = trim((string)($user['session_id'] ?? ''));
    $method = strtoupper($method);
    $payloadHash = tamasyaRequestOperationPayloadHash($user, $action, $method, $query, $input);
    $clusterState = $GLOBALS['tamasya_cluster_state'] ?? [];
    $epoch = isset($clusterState['leadership_epoch']) ? (int)$clusterState['leadership_epoch'] : null;
    $fenceHash = trim((string)($clusterState['fencing_token'] ?? '')) !== '' ? hash('sha256', (string)$clusterState['fencing_token']) : null;
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT IGNORE INTO request_operation_receipts
            (operation_id,staff_id,device_id,session_id,action,http_method,payload_hash,cluster_epoch,fencing_token_hash,status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,'processing',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $insert->execute([$operationId,$staffId,$deviceId,$sessionId !== '' ? $sessionId : null,substr($action,0,100),$method,$payloadHash,$epoch,$fenceHash]);
        $wasInserted = $insert->rowCount() === 1;
        $select = $pdo->prepare("SELECT * FROM request_operation_receipts WHERE operation_id=? LIMIT 1 FOR UPDATE");
        $select->execute([$operationId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Receipt mutasi gagal dibaca setelah klaim.');
        $matches = hash_equals((string)$row['payload_hash'], $payloadHash)
            && hash_equals((string)$row['staff_id'], $staffId)
            && hash_equals((string)$row['device_id'], $deviceId)
            && hash_equals((string)($row['session_id'] ?? ''), $sessionId)
            && hash_equals((string)$row['action'], substr($action,0,100))
            && hash_equals(strtoupper((string)$row['http_method']), $method);
        if (!$matches) {
            tamasyaFinancialCommit($pdo);
            return ['state'=>'mismatch','operationId'=>$operationId];
        }
        if ($wasInserted) {
            tamasyaFinancialCommit($pdo);
            return ['state'=>'claimed','operationId'=>$operationId,'payloadHash'=>$payloadHash];
        }
        $status = strtolower((string)($row['status'] ?? 'processing'));
        if (in_array($status, ['completed','failed','rejected'], true)) {
            tamasyaFinancialCommit($pdo);
            return ['state'=>'terminal','operationId'=>$operationId,'row'=>$row,'payloadHash'=>$payloadHash];
        }
        $updatedAt = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
        if ($status === 'processing' && $updatedAt > 0 && $updatedAt < time() - 600) {
            $pdo->prepare("UPDATE request_operation_receipts SET status='uncertain',error_message='Proses tidak menyelesaikan receipt dalam 10 menit; rekonsiliasi manual wajib dilakukan.',updated_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'")
                ->execute([$operationId]);
            $status = 'uncertain';
        }
        tamasyaFinancialCommit($pdo);
        return ['state'=>$status === 'uncertain' ? 'uncertain' : 'processing','operationId'=>$operationId,'payloadHash'=>$payloadHash];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Store durable operation responses compactly without changing the replay contract.
 * Large API mutation responses often contain the full projected DB snapshot; keeping
 * hundreds of raw copies can inflate an otherwise-empty staging DB by tens of MB.
 * The prefix is intentionally self-describing and backward-compatible with raw rows.
 */
function tamasyaEncodeReceiptResponseBody(string $responseBody): string {
    if ($responseBody === '' || strlen($responseBody) < 4096 || !function_exists('gzencode')) return $responseBody;
    $compressed = @gzencode($responseBody, 6, ZLIB_ENCODING_GZIP);
    if ($compressed === false || $compressed === '') return $responseBody;
    $encoded = '@tamasya:gzip-base64:' . base64_encode($compressed);
    return strlen($encoded) < strlen($responseBody) ? $encoded : $responseBody;
}

function tamasyaDecodeReceiptResponseBody(?string $storedBody): string {
    $storedBody = (string)$storedBody;
    $prefix = '@tamasya:gzip-base64:';
    if (!str_starts_with($storedBody, $prefix)) return $storedBody;
    if (!function_exists('gzdecode')) return '';
    $binary = base64_decode(substr($storedBody, strlen($prefix)), true);
    if ($binary === false) return '';
    $decoded = @gzdecode($binary);
    return $decoded === false ? '' : $decoded;
}

/** Only the transient PMS-wide UI projection is omitted from durable receipts.
 * The mutation result (IDs, amounts, warnings/errors) remains authoritative.
 * Existing raw/gzip receipts keep their byte-exact replay behavior.
 */
function tamasyaPrepareDurableReceiptBody(string $responseBody,string $action): string {
    if(!in_array($action,['transactions','notifications-read'],true))return $responseBody;
    try{$response=json_decode($responseBody,false,512,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){return $responseBody;}
    if(!is_object($response)||($response->success??null)!==true||!isset($response->db)||!is_object($response->db))return $responseBody;
    $projection=$response->db;
    // Recognize the actual role-scoped hotel projection, not an arbitrary db field.
    foreach(['rooms','bookings','transactions'] as $field)if(!property_exists($projection,$field)||!is_array($projection->$field))return $responseBody;
    unset($response->db);
    $envelope=json_encode(['format'=>'tamasya-receipt-projection-v1','response'=>$response,'projection'=>'role-scoped-hotel-data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    return '@tamasya:projection-v1:'.$envelope;
}

/** Rehydrate a UI projection only after actor/device/session/payload checks.
 * A projection read failure must never rerun or reinterpret a committed mutation.
 */
function tamasyaReplayDurableReceiptBody(?string $storedBody,callable $projectHotelData): string {
    $body=tamasyaDecodeReceiptResponseBody($storedBody);$prefix='@tamasya:projection-v1:';
    if(!str_starts_with($body,$prefix))return $body;
    $envelope=json_decode(substr($body,strlen($prefix)),false,512,JSON_THROW_ON_ERROR);
    if(!is_object($envelope)||($envelope->format??'')!=='tamasya-receipt-projection-v1'||($envelope->projection??'')!=='role-scoped-hotel-data'||!isset($envelope->response)||!is_object($envelope->response)||($envelope->response->success??null)!==true||property_exists($envelope->response,'db'))throw new RuntimeException('Receipt operasi tidak dapat diverifikasi; transaksi tidak diulang.');
    $response=$envelope->response;
    try{$response->db=$projectHotelData();$response->receiptProjection='current-role-scoped';}
    catch(Throwable $e){$response->refreshRequired=true;$response->receiptProjection='refresh-required';}
    return json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
}

/** Source line 1441: tamasyaCompleteRequestOperation */
function tamasyaCompleteRequestOperation(PDO $pdo, string $operationId, string $payloadHash, int $httpStatus, string $responseBody, ?array $classification = null, ?array $fatalError = null, string $receiptAction = ''): void {
    if ($operationId === '' || $payloadHash === '') return;
    $classification = $classification ?? tamasyaNodeClassifyResponse($httpStatus, $responseBody);
    $fatalTypes = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
    $fatal = is_array($fatalError) && in_array((int)($fatalError['type'] ?? 0), $fatalTypes, true);
    $completed = !$fatal && !empty($classification['completed']);
    $storedHttpStatus = $fatal ? 500 : (int)($classification['httpStatus'] ?? $httpStatus ?: 500);
    if ($storedHttpStatus <= 0) $storedHttpStatus = $completed ? 200 : 500;
    // Pisahkan penolakan bisnis 4xx dari kegagalan server 5xx. Keduanya terminal,
    // tetapi status yang berbeda penting untuk rekonsiliasi dan retry client.
    $storedStatus = $completed ? 'completed' : (($storedHttpStatus >= 400 && $storedHttpStatus < 500) ? 'rejected' : 'failed');
    $error = $completed ? null : trim((string)($classification['message'] ?? ($fatalError['message'] ?? ('HTTP '.$storedHttpStatus))));
    // PHP 8.4 mendeprekasikan substr(null, ...). Error handler runtime mengubah
    // deprecation menjadi exception, sehingga mutasi sukses dulu selalu berakhir
    // receipt uncertain. Normalisasi eksplisit sebelum pemotongan.
    $storedError = is_string($error) && trim($error) !== '' ? substr(trim($error), 0, 1000) : null;
    if ($responseBody === '' && $fatal) {
        $responseBody = json_encode(['success'=>false,'error'=>'Mutasi berhenti sebelum respons selesai; operation_id diblokir untuk mencegah eksekusi ganda.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '';
    }
    $stmt = $pdo->prepare("UPDATE request_operation_receipts
        SET status=?,http_status=?,response_body=?,error_message=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
        WHERE operation_id=? AND payload_hash=? AND status='processing'");
    $durableBody = $completed ? tamasyaPrepareDurableReceiptBody($responseBody,$receiptAction) : $responseBody;
    $storedResponseBody = tamasyaEncodeReceiptResponseBody($durableBody);
    $stmt->execute([$storedStatus,$storedHttpStatus,$storedResponseBody,$storedError,$operationId,$payloadHash]);
}

/** Source line 1460: sanitizeAuditValue */
function sanitizeAuditValue($value, $depth = 0) {
    if ($depth > 4) return '[max-depth]';
    if (is_array($value)) {
        $safe = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string)$key);
            if (preg_match('/password|token|secret|proofurl|ktpphoto|facedata|dataurl|base64|imagecontent|apikey|credential/', $normalized)) {
                $safe[$key] = '[REDACTED]';
            } else {
                $safe[$key] = sanitizeAuditValue($item, $depth + 1);
            }
        }
        return $safe;
    }
    if (is_string($value) && strlen($value) > 2000) return substr($value, 0, 2000) . '…';
    return $value;
}

/** Source line 1478: writeEnterpriseAudit */
function tamasyaAuditAttemptSnapshot(array $input): array {
    $safe = [];
    foreach ($input as $key => $value) {
        $name = strtolower((string)$key);
        if (preg_match('/password|token|secret|credential|proof|ktp|face|image|dataurl|base64|apikey/', $name)) {
            $safe[$key] = '[REDACTED]';
            continue;
        }
        if (in_array($name, ['db','snapshot','hoteldata','payload','content','filecontent'], true)) {
            $safe[$key] = is_array($value)
                ? ['_summary'=>'omitted-from-attempt-audit','itemCount'=>count($value)]
                : ['_summary'=>'omitted-from-attempt-audit','length'=>is_string($value)?strlen($value):0];
            continue;
        }
        if (is_array($value) && count($value) > 50) {
            $safe[$key] = ['_summary'=>'large-list','itemCount'=>count($value)];
            continue;
        }
        $safe[$key] = sanitizeAuditValue($value);
    }
    return $safe;
}


/**
 * Mutation-attempt audit yang action-aware. Offline sync dapat membawa snapshot
 * queue yang besar; menyimpan payload lengkap pada setiap retry membuat audit_logs
 * tumbuh jauh lebih cepat daripada ledger bisnis. Untuk action sync, simpan hanya
 * count + hash + marker waktu. Detail queue tetap berada di IndexedDB client,
 * sync_operations/request receipts, dan audit entity yang berhasil diposting.
 */
function tamasyaMutationAttemptAuditSnapshot(string $action, array $input): array {
    if (strtolower(trim($action)) !== 'sync') return tamasyaAuditAttemptSnapshot($input);
    $queueKeys = [
        'rooms','bookings','transactions','bookingActions','inventory','inventoryMaintenance',
        'deletedTransactionIds','deletedRoomIds','deletedBookingIds','deletedInventoryIds','deletedMaintenanceIds','resolvedConflicts'
    ];
    $counts = [];
    foreach ($queueKeys as $key) {
        $value = $input[$key] ?? [];
        $counts[$key] = is_array($value) ? count($value) : 0;
    }
    $canonical = tamasyaOperationCanonicalize($input, false);
    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    return [
        '_summary' => 'offline-sync-attempt',
        'counts' => $counts,
        'lastPulledAt' => isset($input['lastPulledAt']) ? substr((string)$input['lastPulledAt'], 0, 40) : null,
        'payloadHash' => hash('sha256', $json === false ? '' : $json),
        'operationIdHash' => isset($input['operationId']) && trim((string)$input['operationId']) !== ''
            ? hash('sha256', (string)$input['operationId'])
            : null,
    ];
}

function writeEnterpriseAudit($pdo, $user, $action, $entityType = null, $entityId = null, $oldData = null, $newData = null, $source = 'web', array $options = []) {
    if (!$pdo) {
        if (!empty($options['required'])) throw new RuntimeException('Audit wajib tidak dapat ditulis karena koneksi database tidak tersedia.');
        return false;
    }
    $required = !empty($options['required']);
    try {
        $source = trim((string)$source) !== '' ? substr(trim((string)$source), 0, 50) : 'web';
        $channelId = trim((string)($options['channelId'] ?? ($GLOBALS['tamasya_verified_channel_id'] ?? '')));
        if ($channelId === '') $channelId = $source;
        $nodeId = function_exists('tamasyaNodeId') ? trim((string)tamasyaNodeId()) : '';
        $clusterId = function_exists('tamasyaClusterId') ? trim((string)tamasyaClusterId()) : '';
        $propertyId = strtolower(trim((string)($options['propertyId'] ?? ($user['property_id'] ?? ($GLOBALS['tamasya_property_id'] ?? '')))));
        if ($propertyId === '' || $propertyId === 'default') throw new RuntimeException('Audit wajib memiliki property_id deployment yang eksplisit.');
        $outcome = strtolower(trim((string)($options['outcome'] ?? 'success')));
        if (!in_array($outcome, ['success','rejected','failed','pending','manual'], true)) $outcome = 'success';
        $requestId = trim((string)($_SERVER['HTTP_X_TAMASYA_REQUEST_ID'] ?? ''));
        $stmt = $pdo->prepare("INSERT INTO audit_logs
            (id, staff_id, staff_name, staff_role, source, channel_id, action, entity_type, entity_id,
             old_data, new_data, outcome, ip_address, device_id, session_id, request_id, operation_id,
             node_id, cluster_id, property_id, cluster_epoch, fencing_token_hash, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([
            generateServerId('audit'),
            $user['id'] ?? null,
            $user['name'] ?? ($user['username'] ?? 'Sistem'),
            isset($user['role']) ? substr((string)$user['role'], 0, 50) : null,
            $source,
            substr($channelId, 0, 100),
            substr((string)$action, 0, 190),
            $entityType ? substr((string)$entityType, 0, 100) : null,
            $entityId ? substr((string)$entityId, 0, 190) : null,
            $oldData === null ? null : json_encode(sanitizeAuditValue($oldData), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $newData === null ? null : json_encode(sanitizeAuditValue($newData), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $outcome,
            $_SERVER['REMOTE_ADDR'] ?? null,
            currentDeviceId(),
            $user['session_id'] ?? null,
            $requestId !== '' ? substr($requestId, 0, 100) : null,
            $GLOBALS['tamasya_request_operation_id'] ?? ($GLOBALS['tamasya_node_replay_event_id'] ?? null),
            $nodeId !== '' ? substr($nodeId, 0, 100) : null,
            $clusterId !== '' ? substr($clusterId, 0, 100) : null,
            substr($propertyId, 0, 80),
            $GLOBALS['tamasya_request_cluster_epoch'] ?? (($GLOBALS['tamasya_cluster_state']['leadership_epoch'] ?? null)),
            $GLOBALS['tamasya_request_fencing_hash'] ?? (isset($GLOBALS['tamasya_cluster_state']['fencing_token']) ? hash('sha256',(string)$GLOBALS['tamasya_cluster_state']['fencing_token']) : null)
        ]);
        return true;
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] audit write failed', $e));
        if ($required) throw $e;
        return false;
    }
}

function writeRequiredEnterpriseAudit($pdo, $user, $action, $entityType = null, $entityId = null, $oldData = null, $newData = null, $source = 'web', array $options = []): void {
    $options['required'] = true;
    writeEnterpriseAudit($pdo, $user, $action, $entityType, $entityId, $oldData, $newData, $source, $options);
    if(function_exists('tamasyaMultiRoomCollectEvent')&&$entityType==='booking')tamasyaMultiRoomCollectEvent($pdo,(string)$entityType,(string)$entityId,(string)$action);
}

/** Snapshot rekening pembayaran tanpa menyimpan nomor atau nama pemilik mentah. */
function tamasyaBankAccountAuditSnapshot(array $row): array {
    $number = preg_replace('/\s+/', '', (string)($row['accountNumber'] ?? $row['account_number'] ?? '')) ?: '';
    $holder = trim((string)($row['accountHolder'] ?? $row['account_holder'] ?? ''));
    return [
        'id'=>(string)($row['id']??''),
        'name'=>(string)($row['name']??''),
        'type'=>(string)($row['type']??''),
        'isActive'=>(int)($row['isActive']??$row['is_active']??0),
        'accountNumberLast4'=>$number!==''?substr($number,-4):null,
        'accountNumberHash'=>$number!==''?hash('sha256',$number):null,
        'accountHolderHash'=>$holder!==''?hash('sha256',$holder):null,
    ];
}

function tamasyaMaskEmailAddress(string $email): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '[email-invalid]';
    [$local,$domain] = explode('@',$email,2);
    $visible = tamasyaStringLength($local) <= 2
        ? substr($local,0,1)
        : substr($local,0,2);
    return $visible.'***@'.$domain;
}

/** Snapshot audit booking tanpa menyimpan identitas tamu atau referensi KTP mentah. */
function tamasyaBookingAuditSnapshot(?array $row): ?array {
    if (!$row) return null;
    $identity = [
        'guestName'=>(string)($row['guestName']??''),
        'guestPhone'=>(string)($row['guestPhone']??''),
        'guestEmail'=>(string)($row['guestEmail']??''),
        'ktpPhoto'=>(string)($row['ktpPhoto']??''),
    ];
    return [
        'id'=>(string)($row['id']??''),
        'guestFingerprint'=>hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),
        'roomNumber'=>(string)($row['roomNumber']??''),
        'roomType'=>(string)($row['roomType']??''),
        'checkIn'=>$row['checkIn']??null,
        'checkOut'=>$row['checkOut']??null,
        'isOpenEnded'=>(int)($row['isOpenEnded']??0),
        'totalAmount'=>(float)($row['totalAmount']??0),
        'vatRate'=>(($row['vatRate']??null)===null||($row['vatRate']??'')==='')?null:(float)$row['vatRate'],
        'vatAmount'=>(float)($row['vatAmount']??0),
        'extrasGross'=>tamasyaBookingExtrasTotal($row['extras']??null),
        'amountPaid'=>(float)($row['amountPaid']??0),
        'balanceDue'=>(float)($row['balanceDue']??0),
        'securityDepositRequiredAmount'=>(float)($row['securityDepositRequiredAmount']??0),
        'securityDepositReceived'=>(float)($row['securityDepositReceived']??0),
        'securityDepositRefunded'=>(float)($row['securityDepositRefunded']??0),
        'securityDepositForfeited'=>(float)($row['securityDepositForfeited']??0),
        'securityDepositHeld'=>(float)($row['securityDepositHeld']??0),
        'securityDepositStatus'=>(string)($row['securityDepositStatus']??'not_required'),
        'financialClosureStatus'=>(string)($row['financialClosureStatus']??'not_required'),
        'financialClosureBalance'=>(float)($row['financialClosureBalance']??0),
        'financialClosureReason'=>(string)($row['financialClosureReason']??''),
        'financialClosureAt'=>$row['financialClosureAt']??null,
        'financialClosureBy'=>$row['financialClosureBy']??null,
        'financialClosureSource'=>$row['financialClosureSource']??null,
        'financialClosureOperationId'=>$row['financialClosureOperationId']??null,
        'paymentStatus'=>(string)($row['paymentStatus']??''),
        'status'=>(string)($row['status']??''),
        'bookingSource'=>(string)($row['bookingSource']??''),
        'accessMode'=>(string)($row['accessMode']??''),
        'version'=>(int)($row['version']??0),
    ];
}

/**
 * Metadata bukti transaksi untuk audit tanpa menyimpan isi gambar, URL, atau path mentah.
 * Data URL di-hash dari byte file; referensi/path di-hash dari nilai referensinya.
 */
function tamasyaTransactionProofAuditMetadata($proof): array {
    $value = is_string($proof) ? trim($proof) : '';
    if ($value === '' || $value === 'placeholder_proof') {
        return [
            'proofStatus'=>'absent',
            'proofStorageType'=>null,
            'proofMimeType'=>null,
            'proofByteLength'=>null,
            'proofSha256'=>null,
            'proofHashBasis'=>null,
        ];
    }

    $storageType = 'stored_reference';
    $mimeType = null;
    $byteLength = null;
    $hashBasis = 'stored_reference';
    $hashMaterial = $value;

    if (preg_match('#^data:([^;,]+)?(?:;charset=[^;,]+)?;base64,(.*)$#si', $value, $match)) {
        $decoded = base64_decode((string)($match[2] ?? ''), true);
        $storageType = 'embedded_data_url';
        $mimeType = trim((string)($match[1] ?? '')) ?: null;
        if ($decoded !== false) {
            $hashMaterial = $decoded;
            $byteLength = strlen($decoded);
            $hashBasis = 'binary_content';
        } else {
            $hashBasis = 'invalid_data_url_reference';
        }
    } elseif (preg_match('#^https?://#i', $value)) {
        $storageType = 'remote_url';
    } elseif (str_starts_with($value, '/') || preg_match('#^[A-Za-z]:[\\/]#', $value)) {
        $storageType = 'local_path';
    }

    return [
        'proofStatus'=>'present',
        'proofStorageType'=>$storageType,
        'proofMimeType'=>$mimeType,
        'proofByteLength'=>$byteLength,
        'proofSha256'=>hash('sha256', $hashMaterial),
        'proofHashBasis'=>$hashBasis,
    ];
}

/** Snapshot audit transaksi: uraian, nomor dokumen, dan fingerprint bukti disimpan tanpa bukti mentah. */
function tamasyaTransactionAuditSnapshot(?array $row): ?array {
    if (!$row) return null;
    $description = trim((string)($row['description']??''));
    $proofMetadata = tamasyaTransactionProofAuditMetadata($row['proofUrl']??null);
    return array_merge([
        'id'=>(string)($row['id']??''),
        'documentNumber'=>trim((string)($row['documentNumber']??''))?:null,
        'type'=>(string)($row['type']??''),
        'category'=>(string)($row['category']??''),
        'subcategory'=>$row['subcategory']??null,
        'roomNumber'=>$row['roomNumber']??null,
        'amount'=>(float)($row['amount']??0),
        'date'=>$row['date']??null,
        'serviceDate'=>$row['serviceDate']??null,
        'reportingPeriod'=>$row['reportingPeriod']??null,
        'description'=>$description,
        'descriptionSha256'=>$description!==''?hash('sha256',$description):null,
        'bankAccountId'=>$row['bankAccountId']??null,
        'bookingId'=>$row['bookingId']??null,
        'bookingSource'=>$row['bookingSource']??null,
        'baseAmount'=>(float)($row['baseAmount']??0),
        'taxAmount'=>(float)($row['taxAmount']??0),
        'taxRate'=>(($row['taxRate']??null)===null||($row['taxRate']??'')==='')?null:(float)$row['taxRate'],
        'taxSnapshotStatus'=>$row['taxSnapshotStatus']??null,
        'taxSource'=>$row['taxSource']??null,
        'taxRuleId'=>$row['taxRuleId']??null,
        'taxRuleName'=>$row['taxRuleName']??null,
        'taxNote'=>$row['taxNote']??null,
        'transactionKind'=>(string)($row['transactionKind']??''),
        'sourceEntity'=>$row['sourceEntity']??null,
        'sourceEntityId'=>$row['sourceEntityId']??null,
        'operationId'=>$row['operationId']??null,
        'recordOrigin'=>$row['recordOrigin']??null,
        'shiftExempt'=>(int)($row['shiftExempt']??0),
        'shiftExemptionReason'=>$row['shiftExemptionReason']??null,
        'importBatchId'=>$row['importBatchId']??null,
        'historicalSourceType'=>$row['historicalSourceType']??null,
        'historicalSourceReference'=>$row['historicalSourceReference']??null,
        'sourceReportedBy'=>$row['sourceReportedBy']??null,
        'periodStatusAtEntry'=>$row['periodStatusAtEntry']??null,
        'periodImpactStatus'=>$row['periodImpactStatus']??null,
        'requiresTaxAmendment'=>(int)($row['requiresTaxAmendment']??0),
        'historicalReviewStatus'=>$row['historicalReviewStatus']??null,
        'historicalReviewedBy'=>$row['historicalReviewedBy']??null,
        'historicalReviewedAt'=>$row['historicalReviewedAt']??null,
        'periodCorrectionReason'=>$row['periodCorrectionReason']??null,
        'inputDelayDays'=>(int)($row['inputDelayDays']??0),
        'createdBy'=>$row['createdBy']??null,
        'updatedBy'=>$row['updatedBy']??null,
        'updatedSource'=>$row['updatedSource']??null,
        'reconciliationStatus'=>$row['reconciliationStatus']??null,
        'reconciliationReference'=>$row['reconciliationReference']??null,
        'version'=>(int)($row['version']??0),
    ], $proofMetadata);
}

/** Nama field yang benar-benar berubah untuk ringkasan audit. */
function tamasyaAuditChangedFieldNames(?array $before, ?array $after): array {
    if (!$before || !$after) return [];
    $ignore=['version','updatedBy','updatedSource'];
    $fields=[];
    foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $field){
        if(in_array($field,$ignore,true))continue;
        $left=$before[$field]??null;
        $right=$after[$field]??null;
        if(json_encode($left,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)!==json_encode($right,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))$fields[]=$field;
    }
    sort($fields);
    return $fields;
}

/** Snapshot audit slip gaji tanpa menyimpan nama, catatan, atau rincian komponen mentah. */
function tamasyaSalarySlipAuditSnapshot(?array $row): ?array {
    if (!$row) return null;
    $allowances=(string)($row['detailed_allowances']??$row['detailedAllowances']??'');
    $deductions=(string)($row['detailed_deductions']??$row['detailedDeductions']??'');
    return [
        'id'=>(string)($row['id']??''),
        'staffId'=>(string)($row['staff_id']??$row['staffId']??''),
        'period'=>(string)($row['period']??''),
        'basicSalary'=>(float)($row['basic_salary']??$row['basicSalary']??0),
        'allowances'=>(float)($row['allowances']??0),
        'deductions'=>(float)($row['deductions']??0),
        'bonus'=>(float)($row['bonus']??0),
        'netSalary'=>(float)($row['net_salary']??$row['netSalary']??0),
        'allowanceComponentsHash'=>hash('sha256',$allowances),
        'deductionComponentsHash'=>hash('sha256',$deductions),
        'status'=>(string)($row['status']??''),
        'paymentMethod'=>$row['payment_method']??$row['paymentMethod']??null,
        'bankAccountId'=>$row['bank_account_id']??$row['bankAccountId']??null,
        'paidAt'=>$row['paid_at']??$row['paidAt']??null,
        'cancelledAt'=>$row['cancelled_at']??$row['cancelledAt']??null,
        'reversalTransactionId'=>$row['reversal_transaction_id']??$row['reversalTransactionId']??null,
        'correctionOfSlipId'=>$row['correction_of_slip_id']??$row['correctionOfSlipId']??null,
        'correctedBySlipId'=>$row['corrected_by_slip_id']??$row['correctedBySlipId']??null,
    ];
}

/** Snapshot audit profil tamu tanpa menyimpan PII mentah. */
function tamasyaGuestProfileAuditSnapshot(?array $row): ?array {
    if (!$row) return null;
    $sensitive = [
        'name'=>(string)($row['name']??''),
        'phone'=>(string)($row['phone']??''),
        'email'=>(string)($row['email']??''),
        'identityNumber'=>(string)($row['identity_number']??$row['identityNumber']??''),
        'preferences'=>(string)($row['preferences']??''),
        'notes'=>(string)($row['notes']??''),
    ];
    return [
        'id'=>(string)($row['id']??''),
        'blacklisted'=>(int)($row['blacklisted']??0),
        'retentionUntil'=>$row['retention_until']??$row['retentionUntil']??null,
        'profileFingerprint'=>hash('sha256',json_encode($sensitive,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),
    ];
}

/** Snapshot audit dokumen tanpa menyimpan konten mentah. */
function tamasyaDocumentAuditSnapshot(?array $row): ?array {
    if (!$row) return null;
    $content=(string)($row['content']??'');
    return [
        'id'=>(string)($row['id']??''),
        'title'=>(string)($row['title']??''),
        'category'=>(string)($row['category']??''),
        'isActive'=>(int)($row['is_active']??1),
        'contentLength'=>tamasyaStringLength($content),
        'contentHash'=>hash('sha256',$content),
    ];
}

/** Source line 1531: refreshOperationalAlerts */
function refreshOperationalAlerts($pdo, bool $strict = false): bool {
    try {
        $upsert = $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE severity=VALUES(severity),title=VALUES(title),message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP");
        $lastBackup = $pdo->query("SELECT MAX(created_at) FROM backup_runs WHERE status='completed'")->fetchColumn();
        if (!$lastBackup || strtotime($lastBackup) < time() - 172800) {
            $upsert->execute(['alert_backup_stale','warning','backup','Backup belum terbaru','Belum ada backup berhasil dalam 48 jam terakhir.']);
        }
        $oldShift = $pdo->query("SELECT COUNT(*) FROM shift_sessions WHERE status='open' AND opened_at < DATE_SUB(NOW(),INTERVAL 16 HOUR)")->fetchColumn();
        if ((int)$oldShift > 0) $upsert->execute(['alert_shift_stale','warning','finance','Shift terlalu lama terbuka',(int)$oldShift.' shift belum ditutup lebih dari 16 jam.']);
        $stalePending = $pdo->query("SELECT COUNT(*) FROM sync_devices WHERE pending_count>0 AND last_seen < DATE_SUB(NOW(),INTERVAL 1 HOUR) AND status<>'disabled'")->fetchColumn();
        if ((int)$stalePending > 0) $upsert->execute(['alert_sync_stale','warning','sync','Antrean sync perangkat tertunda',(int)$stalePending.' perangkat memiliki antrean dan tidak aktif lebih dari satu jam.']);
        $telegram = $pdo->query("SELECT telegram_webhook_active FROM config WHERE id='system_default'")->fetchColumn();
        if (!(int)$telegram) $upsert->execute(['alert_telegram_inactive','info','telegram','Webhook Telegram belum aktif','Bot tidak akan menerima pembaruan Telegram sampai webhook diaktifkan.']);
        try {
            tamasyaFinancialFastAssert($pdo);
        } catch (Throwable $financialError) {
            $upsert->execute(['alert_financial_integrity','critical','finance','Integritas keuangan perlu diperiksa',substr($financialError->getMessage(),0,500)]);
        }
        return true;
    } catch (Throwable $e) {
        if ($strict) throw $e;
        error_log(clientExceptionMessage('[operations] refresh alerts failed',$e));
        return false;
    }
}

/** Source line 1547: nextDocumentNumber */
function nextDocumentNumber($pdo, $prefix, $dateValue = null) {
    $date = $dateValue ? strtotime((string)$dateValue) : time();
    if (!$date) $date = time();
    $period = date('Ymd', $date);
    $key = strtoupper($prefix).'_'.$period;
    $stmt = $pdo->prepare("INSERT INTO number_sequences (sequence_key,last_number,updated_at) VALUES (?,LAST_INSERT_ID(1),CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$key]);
    $number = (int)$pdo->lastInsertId();
    if ($number <= 0) { $q=$pdo->prepare("SELECT last_number FROM number_sequences WHERE sequence_key=?");$q->execute([$key]);$number=(int)$q->fetchColumn(); }
    return strtoupper($prefix).'-'.$period.'-'.str_pad((string)$number,6,'0',STR_PAD_LEFT);
}

/** Decode extras booking secara defensif untuk data online maupun JSON offline lama. */
function tamasyaDecodeBookingExtras($value): array {
    if (is_array($value)) return array_values(array_filter($value, 'is_array'));
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

/** Nilai tagihan layanan berasal dari booking.extras, bukan dari status pembayaran kas. */
function tamasyaBookingExtrasTotal($value): float {
    $total = 0.0;
    foreach (tamasyaDecodeBookingExtras($value) as $extra) {
        $qty = max(1.0, (float)($extra['qty'] ?? 1));
        $price = max(0.0, (float)($extra['price'] ?? $extra['unitPrice'] ?? 0));
        $lineTotal = isset($extra['total']) && is_numeric($extra['total'])
            ? max(0.0, (float)$extra['total'])
            : $price * $qty;
        $total += $lineTotal;
    }
    return round($total, 2);
}

/** Extension components are room revenue even though stored in booking.extras. */
function tamasyaBookingExtraIsRoomCharge(array $extra): bool {
    $kind=strtolower(trim((string)($extra['allocationType']??$extra['revenueType']??'')));
    return in_array($kind,['room','extension'],true)||strtolower(trim((string)($extra['taxKind']??'')))==='extension';
}
function tamasyaBookingServiceExtrasTotal($value): float {
    return tamasyaBookingExtrasTotal(array_values(array_filter(tamasyaDecodeBookingExtras($value),static fn($extra)=>!tamasyaBookingExtraIsRoomCharge($extra))));
}

/** Hubungkan item extra yang dibayar dengan transaksi kas tanpa mengubah transaksi historis lain. */
function tamasyaAttachBookingExtraPayment(PDO $pdo, string $bookingId, ?string $extraId, string $transactionId, ?string $operationId = null): void {
    $extraId = trim((string)$extraId);
    if ($bookingId === '' || $extraId === '' || $transactionId === '') return;
    $stmt = $pdo->prepare('SELECT extras FROM bookings WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$bookingId]);
    $extras = tamasyaDecodeBookingExtras($stmt->fetchColumn());
    $changed = false;
    foreach ($extras as &$extra) {
        if ((string)($extra['id'] ?? '') !== $extraId) continue;
        $extra['paymentStatus'] = 'paid';
        $extra['sourceTransactionId'] = $transactionId;
        $extra['paidAt'] = date('c');
        if ($operationId) $extra['paymentOperationId'] = $operationId;
        $changed = true;
        break;
    }
    unset($extra);
    if ($changed) {
        $pdo->prepare('UPDATE bookings SET extras=?,version=version+1,updatedAt=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([json_encode($extras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $bookingId]);
    }
}

/** Source line 1570: recalculateBookingFinancials */
function recalculateBookingFinancials($pdo, $bookingId = null, bool $strict = false, bool $forceLegacySnapshot = false) {
    try {
        $params=[];$where='';if($bookingId!==null){$where=' WHERE id=?';$params[]=$bookingId;}
        $stmt=$pdo->prepare("SELECT id,totalAmount,discountAmount,status,isOpenEnded,extras,financialClosureStatus,financialClosureBalance,financialProjectionMode FROM bookings".$where." LIMIT 1000");$stmt->execute($params);$bookings=$stmt->fetchAll()?:[];
        // Satu definisi ledger dipakai oleh kartu booking, checkout, refund, dan Log Kas.
        // Penerimaan = seluruh income booking. Pengurang = refund resmi atau pembalik audit booking_charge yang eksplisit.
        $linkedRemainderPaid=$pdo->prepare("SELECT COALESCE(SUM(GREATEST(
                t.amount-COALESCE((SELECT SUM(a.amount) FROM transaction_allocations a WHERE a.transaction_id=t.id AND a.status='active'),0),0
            )),0)
            FROM transactions t
            WHERE t.bookingId=? AND t.type='income'
              AND NOT EXISTS (
                  SELECT 1 FROM transaction_allocations workflow_link
                  WHERE workflow_link.transaction_id=t.id
                    AND workflow_link.transaction_booking_link_added=1
              )");
        $allocatedPaid=$pdo->prepare("SELECT COALESCE(SUM(a.amount),0)
            FROM transaction_allocations a
            JOIN transactions t ON t.id=a.transaction_id
            WHERE a.booking_id=? AND a.status='active' AND t.type='income'");
        $refundReductionCondition=tamasyaBookingReductionSql();
        $refundStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions
            WHERE bookingId=? AND {$refundReductionCondition}");
        $upd=$pdo->prepare("UPDATE bookings SET roomCharge=?,extraCharge=?,amountPaid=?,balanceDue=?,refundAmount=?,paymentStatus=?,financialClosureStatus=?,financialClosureBalance=? WHERE id=?");
        foreach($bookings as $b){
            // Dataset produksi lama dapat memiliki status lunas yang sah tetapi receipt historisnya
            // belum ditautkan satu-per-satu ke booking. Jangan mengubah snapshot tersebut menjadi
            // unpaid hanya karena relasi ledger historis belum lengkap. Mutasi live tetap memakai
            // mode live_ledger dan dihitung dari transaksi kanonis.
            if (!$forceLegacySnapshot && strtolower((string)($b['financialProjectionMode'] ?? 'live_ledger')) === 'legacy_snapshot') continue;
            $linkedRemainderPaid->execute([$b['id']]);
            $allocatedPaid->execute([$b['id']]);
            $refundStmt->execute([$b['id']]);
            $total=max(0,(float)$b['totalAmount']-(float)($b['discountAmount']??0));
            $extras=max(0,tamasyaBookingServiceExtrasTotal($b['extras']??null));
            $received=max(0,(float)$linkedRemainderPaid->fetchColumn()+(float)$allocatedPaid->fetchColumn());
            $refund=max(0,(float)$refundStmt->fetchColumn());
            $net=max(0,$received-$refund);
            $status=strtolower((string)($b['status']??''));
            $cancelled=$status==='cancelled';
            $openEnded=(int)($b['isOpenEnded']??0)===1 && in_array($status,['reserved','active'],true);
            $room=$cancelled?0:max(0,$total-$extras);
            $extrasForBooking=$cancelled?0:min($extras,$total);
            $balance=$cancelled?0:max(0,$total-$net);
            if($cancelled){
                $paymentStatus=$net<=0.01?'unpaid':'partial';
            }elseif($openEnded){
                $paymentStatus=$net>0.01?'partial':'unpaid';
            }elseif($total>0 && $net>=$total-0.01){
                $paymentStatus='paid';
            }elseif($net>0.01){
                $paymentStatus='partial';
            }else{
                $paymentStatus='unpaid';
            }
            $closureStatus=strtolower((string)($b['financialClosureStatus']??'not_required'));
            $closureBalance=max(0.0,(float)($b['financialClosureBalance']??0));
            if($status==='completed' && $closureStatus==='pending'){
                $closureBalance=$balance;
                if($balance<=0.01){
                    $closureStatus='settled';
                    $closureBalance=0.0;
                    $paymentStatus='paid';
                }
            }
            $upd->execute([$room,$extrasForBooking,$received,$balance,$refund,$paymentStatus,$closureStatus,$closureBalance,$b['id']]);
        }
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] booking financial projection failed', $e));
        if($strict) throw $e;
    }
}

/** Ringkasan state turunan Pusat Operasional untuk audit refresh. */
function operationsDerivedStateSnapshot(PDO $pdo): array {
    $scalar=static function(PDO $pdo,string $sql){try{return (int)$pdo->query($sql)->fetchColumn();}catch(Throwable $e){return -1;}};
    return [
        'alerts'=>$scalar($pdo,"SELECT COUNT(*) FROM system_alerts"),
        'openAlerts'=>$scalar($pdo,"SELECT COUNT(*) FROM system_alerts WHERE acknowledged_at IS NULL"),
        'journalEntries'=>$scalar($pdo,"SELECT COUNT(*) FROM journal_entries"),
        'journalLines'=>$scalar($pdo,"SELECT COUNT(*) FROM journal_lines"),
        'activeBookings'=>$scalar($pdo,"SELECT COUNT(*) FROM bookings WHERE status='active'"),
        'lateBookings'=>$scalar($pdo,"SELECT COUNT(*) FROM bookings WHERE status='active' AND lateCheckoutStatus IN ('grace','overdue')"),
    ];
}

/** Source line 1648: getOperationsCenterData */
/**
 * Project live cash totals onto open shifts without mutating the ledger.
 * Closed shifts keep their stored, audited close values.
 */
function projectOpenShiftFinancials(array $shiftSessions, array $shiftTransactionTotals): array {
    $totalsByShift=[];
    foreach($shiftTransactionTotals as $total){
        $shiftId=trim((string)($total['shiftSessionId']??''));
        if($shiftId==='')continue;
        $totalsByShift[$shiftId]=[
            'cash_income'=>(float)($total['cash_income']??0),
            'cash_expense'=>(float)($total['cash_expense']??0),
        ];
    }
    foreach($shiftSessions as &$shift){
        if(strtolower(trim((string)($shift['status']??'')))!=='open')continue;
        $shiftId=trim((string)($shift['id']??''));
        $totals=$totalsByShift[$shiftId]??['cash_income'=>0.0,'cash_expense'=>0.0];
        $opening=(float)($shift['opening_cash']??0);
        $shift['cash_income']=$totals['cash_income'];
        $shift['cash_expense']=$totals['cash_expense'];
        $shift['expected_cash']=$opening+$totals['cash_income']-$totals['cash_expense'];
    }
    unset($shift);
    return $shiftSessions;
}


/**
 * Dataset arsip berdasarkan rentang tanggal. Endpoint ini sengaja terpisah dari
 * Pusat Operasional agar dashboard tetap ringan sementara ekspor/cetak satu
 * tahun tidak diam-diam kehilangan baris karena LIMIT dashboard.
 *
 * Tidak ada data rahasia (IP, device fingerprint, old/new audit payload, token,
 * KTP) yang dikirim. Batas per dataset bersifat fail-visible: jika terlampaui,
 * meta.truncatedDatasets akan berisi nama dataset dan UI wajib memperingatkan
 * pengguna untuk memperkecil rentang tanggal.
 */
function getArchiveRangeData($pdo, $user, string $fromDate, string $toDate): array {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database arsip tidak tersedia.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        throw new InvalidArgumentException('Rentang tanggal arsip tidak valid.');
    }
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
    if (!$from || !$to || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate || $to < $from) throw new InvalidArgumentException('Rentang tanggal arsip tidak valid.');
    if ((int)$from->diff($to)->days > 400) throw new InvalidArgumentException('Rentang arsip maksimal 400 hari per ekspor. Pecah periode agar file tetap lengkap dan dapat diverifikasi.');

    $fromTs=$from->format('Y-m-d').' 00:00:00';
    $toExclusive=$to->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $fromPeriod=$from->format('Y-m');
    $toPeriod=$to->format('Y-m');
    $cap=25000;
    $truncated=[];
    $failed=[];
    $fetchLimited=function(string $name,string $sql,array $params=[]) use($pdo,$cap,&$truncated,&$failed): array {
        try {
            $stmt=$pdo->prepare($sql.' LIMIT '.($cap+1));
            $stmt->execute($params);
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(count($rows)>$cap){$truncated[]=$name;$rows=array_slice($rows,0,$cap);}
            return $rows;
        } catch(Throwable $e) {
            $failed[]=$name;
            error_log('[archive-data]['.$name.'] '.clientExceptionMessage('query failed',$e));
            return [];
        }
    };

    $auditLogs=$fetchLimited('auditLogs',"SELECT id,staff_id,staff_name,source,action,entity_type,entity_id,outcome,request_id,operation_id,node_id,cluster_id,property_id,created_at FROM audit_logs WHERE created_at>=? AND created_at<? ORDER BY created_at DESC",[$fromTs,$toExclusive]);
    $reconciliationItems=$fetchLimited('reconciliationItems',"SELECT * FROM reconciliation_items WHERE (transaction_date>=? AND transaction_date<=?) OR (transaction_date IS NULL AND created_at>=? AND created_at<?) ORDER BY COALESCE(transaction_date,DATE(created_at)) DESC,created_at DESC",[$fromDate,$toDate,$fromTs,$toExclusive]);
    $housekeepingTasks=$fetchLimited('housekeepingTasks',"SELECT * FROM housekeeping_tasks WHERE COALESCE(completed_at,updated_at,created_at)>=? AND COALESCE(completed_at,updated_at,created_at)<? ORDER BY COALESCE(completed_at,updated_at,created_at) DESC",[$fromTs,$toExclusive]);
    $vacancyReports=$fetchLimited('vacancyReports',"SELECT rvr.* FROM room_vacancy_reports rvr WHERE COALESCE(rvr.reported_at,rvr.observed_at)>=? AND COALESCE(rvr.reported_at,rvr.observed_at)<? ORDER BY COALESCE(rvr.reported_at,rvr.observed_at) DESC",[$fromTs,$toExclusive]);
    $maintenanceTickets=$fetchLimited('maintenanceTickets',"SELECT * FROM maintenance_tickets WHERE COALESCE(completed_at,updated_at,created_at)>=? AND COALESCE(completed_at,updated_at,created_at)<? ORDER BY COALESCE(completed_at,updated_at,created_at) DESC",[$fromTs,$toExclusive]);
    $guestServiceRequests=$fetchLimited('guestServiceRequests',"SELECT * FROM guest_service_requests WHERE COALESCE(fulfilled_at,cancelled_at,updated_at,created_at)>=? AND COALESCE(fulfilled_at,cancelled_at,updated_at,created_at)<? ORDER BY COALESCE(fulfilled_at,cancelled_at,updated_at,created_at) DESC",[$fromTs,$toExclusive]);
    $backupRuns=$fetchLimited('backupRuns',"SELECT * FROM backup_runs WHERE created_at>=? AND created_at<? ORDER BY created_at DESC",[$fromTs,$toExclusive]);
    $nightAuditRuns=$fetchLimited('nightAuditRuns',"SELECT * FROM night_audit_runs WHERE started_at>=? AND started_at<? ORDER BY started_at DESC",[$fromTs,$toExclusive]);
    $nightAuditItems=[];
    if($nightAuditRuns){
        $ids=array_values(array_filter(array_map(static fn($r)=>trim((string)($r['id']??'')),$nightAuditRuns)));
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$pdo->prepare("SELECT * FROM night_audit_items WHERE audit_id IN ($ph) ORDER BY audit_id,CAST(room_number AS UNSIGNED),room_number");
            $stmt->execute($chunk);
            $nightAuditItems=array_merge($nightAuditItems,$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
            if(count($nightAuditItems)>$cap){$truncated[]='nightAuditItems';$nightAuditItems=array_slice($nightAuditItems,0,$cap);break;}
        }
    }
    $journalEntries=$fetchLimited('journalEntries',"SELECT * FROM journal_entries WHERE entry_date>=? AND entry_date<=? ORDER BY entry_date DESC,id DESC",[$fromDate,$toDate]);
    $journalLines=[];
    if($journalEntries){
        $ids=array_values(array_filter(array_map(static fn($r)=>trim((string)($r['id']??'')),$journalEntries)));
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$pdo->prepare("SELECT * FROM journal_lines WHERE journal_entry_id IN ($ph) ORDER BY journal_entry_id,id");
            $stmt->execute($chunk);
            $journalLines=array_merge($journalLines,$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
            if(count($journalLines)>$cap){$truncated[]='journalLines';$journalLines=array_slice($journalLines,0,$cap);break;}
        }
    }
    $reportingPeriods=$fetchLimited('reportingPeriods',"SELECT p.*,(SELECT COUNT(*) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status IN ('pending_review','pending_approval','evidence_required')) AS pending_adjustments,(SELECT COALESCE(SUM(a.cash_delta),0) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status<>'rejected') AS adjustment_cash_delta,(SELECT COALESCE(SUM(a.tax_delta),0) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status<>'rejected') AS adjustment_tax_delta FROM financial_reporting_periods p WHERE p.period_key>=? AND p.period_key<=? ORDER BY p.period_key DESC",[$fromPeriod,$toPeriod]);
    $historicalAdjustments=$fetchLimited('historicalAdjustments',"SELECT id,operation_id,transaction_id,period_key,impact_status,cash_delta,tax_base_delta,tax_delta,requires_tax_amendment,review_status,reason,source_type,source_reference,reviewed_by,reviewed_at,review_note,created_by,created_by_name,created_at FROM historical_backfill_adjustments WHERE period_key>=? AND period_key<=? ORDER BY period_key DESC,created_at DESC",[$fromPeriod,$toPeriod]);

    $role=strtolower((string)($user['role']??''));
    if($role==='finance'){
        // Backup fisik dan detail operasional lapangan bukan bagian akses Finance.
        $backupRuns=[];$housekeepingTasks=[];$vacancyReports=[];$maintenanceTickets=[];$guestServiceRequests=[];$nightAuditRuns=[];$nightAuditItems=[];
    }
    if($role==='manager'){
        // Riwayat file backup tetap Admin-only seperti projection Pusat Operasional.
        $backupRuns=[];
    }

    return compact('auditLogs','reconciliationItems','housekeepingTasks','vacancyReports','maintenanceTickets','guestServiceRequests','backupRuns','nightAuditRuns','nightAuditItems','journalEntries','journalLines','reportingPeriods','historicalAdjustments') + [
        'meta'=>[
            'from'=>$fromDate,'to'=>$toDate,'rowCapPerDataset'=>$cap,
            'truncatedDatasets'=>array_values(array_unique($truncated)),
            'failedDatasets'=>array_values(array_unique($failed)),
            'complete'=>count($truncated)===0&&count($failed)===0,
            'roleScope'=>$role,
            'generatedAt'=>date(DATE_ATOM),
        ],
        'serverTime'=>date(DATE_ATOM),
    ];
}

function getOperationsCenterData($pdo) {
    $startedAt = microtime(true);
    $failedDatasets=[];
    $fetch = function($sql,$dataset='unknown') use ($pdo,&$failedDatasets) {
        try { return $pdo->query($sql)->fetchAll() ?: []; }
        catch (Throwable $e) { $failedDatasets[]=$dataset; error_log(clientExceptionMessage('[operations-center] '.$dataset.' query failed',$e)); return []; }
    };
    $auditLogs = $fetch("SELECT id,staff_id,staff_name,source,action,entity_type,entity_id,outcome,request_id,operation_id,node_id,cluster_id,property_id,created_at FROM audit_logs ORDER BY created_at DESC LIMIT 500",'auditLogs');
    $shiftSessions = $fetch("SELECT * FROM shift_sessions ORDER BY opened_at DESC LIMIT 150",'shiftSessions');
    // Preview and closure share the exact settlement calculator. Fetch only open
    // sessions, so closed historical archives are not scanned on every refresh.
    $shiftTransactions=$fetch("SELECT t.* FROM transactions t JOIN shift_sessions s ON s.id=t.shiftSessionId AND s.status='open'",'shiftTransactionTotals');
    $groupedShiftTransactions=[];
    foreach($shiftTransactions as $tx)$groupedShiftTransactions[(string)$tx['shiftSessionId']][]=$tx;
    $shiftTransactionTotals=[];
    foreach($groupedShiftTransactions as $shiftId=>$rows){
        $totals=tamasyaShiftSettlementTotals($rows);
        $shiftTransactionTotals[]=['shiftSessionId'=>$shiftId,'cash_income'=>$totals['cashInc'],'cash_expense'=>$totals['cashExp']];
    }
    $shiftSessions = projectOpenShiftFinancials($shiftSessions,$shiftTransactionTotals);
    $approvals = $fetch("SELECT * FROM approval_requests ORDER BY created_at DESC LIMIT 200",'approvals');
    $sessions = $fetch("SELECT us.id,us.staff_id,s.name AS staff_name,s.username,us.device_id,us.device_name,us.user_agent,us.ip_address,us.last_activity,us.expires_at,us.revoked_at,us.created_at FROM user_sessions us LEFT JOIN staff s ON s.id=us.staff_id ORDER BY us.last_activity DESC LIMIT 250",'sessions');
    $syncDevices = $fetch("SELECT sd.*,s.name AS staff_name FROM sync_devices sd LEFT JOIN staff s ON s.id=sd.staff_id ORDER BY sd.last_seen DESC LIMIT 250",'syncDevices');
    $reconciliationItems = $fetch("SELECT * FROM reconciliation_items ORDER BY created_at DESC LIMIT 300",'reconciliationItems');
    $taxRules = $fetch("SELECT * FROM tax_rules ORDER BY priority DESC, created_at DESC",'taxRules');
    $housekeepingTasks = $fetch("SELECT * FROM housekeeping_tasks ORDER BY FIELD(priority,'urgent','high','normal','low'), created_at DESC LIMIT 300",'housekeepingTasks');
    $vacancyReports = $fetch("SELECT rvr.*,b.status AS booking_status,b.guestName,b.paymentStatus,b.totalAmount FROM room_vacancy_reports rvr LEFT JOIN bookings b ON b.id=rvr.booking_id ORDER BY FIELD(rvr.status,'pending','verified','rejected','checkout_completed'),rvr.reported_at DESC LIMIT 500",'vacancyReports');
    $maintenanceTickets = $fetch("SELECT * FROM maintenance_tickets ORDER BY FIELD(priority,'urgent','high','normal','low'), created_at DESC LIMIT 300",'maintenanceTickets');
    $facilityTypes = $fetch("SELECT * FROM facility_types ORDER BY active DESC,name LIMIT 200",'facilityTypes');
    $facilities = $fetch("SELECT f.*,t.code AS type_code,t.name AS type_name,t.capacity_model,t.pricing_model FROM facilities f LEFT JOIN facility_types t ON t.id=f.facility_type_id ORDER BY f.active DESC,f.name LIMIT 300",'facilities');
    $facilityBookings = $fetch("SELECT fb.*,f.name AS facility_name FROM facility_bookings fb LEFT JOIN facilities f ON f.id=fb.facility_id ORDER BY fb.use_date DESC,fb.start_time LIMIT 300",'facilityBookings');
    $lostFoundItems = $fetch("SELECT * FROM lost_found_items ORDER BY FIELD(custody_status,'found','secured','guest_notified','returned','disposed','donated','authority'), found_at DESC LIMIT 400",'lostFoundItems');
    // R7 R4 compatibility bridge: legacy open Lost & Found alerts must not remain
    // invisible merely because they predate the canonical lost_found_items row.
    // Projection exposes them as actionable `found` cases; the secure command
    // materializes the canonical domain row atomically before changing custody.
    try {
        $legacyLostFoundStmt=$pdo->query("SELECT sa.id,sa.source,sa.title,sa.message,sa.created_at FROM system_alerts sa LEFT JOIN lost_found_items lf ON lf.alert_id=sa.id WHERE sa.acknowledged_at IS NULL AND sa.source LIKE 'lost-found:%' AND lf.id IS NULL ORDER BY sa.created_at DESC LIMIT 400");
        foreach($legacyLostFoundStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $legacyLostFound){
            $source=(string)($legacyLostFound['source']??'');
            $roomNumber=trim(substr($source,strlen('lost-found:')));
            if($roomNumber==='')continue;
            $message=trim((string)($legacyLostFound['message']??''));
            $title=trim((string)($legacyLostFound['title']??''));
            $description=$message!==''?$message:($title!==''?$title:'Barang tertinggal legacy; detail perlu dikonfirmasi saat pengamanan.');
            if(tamasyaStringLength($description)>1000)$description=substr($description,0,1000);
            $lostFoundItems[]=[
                'id'=>(string)$legacyLostFound['id'],
                'alert_id'=>(string)$legacyLostFound['id'],
                'booking_id'=>null,
                'room_number'=>$roomNumber,
                'item_description'=>$description,
                'source_type'=>'legacy_alert_bridge',
                'source_id'=>(string)$legacyLostFound['id'],
                'custody_status'=>'found',
                'found_at'=>(string)($legacyLostFound['created_at']??''),
                'storage_location'=>null,
                'custody_tag'=>null,
                'notes'=>'Data legacy: canonical custody akan dibuat otomatis saat Barang sudah diamankan.',
                'legacy_bridge'=>1,
            ];
        }
    } catch(Throwable $legacyLostFoundError) {
        $failedDatasets[]='legacyLostFoundBridge';
        error_log(clientExceptionMessage('[operations-center] legacy Lost & Found bridge failed',$legacyLostFoundError));
    }
    $maintenanceCancellationReviews = $fetch("SELECT * FROM maintenance_cancellation_reviews ORDER BY FIELD(status,'open','resolved'), created_at DESC LIMIT 300",'maintenanceCancellationReviews');
    $operationalIncidents = $fetch("SELECT * FROM operational_incidents ORDER BY FIELD(status,'open','in_progress','resolved'), FIELD(severity,'emergency','critical','warning','info'), reported_at DESC LIMIT 500",'operationalIncidents');
    $guestServiceRequests = $fetch("SELECT * FROM guest_service_requests ORDER BY FIELD(status,'open','assigned','in_progress','fulfilled','cancelled'), FIELD(priority,'urgent','high','normal'), created_at DESC LIMIT 500",'guestServiceRequests');
    $roomOperationalHolds = $fetch("SELECT * FROM room_operational_holds ORDER BY FIELD(status,'open','released'), created_at DESC LIMIT 300",'roomOperationalHolds');
    $operationalEntityLinks = $fetch("SELECT * FROM operational_entity_links ORDER BY created_at DESC LIMIT 600",'operationalEntityLinks');
    $guestProfiles = $fetch("SELECT gp.*,
        (SELECT COUNT(*) FROM bookings b WHERE (gp.phone<>'' AND b.guestPhone=gp.phone) OR (gp.email<>'' AND b.guestEmail=gp.email)) AS visit_count,
        (SELECT COALESCE(SUM(b.totalAmount),0) FROM bookings b WHERE (gp.phone<>'' AND b.guestPhone=gp.phone) OR (gp.email<>'' AND b.guestEmail=gp.email)) AS total_spent
        FROM guest_profiles gp ORDER BY gp.updated_at DESC LIMIT 300",'guestProfiles');
    $systemAlerts = $fetch("SELECT * FROM system_alerts ORDER BY created_at DESC LIMIT 200",'systemAlerts');
    // R7 R6: system_alerts is a projection of canonical Lost & Found custody,
    // not a second lifecycle authority. Repair stale labels in the API response
    // so pre-hotfix secured/notified cases immediately show their real state.
    if(function_exists('tamasyaLostFoundAlertProjection')&&$systemAlerts&&$lostFoundItems){
        $lostFoundByIdentifier=[];
        foreach($lostFoundItems as $lfItem){
            if(!is_array($lfItem)||!empty($lfItem['legacy_bridge']))continue;
            $lfId=trim((string)($lfItem['id']??''));$lfAlertId=trim((string)($lfItem['alert_id']??''));
            if($lfId!=='')$lostFoundByIdentifier[$lfId]=$lfItem;
            if($lfAlertId!=='')$lostFoundByIdentifier[$lfAlertId]=$lfItem;
        }
        foreach($systemAlerts as &$systemAlert){
            $entityType=strtolower(trim((string)($systemAlert['entity_type']??'')));
            $source=(string)($systemAlert['source']??'');
            if($entityType!=='lost_found_item'&&!str_starts_with($source,'lost-found:')&&!str_starts_with($source,'lost-found-custody:'))continue;
            $entityId=trim((string)($systemAlert['entity_id']??''));$alertId=trim((string)($systemAlert['id']??''));
            $lfItem=($entityId!==''&&isset($lostFoundByIdentifier[$entityId]))?$lostFoundByIdentifier[$entityId]:(($alertId!==''&&isset($lostFoundByIdentifier[$alertId]))?$lostFoundByIdentifier[$alertId]:null);
            if(!$lfItem)continue;
            $projection=tamasyaLostFoundAlertProjection($lfItem);
            $systemAlert['source']=$projection['source'];$systemAlert['title']=$projection['title'];$systemAlert['message']=$projection['message'];
            $systemAlert['entity_type']='lost_found_item';$systemAlert['entity_id']=(string)$lfItem['id'];
        }
        unset($systemAlert);
    }
    $backupRuns = $fetch("SELECT * FROM backup_runs ORDER BY created_at DESC LIMIT 100",'backupRuns');
    $roomAccessControls = $fetch("SELECT rac.*,r.type,r.floor,r.status AS room_status,b.id AS active_booking_id,b.guestName,b.checkoutDueAt,b.lateCheckoutStatus,b.keyControlStatus FROM room_access_control rac JOIN rooms r ON r.number=rac.room_number LEFT JOIN bookings b ON b.roomNumber=rac.room_number AND b.status='active' ORDER BY CAST(rac.room_number AS UNSIGNED),rac.room_number",'roomAccessControls');
    if($roomAccessControls && function_exists('tamasyaRoomOperationalStatusMap')){
        $accessRoomNumbers=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['room_number']??'')),$roomAccessControls),static fn($n)=>$n!==''));
        $accessStatusMap=tamasyaRoomOperationalStatusMap($pdo,$accessRoomNumbers);
        foreach($roomAccessControls as &$accessRow){
            $number=trim((string)($accessRow['room_number']??''));
            $stored=strtolower(trim((string)($accessRow['room_status']??'')));
            $derived=$accessStatusMap[$number]??$stored;
            $accessRow['stored_room_status']=$stored;
            $accessRow['operational_room_status']=$derived;
            $accessRow['room_status']=$derived; // compatibility field now carries canonical status
            $accessRow['room_state_mismatch']=$stored!==''&&$stored!==$derived;
        }
        unset($accessRow);
    }
    $roomKeyEvents = $fetch("SELECT * FROM room_key_events ORDER BY created_at DESC LIMIT 500",'roomKeyEvents');
    $smartLockJobs = $fetch("SELECT id,room_number,booking_id,action,provider,device_id,status,attempts,last_error,created_at,updated_at,completed_at FROM smart_lock_jobs ORDER BY created_at DESC LIMIT 300",'smartLockJobs');
    $nightAuditRuns = $fetch("SELECT * FROM night_audit_runs ORDER BY started_at DESC LIMIT 100",'nightAuditRuns');
    $nightAuditItems = $fetch("SELECT * FROM night_audit_items WHERE audit_id IN (SELECT id FROM night_audit_runs WHERE status='open') OR audit_id IN (SELECT recent.id FROM (SELECT id FROM night_audit_runs ORDER BY started_at DESC LIMIT 20) recent) ORDER BY audit_id,CAST(room_number AS UNSIGNED),room_number",'nightAuditItems');
    $operationalSettings = getOperationalSettings($pdo);
    try { $approvalPolicy=$pdo->query("SELECT approval_required_sensitive AS required,approval_threshold AS threshold FROM config WHERE id='system_default' LIMIT 1")->fetch() ?: ['required'=>1,'threshold'=>0]; } catch(Throwable $e) { $failedDatasets[]='approvalPolicy'; error_log('[operations-center] approvalPolicy: '.$e->getMessage()); $approvalPolicy=['required'=>1,'threshold'=>0,'loadError'=>true]; }
    $journalEntries = $fetch("SELECT * FROM journal_entries ORDER BY entry_date DESC, id DESC LIMIT 500",'journalEntries');
    // MariaDB compatibility: LIMIT inside an IN-subquery is unsupported on common
    // MariaDB releases. Reuse the proven archive projection pattern instead:
    // fetch the bounded entry IDs first, then load matching lines in chunks.
    $journalLines = [];
    if ($journalEntries) {
        try {
            $journalEntryIds = array_values(array_filter(array_map(
                static fn($row) => trim((string)($row['id'] ?? '')),
                $journalEntries
            )));
            foreach (array_chunk($journalEntryIds, 500) as $chunk) {
                if (!$chunk) continue;
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare("SELECT * FROM journal_lines WHERE journal_entry_id IN ($ph) ORDER BY journal_entry_id,id");
                $stmt->execute($chunk);
                $journalLines = array_merge($journalLines, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
        } catch (Throwable $e) {
            $failedDatasets[]='journalLines';
            error_log(clientExceptionMessage('[operations-center] journalLines query failed',$e));
            $journalLines=[];
        }
    }
    try { $financialIntegrity=tamasyaFinancialIntegrityMetrics($pdo); }
    catch(Throwable $e) { $financialIntegrity=['healthy'=>false,'error'=>clientExceptionMessage('Pemeriksaan integritas keuangan gagal',$e)]; }
    $metrics = ['databaseConnected'=>true,'responseMs'=>0,'databaseSize'=>'—','audit24h'=>0,'serverRevision'=>0,'telegramWebhookActive'=>false,'openConflicts'=>0,'roomMasterCount'=>0];
    try {
        $metrics['audit24h'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetchColumn();
        $metrics['serverRevision'] = (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn();
        $metrics['schemaVersion'] = (string)($pdo->query("SELECT version FROM schema_migrations ORDER BY applied_at DESC,version DESC LIMIT 1")->fetchColumn() ?: 'legacy');
        $metrics['telegramWebhookActive'] = (bool)$pdo->query("SELECT telegram_webhook_active FROM config WHERE id='system_default'")->fetchColumn();
        $metrics['openConflicts'] = (int)$pdo->query("SELECT COALESCE(SUM(conflict_count),0) FROM sync_devices WHERE status<>'disabled'")->fetchColumn();
        $metrics['roomMasterCount'] = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $stmtSize = $pdo->prepare("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE table_schema=?");
        $stmtSize->execute([$dbName]);
        $bytes = (float)$stmtSize->fetchColumn();
        $metrics['databaseSize'] = $bytes > 0 ? round($bytes/1048576,2).' MB' : '0 MB';
    } catch (Throwable $e) { $failedDatasets[]='metrics'; error_log('[operations-center] metrics: '.$e->getMessage()); $metrics['databaseConnected']=false; }
    $metrics['responseMs'] = (int)round((microtime(true)-$startedAt)*1000);
    $failedDatasets=array_values(array_unique($failedDatasets));
    return compact('auditLogs','shiftSessions','approvals','sessions','syncDevices','reconciliationItems','taxRules','housekeepingTasks','vacancyReports','maintenanceTickets','facilityTypes','facilities','facilityBookings','lostFoundItems','maintenanceCancellationReviews','operationalIncidents','guestServiceRequests','roomOperationalHolds','operationalEntityLinks','guestProfiles','systemAlerts','backupRuns','journalEntries','journalLines','financialIntegrity','approvalPolicy','metrics','roomAccessControls','roomKeyEvents','smartLockJobs','nightAuditRuns','nightAuditItems','operationalSettings') + [
        'dataHealth'=>['complete'=>count($failedDatasets)===0,'failedDatasets'=>$failedDatasets],
        'serverTime'=>date(DATE_ATOM)
    ];
}

/** Project Pusat Operasional tanpa akses database agar batas data per-role dapat diuji langsung. */
function projectOperationsCenterDataForUser(array $data, $user): array {
    $role=strtolower((string)($user['role']??''));
    $allOperationalRoles=['admin','manager','receptionist','finance','koki','tukang_kebun','cleaning_service','keamanan','lain_lain'];
    $canOperationsTab=hasDesktopTabAccess($user,'operations',$allOperationalRoles);
    $canReportTab=hasDesktopTabAccess($user,'report',['admin','manager','finance']);

    // operations-center dipakai bersama oleh Pusat Operasional dan Pusat Arsip.
    // Bila menu Operasional dimatikan, hanya data pelaporan yang memang diizinkan
    // yang dipertahankan. Menyembunyikan menu React tidak pernah menjadi kontrol akses.
    $applyDesktopProjection=static function(array $projected) use ($canOperationsTab,$canReportTab): array {
        if ($canOperationsTab) return $projected;
        $alwaysKeep=['metrics'=>true,'serverTime'=>true];
        $reportKeep=[
            'auditLogs'=>true,'shiftSessions'=>true,'reconciliationItems'=>true,
            'taxRules'=>true,'backupRuns'=>true,
            'journalEntries'=>true,'journalLines'=>true,'financialIntegrity'=>true,'nightAuditRuns'=>true,
            'nightAuditItems'=>true,'approvalPolicy'=>true,
        ];
        foreach ($projected as $key=>&$value) {
            if (isset($alwaysKeep[$key])) continue;
            if ($canReportTab && isset($reportKeep[$key])) continue;
            if (is_array($value)) $value=[];
            elseif (is_bool($value)) $value=false;
            elseif (is_numeric($value)) $value=0;
            else $value=null;
        }
        unset($value);
        return $projected;
    };

    if (tamasyaIsOwnerRole($user)) {
        // Read visibility does not disclose a credential for operating the lock bridge.
        if (isset($data['operationalSettings']['smart_lock_bridge_token'])) $data['operationalSettings']['smart_lock_bridge_token']='[TERSEMBUNYI]';
        return $applyDesktopProjection($data);
    }

    $canRoomAccess=hasCapability($user,'manage_room_access',['admin','manager','receptionist']);
    $canNightAudit=function_exists('canPerformNightAuditForUser') ? canPerformNightAuditForUser($user) : ($role==='finance' || hasCapability($user,'perform_night_audit',['admin','manager','finance','receptionist','keamanan']));
    $canHousekeeping=hasCapability($user,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance']);
    $canManageOps=hasCapability($user,'manage_operational_settings',['admin','manager']);
    $canViewAudit=hasCapability($user,'view_audit_log',['admin','manager','finance']);
    $canManageTax=hasCapability($user,'manage_tax_rules',['admin','manager']);
    $canViewGuestIdentity=hasCapability($user,'view_guest_identity',['admin','manager','receptionist','keamanan']);
    $canSeeMaintenanceCost=in_array($role,['admin','manager','finance'],true);

    if (!$canHousekeeping) $data['housekeepingTasks']=[];
    if (!$canViewGuestIdentity) {
        $data['guestProfiles']=[];
        if (isset($data['roomAccessControls']) && is_array($data['roomAccessControls'])) {
            foreach ($data['roomAccessControls'] as &$row) {
                unset($row['guestName'],$row['active_booking_id']);
            }
            unset($row);
        }
        if (isset($data['vacancyReports']) && is_array($data['vacancyReports'])) {
            foreach ($data['vacancyReports'] as &$row) {
                unset($row['guestName'],$row['guest_phone'],$row['guest_email'],$row['identity_number']);
            }
            unset($row);
        }
    }
    if (!$canSeeMaintenanceCost) {
        if (isset($data['maintenanceTickets']) && is_array($data['maintenanceTickets'])) {
            foreach ($data['maintenanceTickets'] as &$ticket) {
                unset($ticket['estimated_cost'],$ticket['actual_cost'],$ticket['transaction_id'],$ticket['approval_request_id']);
            }
            unset($ticket);
        }
    }

    // Dedicated operational domains have narrower exposure than generic alerts.
    if(!in_array($role,['admin','manager','receptionist'],true)){
        $staffId=(string)($user['id']??'');
        $data['lostFoundItems']=array_values(array_filter($data['lostFoundItems']??[],static fn($row)=>$staffId!==''&&(string)($row['found_by']??'')===$staffId));
    }
    if(!in_array($role,['admin','manager'],true)){
        $data['maintenanceCancellationReviews']=[];
        $data['operationalEntityLinks']=[];
    }
    if(!in_array($role,['admin','manager','receptionist','keamanan'],true)){
        $staffId=(string)($user['id']??'');
        $data['operationalIncidents']=array_values(array_filter($data['operationalIncidents']??[],static fn($row)=>$staffId!==''&&(string)($row['reported_by']??'')===$staffId));
        $data['roomOperationalHolds']=[];
    }

    if ($role==='admin') return $applyDesktopProjection($data);

    if ($role==='manager') {
        // Manager mengawasi operasional, tetapi riwayat backup tetap Admin-only.
        $data['backupRuns']=[];
        return $applyDesktopProjection($data);
    }

    if ($role==='finance') {
        // Finance dapat menjadi pemilik shift kas malam. Data Night Audit tetap
        // tersedia agar penutupan shift tidak mengalami deadlock, sedangkan data
        // akses kamar sensitif lainnya tetap dibatasi.
        foreach (['sessions','syncDevices','housekeepingTasks','maintenanceTickets','lostFoundItems','maintenanceCancellationReviews','operationalIncidents','guestServiceRequests','roomOperationalHolds','operationalEntityLinks','guestProfiles','backupRuns','smartLockJobs','roomAccessControls','roomKeyEvents'] as $key) $data[$key]=[];
        if(!$canNightAudit){$data['nightAuditRuns']=[];$data['nightAuditItems']=[];}
        $staffId=(string)($user['id']??'');
        $data['vacancyReports']=array_values(array_filter($data['vacancyReports']??[],static fn($row)=>(string)($row['reported_by']??'')===$staffId));
        foreach($data['vacancyReports'] as &$vacancyRow){unset($vacancyRow['guestName'],$vacancyRow['totalAmount'],$vacancyRow['paymentStatus']);}unset($vacancyRow);
        if (isset($data['operationalSettings'])) {
            $data['operationalSettings']=[
                'cash_variance_tolerance'=>$data['operationalSettings']['cash_variance_tolerance']??0,
                'require_open_shift_for_sale'=>$data['operationalSettings']['require_open_shift_for_sale']??1,
            ];
        }
        return $applyDesktopProjection($data);
    }

    // Role lapangan hanya menerima audit miliknya sendiri.
    if (!$canViewAudit) {
        $staffId=(string)($user['id']??'');
        $data['auditLogs']=array_values(array_filter($data['auditLogs']??[],function($row) use ($staffId){
            return $staffId!=='' && (string)($row['staff_id']??'')===$staffId;
        }));
    }
    if (!$canManageTax) $data['taxRules']=[];

    $emptyKeys=['approvals','sessions','syncDevices','reconciliationItems','systemAlerts','backupRuns','journalEntries','journalLines'];
    foreach($emptyKeys as $key)$data[$key]=[];
    if(!$canRoomAccess)$data['smartLockJobs']=[];
    if(!$canRoomAccess && !$canNightAudit){$data['roomAccessControls']=[];$data['roomKeyEvents']=[];}
    if(!$canNightAudit){$data['nightAuditRuns']=[];$data['nightAuditItems']=[];}
    if(!$canManageOps && isset($data['operationalSettings'])){
        $data['operationalSettings']['smart_lock_bridge_url']=null;
        $data['operationalSettings']['smart_lock_bridge_token']='[TERSEMBUNYI]';
    }
    if ($role==='receptionist') {
        $data['shiftSessions']=array_values(array_filter($data['shiftSessions']??[],function($row) use ($user){$id=(string)($user['id']??'');return $id!=='' && ((string)($row['staff_id']??'')===$id || (string)($row['companion_staff_id']??'')===$id);}));
        if (isset($data['operationalSettings'])) $data['operationalSettings']['smart_lock_bridge_token']='[TERSEMBUNYI]';
        return $applyDesktopProjection($data);
    }
    if ($role==='keamanan' || $role==='cleaning_service') {
        $data['shiftSessions']=[];
        if (!$canViewGuestIdentity || $role==='cleaning_service') $data['guestProfiles']=[];
        if (isset($data['operationalSettings'])) $data['operationalSettings']['smart_lock_bridge_token']='[TERSEMBUNYI]';
        $staffId=(string)($user['id']??'');
        $data['vacancyReports']=array_values(array_filter($data['vacancyReports']??[],static fn($row)=>(string)($row['reported_by']??'')===$staffId));
        foreach($data['vacancyReports'] as &$vacancyRow){unset($vacancyRow['guestName'],$vacancyRow['totalAmount'],$vacancyRow['paymentStatus']);}unset($vacancyRow);
        if($role==='cleaning_service'){
            if (isset($data['roomAccessControls']) && is_array($data['roomAccessControls'])) {
                foreach($data['roomAccessControls'] as &$row){unset($row['guestName'],$row['active_booking_id']);}
                unset($row);
            }
            $data['roomKeyEvents']=[];
        }
        return $applyDesktopProjection($data);
    }
    $data['shiftSessions']=[];$data['guestProfiles']=[];
    $staffId=(string)($user['id']??'');
    $data['vacancyReports']=array_values(array_filter($data['vacancyReports']??[],static fn($row)=>(string)($row['reported_by']??'')===$staffId));
    foreach($data['vacancyReports'] as &$vacancyRow){unset($vacancyRow['guestName'],$vacancyRow['totalAmount'],$vacancyRow['paymentStatus']);}unset($vacancyRow);
    if (!$canHousekeeping) $data['housekeepingTasks']=[];
    return $applyDesktopProjection($data);
}

/** Source line 1699: getRoleScopedOperationsData */
function getRoleScopedOperationsData($pdo,$user) {
    return projectOperationsCenterDataForUser(getOperationsCenterData($pdo),$user);
}

/** Source line 1770: requireAuth */
function requireAuth($pdo) {
    if (!$pdo) {
        http_response_code(503);
        echo json_encode([
            "success" => false,
            "error" => "Database tidak tersedia. Konfigurasikan DB_* atau APP_CREDENTIALS_FILE di luar document root; akses recovery tanpa autentikasi dinonaktifkan demi keamanan."
        ]);
        exit();
    }
    // Replay dari server lokal memakai HMAC node, bukan token browser. Event
    // diklaim satu kali di server online agar retry jaringan tidak menggandakan
    // booking, transaksi, shift, atau tindakan housekeeping.
    if (strtolower(tamasyaNodeHeader('X-Tamasya-Node-Mode')) === 'replay') {
        global $action, $rawRequestBody, $input;
        try {
            $verified = tamasyaVerifyNodeRequest($pdo, (string)$action, (string)$rawRequestBody, false);
            $eventId = (string)$verified['eventId'];
            $payloadHash = tamasyaNodePayloadHash((string)$rawRequestBody);
            $actorId = trim((string)($verified['actorId'] ?? ''));
            $operationId = trim((string)($verified['operationId'] ?? ''));
            $originDeviceId = trim((string)($verified['deviceId'] ?? ''));
            $originSessionId = trim((string)($verified['sessionId'] ?? ''));
            $httpMethod = strtoupper((string)($verified['httpMethod'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'POST')));
            $queryHash = trim((string)($verified['queryHash'] ?? ''));

            $stmtReceipt = $pdo->prepare("SELECT * FROM node_sync_receipts WHERE event_id=? LIMIT 1");
            $stmtReceipt->execute([$eventId]);
            $existingReceipt = $stmtReceipt->fetch(PDO::FETCH_ASSOC);
            if ($existingReceipt) {
                $coreMatches = hash_equals((string)$existingReceipt['payload_hash'], $payloadHash)
                    && hash_equals((string)($existingReceipt['origin_node_id'] ?? ''), (string)($verified['nodeId'] ?? ''))
                    && hash_equals((string)($existingReceipt['actor_staff_id'] ?? ''), $actorId)
                    && hash_equals((string)($existingReceipt['action'] ?? ''), (string)$action);
                $operationMatches = $operationId === '' || (
                    hash_equals((string)($existingReceipt['operation_id'] ?? ''), $operationId)
                    && hash_equals((string)($existingReceipt['device_id'] ?? ''), $originDeviceId)
                    && hash_equals((string)($existingReceipt['session_id'] ?? ''), $originSessionId)
                    && hash_equals(strtoupper((string)($existingReceipt['http_method'] ?? '')), $httpMethod)
                    && hash_equals((string)($existingReceipt['query_hash'] ?? ''), $queryHash)
                );
                if (!$coreMatches || !$operationMatches) {
                    http_response_code(409);
                    echo json_encode(['success'=>false,'error'=>'Event/operation sinkronisasi pernah digunakan oleh payload, staf, perangkat, sesi, action, method, atau query berbeda.']);
                    exit;
                }
                $receiptStatus = strtolower((string)($existingReceipt['status'] ?? 'processing'));
                $storedResponse = trim((string)($existingReceipt['response_json'] ?? ''));
                if (in_array($receiptStatus, ['completed','failed','rejected'], true) && $storedResponse !== '') {
                    $storedStatus = (int)($existingReceipt['http_status'] ?? ($receiptStatus === 'completed' ? 200 : 409));
                    if ($storedStatus <= 0) $storedStatus = $receiptStatus === 'completed' ? 200 : 409;
                    http_response_code($storedStatus);
                    header('X-Tamasya-Node-Idempotent-Replay: 1');
                    echo is_array(json_decode($storedResponse, true))
                        ? $storedResponse
                        : json_encode(['success'=>$receiptStatus === 'completed','duplicate'=>true,'message'=>'Event sinkronisasi sudah memiliki hasil terminal.','eventId'=>$eventId]);
                    exit;
                }
                if ($receiptStatus === 'completed') {
                    http_response_code(200);
                    echo json_encode(['success'=>true,'duplicate'=>true,'message'=>'Event sinkronisasi sudah diterapkan sebelumnya.','eventId'=>$eventId]);
                    exit;
                }
                $receiptUpdatedAt = strtotime((string)($existingReceipt['updated_at'] ?? '')) ?: 0;
                if ($receiptStatus === 'processing' && $receiptUpdatedAt > 0 && $receiptUpdatedAt < time() - 600) {
                    $pdo->prepare("UPDATE node_sync_receipts SET status='failed',last_error='Receipt processing melewati 10 menit; wajib rekonsiliasi manual sebelum retry.',updated_at=CURRENT_TIMESTAMP WHERE event_id=? AND status='processing'")
                        ->execute([$eventId]);
                    $receiptStatus = 'failed';
                }
                http_response_code(409);
                echo json_encode([
                    'success'=>false,
                    'error'=>$receiptStatus === 'processing' ? 'Event sinkronisasi masih diproses; agent akan mencoba lagi.' : 'Event sinkronisasi sebelumnya gagal tanpa hasil terminal yang aman dan memerlukan pemeriksaan manual.',
                    'eventId'=>$eventId,'receiptStatus'=>$receiptStatus
                ]);
                exit;
            }

            if ($actorId === '') throw new RuntimeException('Identitas staf sumber tidak tersedia.');
            $stmtActor = $pdo->prepare("SELECT * FROM staff WHERE id=? AND status='active' LIMIT 1");
            $stmtActor->execute([$actorId]);
            $user = $stmtActor->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new RuntimeException('Akun staf sumber tidak aktif atau belum tersedia di server primary.');
            // Receipt browser dan audit harus tetap terikat ke sesi/perangkat asli,
            // bukan sesi teknis node yang meneruskan request.
            $user['session_id'] = $originSessionId !== '' ? $originSessionId : null;
            $user['session_device_id'] = $originDeviceId;

            // Klaim nonce dan receipt node dalam satu transaksi. Metadata lengkap
            // mencegah operation_id yang sama dipakai silang staf/perangkat/sesi.
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO node_sync_nonces (nonce,node_id,used_at,expires_at) VALUES (?,?,CURRENT_TIMESTAMP,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE))")
                    ->execute([$eventId,$verified['nodeId']]);
                $pdo->prepare("INSERT INTO node_sync_receipts
                    (event_id,origin_node_id,actor_staff_id,device_id,session_id,operation_id,action,http_method,query_hash,payload_hash,status,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'processing',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                    ->execute([$eventId,$verified['nodeId'],$actorId,$originDeviceId,$originSessionId !== '' ? $originSessionId : null,$operationId !== '' ? $operationId : null,(string)$action,$httpMethod,$queryHash,$payloadHash]);
                tamasyaFinancialCommit($pdo);
            } catch (Throwable $claimError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $stmtClaim = $pdo->prepare("SELECT * FROM node_sync_receipts WHERE event_id=? LIMIT 1");
                $stmtClaim->execute([$eventId]);
                $claimed = $stmtClaim->fetch(PDO::FETCH_ASSOC);
                if ($claimed && hash_equals((string)$claimed['payload_hash'],$payloadHash)) {
                    http_response_code((string)$claimed['status'] === 'completed' ? 200 : 409);
                    echo json_encode([
                        'success'=>(string)$claimed['status'] === 'completed',
                        'duplicate'=>(string)$claimed['status'] === 'completed',
                        'error'=>(string)$claimed['status'] === 'completed' ? null : 'Event sinkronisasi sedang diproses atau sudah terminal.',
                        'eventId'=>$eventId,
                        'receiptStatus'=>$claimed['status']
                    ]);
                    exit;
                }
                throw new RuntimeException('Event sinkronisasi yang sama sudah diklaim atau gagal dicatat secara atomik.');
            }

            $GLOBALS['tamasya_node_replay_active'] = true;
            $GLOBALS['tamasya_node_replay_event_id'] = $eventId;
            $GLOBALS['tamasya_node_replay_origin'] = $verified['nodeId'];
            $generationEventId = trim((string)($input['_nodeGenerationEventId'] ?? $eventId));
            if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $generationEventId)) $generationEventId = $eventId;
            $GLOBALS['tamasya_node_operation_event_id'] = $generationEventId;
            $GLOBALS['tamasya_node_id_counters'] = [];
            unset($input['_nodeGenerationEventId']);

            // Claim receipt browser juga pada request yang melewati Standby.
            // Tabel ini ikut snapshot, sehingga retry setelah switchover tidak
            // mengeksekusi ulang transaksi yang sudah diproses Primary lama.
            $requestOperationClaim = null;
            if ($operationId !== '') {
                try {
                    $requestOperationClaim = tamasyaClaimRequestOperation($pdo, $user, (string)$action, $httpMethod, (array)$_GET, (array)$input);
                } catch (Throwable $operationClaimError) {
                    $pdo->prepare("UPDATE node_sync_receipts SET status='failed',http_status=503,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                        ->execute([substr('Receipt operation_id gagal diklaim: '.$operationClaimError->getMessage(),0,1000),$eventId]);
                    throw $operationClaimError;
                }
                $operationState = (string)($requestOperationClaim['state'] ?? '');
                if ($operationState === 'terminal') {
                    $row = $requestOperationClaim['row'] ?? [];
                    $storedStatus = (int)($row['http_status'] ?? 200);
                    if ($storedStatus <= 0) $storedStatus = 200;
                    $storedBody = tamasyaReplayDurableReceiptBody((string)($row['response_body'] ?? ''),static fn()=>getRoleScopedHotelData($pdo,$user));
                    $storedBody = $storedBody !== '' ? $storedBody : (json_encode(['success'=>(string)($row['status']??'')==='completed','duplicate'=>true,'operationId'=>$operationId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}');
                    $nodeStatus = (string)($row['status'] ?? '') === 'completed' ? 'completed' : 'failed';
                    $pdo->prepare("UPDATE node_sync_receipts SET status=?,http_status=?,response_json=?,last_error=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                        ->execute([$nodeStatus,$storedStatus,substr($storedBody,0,262144),$nodeStatus === 'completed' ? null : substr((string)($row['error_message'] ?? 'Operation terminal gagal.'),0,1000),$eventId]);
                    http_response_code($storedStatus);
                    header('X-Tamasya-Idempotent-Replay: 1');
                    echo $storedBody;
                    exit;
                }
                if ($operationState === 'mismatch' || in_array($operationState, ['processing','uncertain'], true)) {
                    $message = $operationState === 'mismatch'
                        ? 'operation_id pernah digunakan oleh payload, staf, perangkat, sesi, action, atau method berbeda.'
                        : ($operationState === 'uncertain'
                            ? 'Hasil operation_id tidak pasti; request tidak dieksekusi ulang.'
                            : 'operation_id yang sama masih diproses; request kedua tidak dieksekusi.');
                    $body = json_encode(['success'=>false,'pendingOnlineConfirmation'=>$operationState !== 'mismatch','receiptStatus'=>$operationState,'operationId'=>$operationId,'error'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}';
                    $pdo->prepare("UPDATE node_sync_receipts SET status='failed',http_status=409,response_json=?,last_error=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                        ->execute([substr($body,0,262144),substr($message,0,1000),$eventId]);
                    http_response_code(409);
                    echo $body;
                    exit;
                }
            }

            // Buffer replay output so node receipt and durable browser receipt
            // follow the same HTTP + JSON success contract.
            $requestOperationId = (string)($requestOperationClaim['operationId'] ?? '');
            $requestOperationPayloadHash = (string)($requestOperationClaim['payloadHash'] ?? '');
            ob_start();
            register_shutdown_function(static function() use ($pdo, $eventId, $requestOperationId, $requestOperationPayloadHash, $action): void {
                $status = http_response_code();
                if ($status === false || $status === 0) $status = 200;
                $responseBody = (string)(ob_get_contents() ?: '');
                $fatalError = error_get_last();
                $fatalTypes = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
                $hasFatal = is_array($fatalError) && in_array((int)($fatalError['type'] ?? 0), $fatalTypes, true);
                if ($hasFatal) $status = 500;
                $classification = tamasyaNodeClassifyResponse((int)$status, $responseBody);
                if (!$classification['completed'] && (int)$status >= 200 && (int)$status < 400 && !headers_sent()) {
                    http_response_code((int)$classification['httpStatus']);
                }
                try {
                    $pdo->prepare("UPDATE node_sync_receipts SET status=?,http_status=?,response_json=?,last_error=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                        ->execute([
                            $classification['completed'] ? 'completed' : 'failed',
                            (int)$classification['httpStatus'],
                            substr($responseBody, 0, 262144),
                            $classification['completed'] ? null : substr((string)$classification['message'], 0, 1000),
                            $classification['completed'] ? date('Y-m-d H:i:s') : null,
                            $eventId
                        ]);
                } catch (Throwable $ignored) {}
                if ($requestOperationId !== '' && $requestOperationPayloadHash !== '') {
                    try {
                        tamasyaCompleteRequestOperation($pdo,$requestOperationId,$requestOperationPayloadHash,(int)$status,$responseBody,$classification,$hasFatal ? $fatalError : null,(string)$action);
                    } catch (Throwable $ignored) {}
                }
            });
            return $user;
        } catch (Throwable $e) {
            http_response_code(401);
            echo json_encode(['success'=>false,'error'=>'Autentikasi node gagal: '.clientExceptionMessage('Node sync ditolak',$e)]);
            exit;
        }
    }
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } else {
        if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $authHeader = trim($requestHeaders['Authorization']);
            }
        }
    }
    
    // Fallback for development without authorization header
    if (empty($authHeader) && isset($_GET['action']) && in_array($_GET['action'], ['login', 'verify-2fa'])) {
        return null;
    }

    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        $tokenHash = hash('sha256', $token);
        try {
            $idleCutoff = tamasyaSessionIdleCutoff();
            $stmtSession = $pdo->prepare("SELECT s.*, us.id AS session_id, us.device_id AS session_device_id, us.last_activity AS session_last_activity FROM user_sessions us JOIN staff s ON s.id=us.staff_id WHERE us.token_hash=? AND us.revoked_at IS NULL AND us.expires_at>=NOW() AND us.last_activity>=? AND s.status='active' LIMIT 1");
            $stmtSession->execute([$tokenHash,$idleCutoff]);
            $user = $stmtSession->fetch();
            if ($user) {
                $requestDeviceId = currentDeviceId();
                if ($requestDeviceId === 'unknown-device' || !hash_equals((string)$user['session_device_id'], $requestDeviceId)) {
                    http_response_code(401);
                    echo json_encode(["success" => false, "error" => "Sesi tidak berlaku pada perangkat ini. Silakan login kembali."]);
                    exit;
                }
                // Throttle heartbeat writes to reduce row contention on busy installations.
                $lastActivity = strtotime((string)($user['session_last_activity'] ?? '')) ?: 0;
                if ($lastActivity < time() - 60) {
                    $pdo->prepare("UPDATE user_sessions SET last_activity=CURRENT_TIMESTAMP, ip_address=?, user_agent=? WHERE id=? AND last_activity<?")
                        ->execute([tamasyaClientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500), $user['session_id'], date('Y-m-d H:i:s', time()-60)]);
                }
                return $user;
            }
            // The session row exists but no condition matched. Record which one failed,
            // otherwise a stale token, an expired session and a deactivated staff account
            // are indistinguishable from the outside, and a database fault in this block
            // was previously reported as "please log in again".
            try {
                $probe=$pdo->prepare("SELECT us.revoked_at,us.expires_at,us.last_activity,s.status FROM user_sessions us JOIN staff s ON s.id=us.staff_id WHERE us.token_hash=? LIMIT 1");
                $probe->execute([$tokenHash]);
                $row=$probe->fetch(PDO::FETCH_ASSOC);
                if(!$row){
                    tamasyaRuntimeLog('session_token_unknown',[],null);
                }else{
                    $reason=($row['revoked_at']!==null)?'revoked'
                        :(strtotime((string)$row['expires_at'])<time()?'expired'
                        :(strtotime((string)$row['last_activity'])<strtotime($idleCutoff)?'idle_timeout'
                        :((string)$row['status']!=='active'?'staff_not_active':'unknown')));
                    tamasyaRuntimeLog('session_rejected',['reason'=>$reason],null);
                }
            } catch (Throwable $probeError) {
                tamasyaRuntimeLog('session_probe_failed',[],$probeError);
            }
        } catch (Throwable $e) {
            // Never empty: a database fault here used to masquerade as a login problem.
            tamasyaRuntimeLog('session_lookup_failed',[],$e);
        }
    }
    
    // For telegram webhook, let it pass and do its own auth
    if (isset($_GET['action']) && $_GET['action'] === 'telegram-webhook') {
        return null; 
    }
    
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized. Please login again."]);
    exit;
}

/** Source line 2070: bookingIdentityDataUrl */
function bookingIdentityDataUrl(PDO $pdo, string $storedValue): array {
    $storedValue = trim($storedValue);
    if ($storedValue === '') return ['available'=>false,'dataUrl'=>null,'storage'=>'none'];

    if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\r\n]+)$#i', $storedValue, $match)) {
        $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) {
            throw new RuntimeException('Berkas KTP tidak valid atau melebihi 8 MB.');
        }
        return ['available'=>true,'dataUrl'=>'data:'.strtolower($match[1]).';base64,'.base64_encode($bytes),'storage'=>'database'];
    }

    if (str_starts_with($storedValue, 'telegram_file_id:')) {
        $fileId = trim(substr($storedValue, strlen('telegram_file_id:')));
        if ($fileId === '') throw new RuntimeException('Referensi KTP Telegram tidak valid.');
        $conf = $pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1")->fetch() ?: [];
        $token = trim(decryptStoredSecret($conf['telegram_bot_token'] ?? ''));
        if ($token === '') return ['available'=>true,'dataUrl'=>null,'storage'=>'telegram','reason'=>'Bot token belum tersedia untuk mengambil ulang foto.'];
        $info = telegramApiCall($token, 'getFile', ['file_id'=>$fileId]);
        $path = (string)($info['data']['result']['file_path'] ?? '');
        if (empty($info['ok']) || $path === '' || str_contains($path, '..')) {
            return ['available'=>true,'dataUrl'=>null,'storage'=>'telegram','reason'=>'Foto Telegram belum dapat diambil ulang.'];
        }
        $url = getTelegramApiBaseUrl() . '/file/bot' . $token . '/' . ltrim($path, '/');
        $context = stream_context_create(['http'=>['timeout'=>12,'follow_location'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $bytes = @file_get_contents($url, false, $context, 0, 8 * 1024 * 1024 + 1);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) {
            return ['available'=>true,'dataUrl'=>null,'storage'=>'telegram','reason'=>'Foto Telegram gagal dibaca atau terlalu besar.'];
        }
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $fi ? finfo_buffer($fi, $bytes) : false;
            if ($fi) unset($fi);
            if (in_array($detected, ['image/jpeg','image/png','image/webp'], true)) $mime = $detected;
        }
        return ['available'=>true,'dataUrl'=>'data:'.$mime.';base64,'.base64_encode($bytes),'storage'=>'telegram'];
    }

    $relative = ltrim(str_replace('\\','/',$storedValue), '/');
    if (!str_starts_with($relative, 'uploads/')) {
        throw new RuntimeException('Format penyimpanan KTP lama tidak didukung demi keamanan.');
    }
    $base = realpath(TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
    $file = realpath(TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        return ['available'=>true,'dataUrl'=>null,'storage'=>'file','reason'=>'File KTP tidak ditemukan pada server.'];
    }
    $size = filesize($file);
    if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) throw new RuntimeException('Ukuran file KTP tidak valid.');
    $mime = function_exists('mime_content_type') ? mime_content_type($file) : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('Tipe file KTP tidak didukung.');
    $bytes = file_get_contents($file);
    if ($bytes === false) throw new RuntimeException('File KTP gagal dibaca.');
    return ['available'=>true,'dataUrl'=>'data:'.$mime.';base64,'.base64_encode($bytes),'storage'=>'file'];
}

/** Source line 2133: normalizeBookingIdentityReferenceForStorage */
function normalizeBookingIdentityReferenceForStorage(PDO $pdo, $value, bool $allowMissingSimulationFile = false): ?string {
    if ($value === null) return null;
    $storedValue = trim((string)$value);
    if ($storedValue === '') return null;

    if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\r\n]+)$#i', $storedValue, $match)) {
        $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('Foto KTP tidak valid atau melebihi 8 MB.');
        }
        $detected = strtolower($match[1]);
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi ? finfo_buffer($fi, $bytes) : false;
            if ($fi) unset($fi);
            if ($mime && !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                throw new InvalidArgumentException('Isi foto KTP bukan gambar JPEG, PNG, atau WEBP.');
            }
            if ($mime) $detected = $mime;
        }
        return 'data:'.$detected.';base64,'.base64_encode($bytes);
    }

    if (str_starts_with($storedValue, 'telegram_file_id:')) {
        $fileId = trim(substr($storedValue, strlen('telegram_file_id:')));
        if (!preg_match('/^[A-Za-z0-9_-]{8,512}$/', $fileId)) {
            throw new InvalidArgumentException('Referensi foto KTP Telegram tidak valid.');
        }
        return 'telegram_file_id:'.$fileId;
    }

    $relative = ltrim(str_replace('\\','/',$storedValue), '/');
    if (str_contains($relative, "\0") || str_contains($relative, '..')
        || !preg_match('#^uploads/ktp_[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$#i', $relative)) {
        throw new InvalidArgumentException('Lokasi file KTP tidak valid.');
    }
    $base = realpath(TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
    $file = realpath(TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if (!$file) {
        if ($allowMissingSimulationFile && $relative === 'uploads/ktp_simulated_placeholder.jpg') {
            return '/'.$relative;
        }
        throw new InvalidArgumentException('File KTP tidak ditemukan pada server.');
    }
    if (!$base || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        throw new InvalidArgumentException('Lokasi file KTP berada di luar folder yang diizinkan.');
    }
    $size = filesize($file);
    if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran file KTP tidak valid atau melebihi 8 MB.');
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($file) : null;
    if ($mime && !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new InvalidArgumentException('Tipe file KTP tidak didukung.');
    }
    return '/'.$relative;
}

/** Source line 2210: consumeRateLimit */
function consumeRateLimit($pdo, $scopeKey, $limit, $windowSeconds, $blockSeconds = 0) {
    if (!$pdo) return ['allowed'=>true,'retryAfter'=>0];
    $scopeKey = substr((string)$scopeKey,0,190);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT * FROM rate_limits WHERE scope_key=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$scopeKey]); $row=$stmt->fetch(); $now=time();
        if ($row && !empty($row['blocked_until']) && strtotime($row['blocked_until'])>$now) {
            if ($ownsTransaction) tamasyaFinancialCommit($pdo);
            return ['allowed'=>false,'retryAfter'=>max(1,strtotime($row['blocked_until'])-$now)];
        }
        $windowStart=$row&&!empty($row['window_start'])?strtotime($row['window_start']):0;
        $hits=$row?(int)$row['hit_count']:0;
        if (!$row || !$windowStart || ($now-$windowStart)>=(int)$windowSeconds) { $windowStart=$now; $hits=1; } else { $hits++; }
        $allowed=$hits<=(int)$limit;
        $blockedUntil=(!$allowed && (int)$blockSeconds>0)?date('Y-m-d H:i:s',$now+(int)$blockSeconds):null;
        $save=$pdo->prepare("INSERT INTO rate_limits (scope_key,window_start,hit_count,blocked_until) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),hit_count=VALUES(hit_count),blocked_until=VALUES(blocked_until)");
        $save->execute([$scopeKey,date('Y-m-d H:i:s',$windowStart),$hits,$blockedUntil]);
        if ($ownsTransaction) tamasyaFinancialCommit($pdo);
        return ['allowed'=>$allowed,'retryAfter'=>$blockedUntil?max(1,strtotime($blockedUntil)-$now):max(1,(int)$windowSeconds-($now-$windowStart))];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log(clientExceptionMessage('[api.php] rate limiter unavailable', $e));
        $failClosed = tamasyaIsProductionEnvironment() && preg_match('/^(?:login|otp|refresh|public|authenticated):/', (string)$scopeKey);
        return ['allowed'=>!$failClosed,'retryAfter'=>$failClosed ? 60 : 0];
    }
}

/** Source line 2237: enforceRateLimit */
function enforceRateLimit($pdo,$scopeKey,$limit,$windowSeconds,$blockSeconds=0) {
    $result=consumeRateLimit($pdo,$scopeKey,$limit,$windowSeconds,$blockSeconds);
    if (empty($result['allowed'])) {
        http_response_code(429); header('Retry-After: '.(int)$result['retryAfter']);
        echo json_encode(['success'=>false,'error'=>'Terlalu banyak percobaan. Coba lagi beberapa saat.','retryAfter'=>(int)$result['retryAfter'],'lockScope'=>'username_ip']);
        return false;
    }
    return true;
}

/** Clear one exact rate-limit bucket after a verified successful login or when
 * a completed account-lock period is normalized. This prevents successful
 * users from carrying stale failed-attempt quota into their next login. */
function tamasyaClearRateLimitScope($pdo, $scopeKey): void {
    if (!$pdo) return;
    $scopeKey = substr((string)$scopeKey,0,190);
    if ($scopeKey === '') return;
    try {
        $stmt=$pdo->prepare('DELETE FROM rate_limits WHERE scope_key=?');
        $stmt->execute([$scopeKey]);
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[api.php] clear rate limiter failed', $e));
    }
}

/** Source line 2271: createMaintenanceExpense */
function createMaintenanceExpense($pdo,$ticket,$amount,$user,$paymentMethod='cash',$bankAccountId='',$shiftSessionId=null) {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'pencatatan biaya maintenance');
    if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new RuntimeException('Biaya maintenance wajib berada dalam transaksi database.');
    $amount=max(0,round((float)$amount,2)); if($amount<=0)return null;
    $paymentMethod=tamasyaNormalizePaymentMethod((string)$paymentMethod);
    $resolvedAccount=tamasyaResolvePaymentAccount($pdo,$paymentMethod,trim((string)$bankAccountId),[
        'allowedMethods'=>['cash','transfer','qris','payable'],'lock'=>true,'context'=>'Biaya maintenance'
    ]);
    if($paymentMethod==='cash')$shiftSessionId=tamasyaResolveExpenseCashShift($pdo,$user,$shiftSessionId);
    else $shiftSessionId=null;
    $operationId='maintenance_expense:'.($ticket['id']??'');
    $stmt=$pdo->prepare("SELECT id,amount,bankAccountId,shiftSessionId FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");$stmt->execute([$operationId]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(abs((float)$existing['amount']-$amount)>0.009)throw new RuntimeException('Biaya maintenance sudah diposting dengan nominal berbeda; gunakan koreksi transaksi resmi, bukan mengubah tiket selesai.');
        return (string)$existing['id'];
    }
    $id=generateServerId('tx'); $date=date('Y-m-d'); $description='Biaya maintenance: '.($ticket['title']??$ticket['asset_ref']??'Tiket').' ['.strtoupper($paymentMethod).']';
    $maintenanceCategory=tamasyaRequireSystemFinanceCategory($pdo,'maintenance_expense','expense',true);
    tamasyaPostFinancialTransaction($pdo,[
        'id'=>$id,'type'=>'expense','category'=>(string)$maintenanceCategory['name'],'categoryId'=>(string)$maintenanceCategory['id'],'categorySystemKey'=>'maintenance_expense',
        'subcategory'=>'Maintenance Ticket','amount'=>$amount,'date'=>$date,'description'=>$description,'createdBy'=>currentStaffLabel($user),
        'bankAccountId'=>$resolvedAccount,'shiftSessionId'=>$shiftSessionId,'transactionKind'=>'maintenance_cost','sourceEntity'=>'maintenance_ticket',
        'sourceEntityId'=>$ticket['id'],'isSystemGenerated'=>1,'operationId'=>$operationId,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
        'taxSnapshotStatus'=>'not_applicable','taxSource'=>'maintenance','updatedBy'=>$user['id']??null,'updatedSource'=>'web','version'=>1
    ],$user,'maintenance',['lockCatalog'=>true,'source'=>'web']);
    return $id;
}

/** Canonical owner for any live physical-cash movement. */
function tamasyaResolvePhysicalCashShift(PDO $pdo,array $user,$shiftSessionId=null,string $context='Transaksi tunai'): ?string {
    $required=(int)$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn()===1;
    $requested=trim((string)$shiftSessionId);
    if($requested==='')return resolveOpenShiftSessionId($pdo,$user,$required);
    $actorId=trim((string)($user['id']??''));
    if($actorId==='')throw new RuntimeException('Identitas petugas tidak tersedia untuk validasi shift kas.');
    $stmt=$pdo->prepare("SELECT id,status,staff_id,companion_staff_id FROM shift_sessions WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$requested]);$shift=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$shift||strtolower((string)($shift['status']??''))!=='open')throw new RuntimeException($context.' ditolak karena shift kas tidak ditemukan atau sudah ditutup.');
    if(!in_array($actorId,[trim((string)($shift['staff_id']??'')),trim((string)($shift['companion_staff_id']??''))],true))throw new RuntimeException($context.' hanya boleh dicatat ke shift aktif milik petugas/pendamping yang sedang login.');
    return (string)$shift['id'];
}

/** Compatibility wrapper for existing expense modules; authority lives above. */
function tamasyaResolveExpenseCashShift(PDO $pdo,array $user,$shiftSessionId=null): ?string {
    return tamasyaResolvePhysicalCashShift($pdo,$user,$shiftSessionId,'Biaya tunai');
}

/** FINAL9: preserve compatibility with FINAL8 queued inventory-maintenance payloads without inventing cash. */
function tamasyaInventoryMaintenancePaymentMethod(PDO $pdo,$paymentMethod,$bankAccountId=''): string {
    $method=tamasyaNormalizePaymentMethod((string)$paymentMethod);
    if($method!==''){
        if(!in_array($method,['cash','transfer','qris','payable'],true))throw new InvalidArgumentException('Sumber biaya maintenance inventaris harus Tunai, Transfer, QRIS, atau Hutang/Payable.');
        return $method;
    }
    $accountId=trim((string)$bankAccountId);
    if($accountId==='')return 'payable';
    return tamasyaInferPaymentMethodFromAccount($pdo,$accountId,true,['transfer','qris']);
}

/** Source line 2282: createInventoryMaintenanceExpense */
function createInventoryMaintenanceExpense($pdo,$maintenanceId,$amount,$user,$paymentMethod='',$bankAccountId='',$shiftSessionId=null) {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'pencatatan biaya maintenance inventaris');
    if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new RuntimeException('Biaya maintenance inventory wajib berada dalam transaksi database.');
    $amount=max(0,round((float)$amount,2)); if($amount<=0)return null;
    $stmt=$pdo->prepare("SELECT m.*,i.name AS asset_name,i.code AS asset_code FROM inventory_maintenance m LEFT JOIN inventory i ON i.id=m.inventory_id WHERE m.id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$maintenanceId]); $maintenance=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$maintenance)throw new RuntimeException('Log perawatan tidak ditemukan.');
    if(abs(round((float)($maintenance['cost']??0),2)-$amount)>0.009)throw new RuntimeException('Nominal biaya maintenance inventaris berubah; buat koreksi/approval baru, jangan memakai posting lama.');
    $paymentMethod=tamasyaInventoryMaintenancePaymentMethod($pdo,$paymentMethod,$bankAccountId);
    $resolvedAccount=tamasyaResolvePaymentAccount($pdo,$paymentMethod,trim((string)$bankAccountId),[
        'allowedMethods'=>['cash','transfer','qris','payable'],'lock'=>true,'context'=>'Biaya maintenance inventaris'
    ]);
    if($paymentMethod==='cash')$shiftSessionId=tamasyaResolveExpenseCashShift($pdo,$user,$shiftSessionId);
    else $shiftSessionId=null;
    $operationId='inventory_maintenance_expense:'.(string)$maintenanceId;
    $stmt=$pdo->prepare("SELECT id,amount,bankAccountId,shiftSessionId FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$operationId]); $existing=$stmt->fetch(PDO::FETCH_ASSOC);
    if($existing){
        $sameAmount=abs(round((float)$existing['amount'],2)-$amount)<=0.009;
        $sameAccount=trim((string)($existing['bankAccountId']??''))===trim((string)($resolvedAccount??''));
        $sameShift=trim((string)($existing['shiftSessionId']??''))===trim((string)($shiftSessionId??''));
        if(!$sameAmount||!$sameAccount||!$sameShift)throw new RuntimeException('Biaya maintenance inventaris sudah diposting dengan nominal/sumber dana berbeda; gunakan koreksi transaksi resmi.');
        return (string)$existing['id'];
    }
    $maintenanceCategory=tamasyaRequireSystemFinanceCategory($pdo,'maintenance_expense','expense',true);
    $id=generateServerId('tx');
    $description='Pemeliharaan ['.($maintenance['asset_name']??'Aset').(!empty($maintenance['asset_code'])?' / '.$maintenance['asset_code']:'').']: '.($maintenance['action_taken']??'Perawatan').' oleh '.($maintenance['staff_name']??currentStaffLabel($user)).' ['.strtoupper($paymentMethod).']';
    tamasyaPostFinancialTransaction($pdo,[
        'id'=>$id,'type'=>'expense','category'=>(string)$maintenanceCategory['name'],'categoryId'=>(string)$maintenanceCategory['id'],'categorySystemKey'=>'maintenance_expense',
        'amount'=>$amount,'date'=>$maintenance['maintenance_date']??date('Y-m-d'),'description'=>$description,'createdBy'=>currentStaffLabel($user),
        'bankAccountId'=>$resolvedAccount,'shiftSessionId'=>$shiftSessionId,'transactionKind'=>'maintenance_cost','sourceEntity'=>'inventory_maintenance',
        'sourceEntityId'=>$maintenanceId,'isSystemGenerated'=>1,'operationId'=>$operationId,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
        'taxSnapshotStatus'=>'not_applicable','taxSource'=>'maintenance','updatedBy'=>$user['id']??null,'updatedSource'=>'web','version'=>1
    ],$user,'maintenance',['lockCatalog'=>true,'source'=>'web']);
    return $id;
}

/** Source line 2301: currentStaffLabel */
function currentStaffLabel($user) {
    return trim((string)($user['username'] ?? $user['name'] ?? 'staff')) ?: 'staff';
}

/** Source line 2306: canManageStaffSavings */
function canManageStaffSavings($user): bool {
    return is_array($user) && in_array(strtolower((string)($user['role'] ?? '')), ['admin','manager','finance'], true);
}

/** Source line 2310: normalizeStaffSavingsAmount */
function normalizeStaffSavingsAmount($value): float {
    $amount = round((float)$value, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > 999999999999.99) {
        throw new InvalidArgumentException('Nominal Simpanan Karyawan harus lebih dari 0 dan berada dalam batas yang wajar.');
    }
    return $amount;
}

/** Source line 2318: normalizeStaffSavingsMethod */
function normalizeStaffSavingsMethod($value): string {
    $method = strtolower(trim((string)$value));
    return in_array($method, ['cash','transfer'], true) ? $method : 'cash';
}

/** Source line 2323: normalizeStaffSavingsSource */
function normalizeStaffSavingsSource($value): string {
    $source = strtolower(trim((string)$value));
    $allowed = ['salary','personal_income','business_income','family_transfer','personal_cash','other','withdrawal_request','correction'];
    return in_array($source, $allowed, true) ? $source : 'other';
}

/** Source line 2329: ensureStaffSavingsAccount */
function ensureStaffSavingsAccount(PDO $pdo, string $staffId): void {
    $pdo->prepare("INSERT INTO staff_savings_accounts(staff_id,balance,status,version,created_at,updated_at) VALUES (?,0,'active',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id)")
        ->execute([$staffId]);
}

/** Source line 2334: lockStaffSavingsAccount */
function lockStaffSavingsAccount(PDO $pdo, string $staffId): array {
    ensureStaffSavingsAccount($pdo,$staffId);
    $stmt=$pdo->prepare("SELECT * FROM staff_savings_accounts WHERE staff_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$staffId]);
    $account=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$account) throw new RuntimeException('Rekening Simpanan Karyawan tidak dapat dikunci.');
    return $account;
}

/** Source line 2343: staffSavingsReservedAmount */
function staffSavingsReservedAmount(PDO $pdo, string $staffId, ?string $excludeRequestId=null): float {
    $sql="SELECT COALESCE(SUM(amount),0) FROM staff_savings_requests WHERE staff_id=? AND status IN ('pending','approved')";
    $params=[$staffId];
    if($excludeRequestId!==null && $excludeRequestId!==''){$sql.=" AND id<>?";$params[]=$excludeRequestId;}
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return round((float)$stmt->fetchColumn(),2);
}

/** Source line 2351: staffSavingsTargetStaff */
function staffSavingsTargetStaff(PDO $pdo, array $actor, string $requestedStaffId): array {
    $actorId=(string)($actor['id']??'');
    $targetId=trim($requestedStaffId)!==''?trim($requestedStaffId):$actorId;
    if(!canManageStaffSavings($actor) && !(tamasyaIsOwnerRole($actor) && strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='GET') && $targetId!==$actorId){
        http_response_code(403);
        throw new RuntimeException('Karyawan hanya dapat mengakses Simpanan miliknya sendiri.');
    }
    $stmt=$pdo->prepare("SELECT id,name,role,status FROM staff WHERE id=? LIMIT 1");
    $stmt->execute([$targetId]);$staff=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$staff || ($staff['status']??'')!=='active'){
        http_response_code(404);
        throw new RuntimeException('Karyawan aktif tidak ditemukan.');
    }
    return $staff;
}

/** Source line 2367: staffSavingsReceiptPrefix */
function staffSavingsReceiptPrefix(string $entryType): string {
    return $entryType==='deposit' || $entryType==='correction_credit' ? 'SAV-IN' : 'SAV-OUT';
}

/** Source line 2371: assertStaffSavingsClosedBeforeDeactivation */
function assertStaffSavingsClosedBeforeDeactivation(PDO $pdo, string $staffId): void {
    if ($staffId === '') return;
    $account=$pdo->prepare("SELECT balance FROM staff_savings_accounts WHERE staff_id=? LIMIT 1 FOR UPDATE");
    $account->execute([$staffId]);
    $balance=round((float)($account->fetchColumn() ?: 0),2);
    $requests=$pdo->prepare("SELECT COUNT(*) FROM staff_savings_requests WHERE staff_id=? AND status IN ('pending','approved')");
    $requests->execute([$staffId]);
    $activeRequests=(int)$requests->fetchColumn();
    if(abs($balance)>0.001 || $activeRequests>0){
        throw new RuntimeException('Selesaikan saldo dan permintaan Simpanan Karyawan sebelum menonaktifkan akun staf ini.');
    }
}

/**
 * Resolve one authoritative booking interval for creation, edit, overlap, and check-in.
 * Short-time bookings are valid on the same calendar date only when the exact end
 * timestamp is later than the exact start timestamp.
 */
function resolveHotelBookingStayWindow(array $row, string $defaultCheckoutTime='12:00:00', string $defaultCheckinTime='14:00:00'): array {
    $checkIn=trim((string)($row['checkIn']??''));
    $checkOut=trim((string)($row['checkOut']??''));
    $isOpenEnded=(int)($row['isOpenEnded']??0)===1;
    if(!validIsoDate($checkIn)||!validIsoDate($checkOut)){
        throw new InvalidArgumentException('Tanggal check-in/check-out tidak valid.');
    }

    $stayMode=strtolower(trim((string)($row['stayMode']??'')));
    // `stayMode` describes the commercial stay shape; `isOpenEnded` describes
    // whether departure is known. Keep short_time + open-ended valid so a guest
    // can choose short-time while the exact exit time is still unknown.
    if($isOpenEnded){
        if($stayMode==='short_time')$stayMode='short_time';
        elseif($stayMode===''||in_array($stayMode,['overnight','open_ended'],true))$stayMode='open_ended';
    }elseif($stayMode===''&&$checkOut===$checkIn)$stayMode='short_time';
    elseif($stayMode==='')$stayMode='overnight';
    if(!in_array($stayMode,['overnight','short_time','open_ended'],true)){
        throw new InvalidArgumentException('Mode menginap tidak valid.');
    }
    if($stayMode==='open_ended'&&!$isOpenEnded){
        throw new InvalidArgumentException('Mode open-ended wajib memakai penanda isOpenEnded.');
    }

    $rawScheduledCheckIn=trim((string)($row['scheduledCheckInAt']??''));
    $rawScheduledCheckOut=trim((string)($row['scheduledCheckOutAt']??''));
    $scheduledCheckInAt=normalizeHotelDateTime($rawScheduledCheckIn);
    $scheduledCheckOutAt=normalizeHotelDateTime($rawScheduledCheckOut);
    if($rawScheduledCheckIn!==''&&!$scheduledCheckInAt){
        throw new InvalidArgumentException('Jam masuk terjadwal tidak valid.');
    }
    if($rawScheduledCheckOut!==''&&!$scheduledCheckOutAt){
        throw new InvalidArgumentException('Jam selesai terjadwal tidak valid.');
    }

    $checkinTime=preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',$defaultCheckinTime)
        ? substr($defaultCheckinTime,0,8)
        : '14:00:00';
    if(strlen($checkinTime)===5)$checkinTime.=':00';
    $checkoutTime=preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',$defaultCheckoutTime)
        ? substr($defaultCheckoutTime,0,8)
        : '12:00:00';
    if(strlen($checkoutTime)===5)$checkoutTime.=':00';

    if($stayMode==='short_time'){
        if($checkOut!==$checkIn){
            throw new InvalidArgumentException('Short time wajib memakai tanggal masuk dan keluar yang sama.');
        }
        if($scheduledCheckInAt!==null && substr($scheduledCheckInAt,0,10)!==$checkIn){
            throw new InvalidArgumentException('Jam masuk short time wajib berada pada tanggal booking yang sama.');
        }
        if(!$isOpenEnded){
            if(!$scheduledCheckInAt||!$scheduledCheckOutAt
                ||substr($scheduledCheckOutAt,0,10)!==$checkOut
                ||strtotime($scheduledCheckOutAt)<=strtotime($scheduledCheckInAt)){
                throw new InvalidArgumentException('Short time dengan jam keluar pasti wajib memiliki jam masuk dan jam selesai yang valid.');
            }
        }
    }else{
        if(!$isOpenEnded&&$checkOut<=$checkIn){
            throw new InvalidArgumentException('Booking menginap wajib memiliki checkout setelah check-in.');
        }
        // Root invariant: an overnight/open-ended schedule timestamp belongs to
        // the same boundary date as checkIn/checkOut. Older edit paths could keep
        // the old timestamp after the date field changed, which made availability
        // calculate a different interval from the date shown to operators. Rebase
        // only inconsistent boundary dates while preserving the snapshotted clock.
        if($scheduledCheckInAt!==null && substr($scheduledCheckInAt,0,10)!==$checkIn){
            $scheduledCheckInAt=$checkIn.' '.substr($scheduledCheckInAt,11,8);
        }
        if(!$isOpenEnded && $scheduledCheckOutAt!==null && substr($scheduledCheckOutAt,0,10)!==$checkOut){
            $scheduledCheckOutAt=$checkOut.' '.substr($scheduledCheckOutAt,11,8);
        }
    }

    if($isOpenEnded)$scheduledCheckOutAt=null;
    // Overnight/open-ended reservation inventory starts at the property's canonical
    // check-in clock, not midnight. This permits normal same-day turnover when the
    // previous guest checks out before the next guest's configured arrival time.
    $startAt=$scheduledCheckInAt?:($checkIn.' '.$checkinTime);
    $endAt=$isOpenEnded?'9999-12-31 23:59:59':($scheduledCheckOutAt?:($checkOut.' '.$checkoutTime));
    if(!$isOpenEnded&&strtotime($endAt)<=strtotime($startAt)){
        throw new InvalidArgumentException('Waktu selesai booking wajib setelah waktu mulai.');
    }
    // Snapshot the effective hotel schedule into the booking contract. Once a
    // reservation is created, a later property-wide check-in/check-out policy
    // change must not silently move that existing guest's inventory window.
    $effectiveScheduledCheckInAt=$startAt;
    $effectiveScheduledCheckOutAt=$isOpenEnded?null:$endAt;

    return [
        'checkIn'=>$checkIn,
        'checkOut'=>$checkOut,
        'isOpenEnded'=>$isOpenEnded,
        'stayMode'=>$stayMode,
        'scheduledCheckInAt'=>$effectiveScheduledCheckInAt,
        'scheduledCheckOutAt'=>$effectiveScheduledCheckOutAt,
        'startAt'=>$startAt,
        'endAt'=>$endAt,
        'checkoutDueAt'=>$isOpenEnded?null:$endAt,
        'departureMode'=>$isOpenEnded?'open_ended':'scheduled',
        'checkinTime'=>$checkinTime,
        'checkoutTime'=>$checkoutTime,
    ];
}

/** Source line 2390: tamasyaStringLength */
function tamasyaStringLength(string $value): int {
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Source line 2394: generateServerId */
function generateServerId($prefix) {
    $prefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$prefix) ?: 'id';
    // During a standby-origin mutation and its replay on the active primary, every
    // generated business identifier must remain identical. Otherwise a local
    // shift/booking/task can be replayed with a different ID and later events
    // still point to the local-only identifier. Counters are scoped per prefix
    // so optional notification/external branches cannot shift core entity IDs.
    $eventId = trim((string)($GLOBALS['tamasya_node_operation_event_id'] ?? ''));
    if ($eventId !== '') {
        if (!isset($GLOBALS['tamasya_node_id_counters']) || !is_array($GLOBALS['tamasya_node_id_counters'])) {
            $GLOBALS['tamasya_node_id_counters'] = [];
        }
        $counter = (int)($GLOBALS['tamasya_node_id_counters'][$prefix] ?? 0) + 1;
        $GLOBALS['tamasya_node_id_counters'][$prefix] = $counter;
        return $prefix . '_' . substr(hash('sha256', $eventId . '|' . $prefix . '|' . $counter), 0, 24);
    }
    try { return $prefix . '_' . bin2hex(random_bytes(12)); }
    catch (Throwable $e) { return $prefix . '_' . str_replace('.', '', uniqid('', true)); }
}

/** Source line 2414: getBootstrapAdminPassword */
function getBootstrapAdminPassword() {
    $configured = trim((string)(getenv('APP_BOOTSTRAP_ADMIN_PASSWORD') ?: ''));
    if ($configured === '') {
        throw new RuntimeException('APP_BOOTSTRAP_ADMIN_PASSWORD wajib dikonfigurasi untuk instalasi pertama; password bootstrap tidak pernah dibuat atau ditulis ke log.');
    }
    if (strlen($configured) < 12) throw new RuntimeException('APP_BOOTSTRAP_ADMIN_PASSWORD minimal 12 karakter.');
    return $configured;
}

/** Source line 2425: createTelegramBindingCode */
function createTelegramBindingCode($pdo, $staffId, $createdBy) {
    $staffId = trim((string)$staffId);
    if ($staffId === '') throw new InvalidArgumentException('Staff ID wajib diisi.');
    $stmt = $pdo->prepare("SELECT id,name,status FROM staff WHERE id=? LIMIT 1");
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();
    if (!$staff || ($staff['status'] ?? '') !== 'active') throw new RuntimeException('Akun staf aktif tidak ditemukan.');
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i=0; $i<8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
    $hash = hash('sha256', $code);
    $id = generateServerId('tg_bind');
    $pdo->prepare("UPDATE telegram_binding_codes SET used_at=COALESCE(used_at,CURRENT_TIMESTAMP) WHERE staff_id=? AND used_at IS NULL")
        ->execute([$staffId]);
    $expiresAt = date('Y-m-d H:i:s', time()+600);
    $pdo->prepare("INSERT INTO telegram_binding_codes (id,staff_id,code_hash,expires_at,created_by,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)")
        ->execute([$id,$staffId,$hash,$expiresAt,$createdBy ?: null]);
    return ['id'=>$id,'code'=>$code,'expiresAt'=>$expiresAt,'staffName'=>$staff['name'] ?? 'Staf'];
}

/** Source line 2445: consumeTelegramBindingCode */
function consumeTelegramBindingCode($pdo, $code, $telegramUserId, $telegramChatId) {
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)$code));
    if (strlen($normalized) !== 8) throw new InvalidArgumentException('Format kode binding tidak valid.');
    $hash = hash('sha256',$normalized);
    $owns = !$pdo->inTransaction();
    try {
        if ($owns) $pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT c.*,s.name,s.role,s.status FROM telegram_binding_codes c JOIN staff s ON s.id=c.staff_id WHERE c.code_hash=? AND c.used_at IS NULL AND c.expires_at>=NOW() LIMIT 1 FOR UPDATE");
        $stmt->execute([$hash]); $row=$stmt->fetch();
        if(!$row || ($row['status']??'')!=='active') throw new RuntimeException('Kode binding salah, kedaluwarsa, atau sudah digunakan.');
        bindTelegramIdentity($pdo,$row['staff_id'],$telegramUserId,$telegramChatId);
        $pdo->prepare("UPDATE telegram_binding_codes SET used_at=CURRENT_TIMESTAMP,used_telegram_user_id=? WHERE id=?")
            ->execute([(string)$telegramUserId,$row['id']]);
        if($owns)tamasyaFinancialCommit($pdo);
        return $row;
    } catch(Throwable $e) {
        if($owns && $pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** Source line 2466: bindTelegramIdentity */
function bindTelegramIdentity($pdo, $staffId, $telegramUserId, $telegramChatId = null) {
    $staffId = trim((string)$staffId);
    $telegramUserId = trim((string)$telegramUserId);
    $telegramChatId = trim((string)($telegramChatId ?: $telegramUserId));
    if ($staffId === '' || $telegramUserId === '') throw new InvalidArgumentException('Identitas Telegram/staff tidak lengkap.');
    $owns = !$pdo->inTransaction();
    try {
        if ($owns) $pdo->beginTransaction();
        $staffLock=$pdo->prepare("SELECT id,status FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
        $staffLock->execute([$staffId]);
        $staff=$staffLock->fetch(PDO::FETCH_ASSOC);
        if(!$staff || (string)($staff['status']??'')!=='active') throw new RuntimeException('Akun staff aktif tidak ditemukan untuk binding Telegram.');
        // Kunci kedua sisi relasi sebelum delete+insert agar dua kode yang
        // dikonsumsi bersamaan tidak dapat membuat binding silang.
        $bindingLock=$pdo->prepare("SELECT telegram_user_id,staff_id FROM telegram_bindings WHERE staff_id=? OR telegram_user_id=? ORDER BY telegram_user_id FOR UPDATE");
        $bindingLock->execute([$staffId,$telegramUserId]);
        $bindingLock->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE staff SET telegram_chat_id = NULL, telegram_state = NULL, telegram_context = NULL, two_factor_active = 0, two_factor_code_hash = NULL, two_factor_challenge_hash = NULL, two_factor_device_id = NULL, two_factor_expires = NULL WHERE telegram_chat_id = ? AND id <> ?")
            ->execute([$telegramUserId, $staffId]);
        $pdo->prepare("UPDATE staff SET telegram_chat_id = ?, telegram_state = NULL, telegram_context = NULL WHERE id = ?")
            ->execute([$telegramUserId, $staffId]);
        $pdo->prepare("DELETE FROM telegram_bindings WHERE staff_id = ? OR telegram_user_id = ?")
            ->execute([$staffId, $telegramUserId]);
        $pdo->prepare("INSERT INTO telegram_bindings (telegram_user_id, telegram_chat_id, staff_id, status, verified_at) VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)")
            ->execute([$telegramUserId, $telegramChatId, $staffId]);
        if($owns)tamasyaFinancialCommit($pdo);
    } catch(Throwable $e) {
        if($owns && $pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** Source line 2499: resolveTelegramUserIdForStaff */
function resolveTelegramUserIdForStaff($pdo, $staffId, $legacyTelegramId = null) {
    $staffId=trim((string)$staffId);
    if($staffId==='')return '';
    $stmt=$pdo->prepare("SELECT telegram_user_id FROM telegram_bindings WHERE staff_id=? AND status='active' ORDER BY verified_at DESC,telegram_user_id DESC LIMIT 2");
    $stmt->execute([$staffId]);
    $rows=$stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
    if(count($rows)===1)return trim((string)$rows[0]);
    if(count($rows)>1){
        error_log('[Telegram Auth] Multiple active bindings rejected for staff hash '.substr(hash('sha256',$staffId),0,16));
        return '';
    }
    $legacy=trim((string)$legacyTelegramId);
    if($legacy==='')return '';
    $legacyCheck=$pdo->prepare("SELECT id FROM staff WHERE telegram_chat_id=? AND status='active' ORDER BY id LIMIT 2");
    $legacyCheck->execute([$legacy]);
    $legacyRows=$legacyCheck->fetchAll(PDO::FETCH_COLUMN)?:[];
    return count($legacyRows)===1 && hash_equals((string)$legacyRows[0],$staffId)?$legacy:'';
}

/** Source line 2518: unbindTelegramIdentity */
function unbindTelegramIdentity($pdo, $staffId, $telegramUserId = null) {
    // 2FA Telegram tidak boleh tetap aktif setelah identitas Telegram dilepas.
    // Bersihkan seluruh challenge/OTP agar sesi verifikasi lama tidak dapat dipakai kembali.
    $pdo->prepare("UPDATE staff SET telegram_chat_id = NULL, telegram_state = NULL, telegram_context = NULL, two_factor_active = 0, two_factor_code_hash = NULL, two_factor_challenge_hash = NULL, two_factor_device_id = NULL, two_factor_expires = NULL WHERE id = ?")
        ->execute([$staffId]);
    if ($telegramUserId !== null && $telegramUserId !== '') {
        $pdo->prepare("DELETE FROM telegram_bindings WHERE staff_id = ? OR telegram_user_id = ?")
            ->execute([$staffId, (string)$telegramUserId]);
    } else {
        $pdo->prepare("DELETE FROM telegram_bindings WHERE staff_id = ?")->execute([$staffId]);
    }
}

/** Source line 2531: findActiveStaffByTelegramUserId */
function findActiveStaffByTelegramUserId($pdo, $telegramUserId): ?array {
    // Unbound identities use null consistently, including missing PDO rows and
    // rejected legacy duplicates, so nullable authorization/projection APIs agree.
    $telegramUserId = trim((string)$telegramUserId);
    if ($telegramUserId === '') return null;
    $stmt = $pdo->prepare("SELECT s.* FROM telegram_bindings tb
        JOIN staff s ON s.id=tb.staff_id
        WHERE tb.telegram_user_id=? AND tb.status='active' AND s.status='active'
        ORDER BY tb.verified_at DESC LIMIT 1");
    $stmt->execute([$telegramUserId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($staff) return $staff;
    // Kompatibilitas data lama hanya berlaku bila ID tersebut unik. Duplikasi
    // legacy ditolak agar satu Telegram User ID tidak pernah memilih akun secara acak.
    $legacy = $pdo->prepare("SELECT * FROM staff WHERE telegram_chat_id=? AND status='active' ORDER BY id LIMIT 2");
    $legacy->execute([$telegramUserId]);
    $legacyRows = $legacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($legacyRows) === 1) {
        return $legacyRows[0];
    }
    if (count($legacyRows) > 1) {
        error_log('[Telegram Auth] Duplicate legacy Telegram User ID rejected: ' . hash('sha256',$telegramUserId));
    }
    return null;
}


/** Safe Telegram webhook state for enterprise audit; never stores bot token or webhook secret. */
function tamasyaTelegramWebhookAuditSnapshot($row) {
    if (!is_array($row)) return null;
    $url=trim((string)($row['telegram_webhook_url']??''));
    $error=trim((string)($row['telegram_webhook_last_error']??''));
    $secret=trim((string)($row['telegram_webhook_secret']??''));
    $token=trim((string)($row['telegram_bot_token']??''));
    return [
        'id'=>(string)($row['id']??'system_default'),
        'active'=>(bool)($row['telegram_webhook_active']??false),
        'url'=>$url!==''?$url:null,
        'urlFingerprint'=>$url!==''?substr(hash('sha256',$url),0,24):null,
        'secretConfigured'=>$secret!=='',
        'secretFingerprint'=>$secret!==''?substr(hash('sha256',$secret),0,24):null,
        'botTokenConfigured'=>$token!=='',
        'botTokenFingerprint'=>$token!==''?substr(hash('sha256',$token),0,24):null,
        'lastErrorFingerprint'=>$error!==''?substr(hash('sha256',$error),0,24):null,
        'lastErrorLength'=>strlen($error),
        'checkedAt'=>$row['telegram_webhook_checked_at']??null,
    ];
}

/** Persist mirrored Telegram webhook state atomically with required audit. */
function tamasyaPersistTelegramWebhookState($pdo,$staff,array $state,string $auditAction) {
    $owns=!$pdo->inTransaction();
    try {
        if($owns)$pdo->beginTransaction();
        $lock=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1 FOR UPDATE");
        $before=$lock?$lock->fetch(PDO::FETCH_ASSOC):null;
        if(!$before)throw new RuntimeException('Konfigurasi sistem tidak ditemukan.');
        $stmt=$pdo->prepare("UPDATE config SET telegram_webhook_active=?,telegram_webhook_url=?,telegram_webhook_secret=?,telegram_webhook_last_error=?,telegram_webhook_checked_at=NOW() WHERE id='system_default'");
        $stmt->execute([
            !empty($state['active'])?1:0,
            isset($state['url'])&&trim((string)$state['url'])!==''?trim((string)$state['url']):null,
            isset($state['secret'])&&trim((string)$state['secret'])!==''?trim((string)$state['secret']):null,
            isset($state['lastError'])&&trim((string)$state['lastError'])!==''?substr(trim((string)$state['lastError']),0,2000):null,
        ]);
        $afterStmt=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1");
        $after=$afterStmt?$afterStmt->fetch(PDO::FETCH_ASSOC):null;
        writeRequiredEnterpriseAudit($pdo,$staff,$auditAction,'config','system_default',tamasyaTelegramWebhookAuditSnapshot($before),tamasyaTelegramWebhookAuditSnapshot($after),'web');
        if($owns)tamasyaFinancialCommit($pdo);
        return $after?:[];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** Safe session projection for audit; raw bearer/refresh hashes and personal network data are omitted. */
function tamasyaSessionAuditSnapshot($row) {
    if(!is_array($row))return null;
    $ip=trim((string)($row['ip_address']??''));
    $ua=trim((string)($row['user_agent']??''));
    return [
        'id'=>(string)($row['id']??''),
        'staffId'=>(string)($row['staff_id']??''),
        'deviceId'=>(string)($row['device_id']??''),
        'deviceName'=>(string)($row['device_name']??''),
        'ipFingerprint'=>$ip!==''?substr(hash('sha256',$ip),0,24):null,
        'userAgentFingerprint'=>$ua!==''?substr(hash('sha256',$ua),0,24):null,
        'createdAt'=>$row['created_at']??null,
        'lastActivity'=>$row['last_activity']??null,
        'expiresAt'=>$row['expires_at']??null,
        'refreshExpires'=>$row['refresh_expires']??null,
        'revokedAt'=>$row['revoked_at']??null,
    ];
}

/** Safe 2FA challenge projection; OTP, challenge hash, and Telegram identifier are never stored. */
function tamasyaTwoFactorAuditSnapshot($row) {
    if(!is_array($row))return null;
    return [
        'staffId'=>(string)($row['id']??''),
        'twoFactorActive'=>(bool)($row['two_factor_active']??false),
        'challengeConfigured'=>trim((string)($row['two_factor_challenge_hash']??''))!=='',
        'otpConfigured'=>trim((string)($row['two_factor_code_hash']??''))!=='',
        'deviceId'=>(string)($row['two_factor_device_id']??''),
        'expiresAt'=>$row['two_factor_expires']??null,
        'telegramBound'=>trim((string)($row['telegram_chat_id']??''))!=='',
    ];
}
