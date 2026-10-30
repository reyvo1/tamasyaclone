<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaPromotionCodesEnabled(): bool {
    if (function_exists('tamasyaGrowthFlag')) {
        return tamasyaGrowthFlag('TAMASYA_PROMOTION_CODES_ENABLED', false);
    }
    return getenv('TAMASYA_PROMOTION_CODES_ENABLED') === '1';
}

function tamasyaPromotionCodesSchemaReady(PDO $pdo): bool {
    if (function_exists('tamasyaGrowthTableExists')) {
        return tamasyaGrowthTableExists($pdo, 'promotion_codes');
    }
    try {
        $pdo->query('SELECT 1 FROM promotion_codes LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function tamasyaPromotionCodeNormalize(array $input, bool $isUpdate = false): array {
    $out = [];
    if (array_key_exists('code', $input)) {
        $code = strtoupper(trim((string)$input['code']));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw new InvalidArgumentException('Kode promo tidak valid (3-40 karakter, huruf/angka/-/_).');
        }
        $out['code'] = $code;
    }
    if (array_key_exists('title', $input)) {
        $title = trim((string)$input['title']);
        if ($title === '' || mb_strlen($title) > 190) {
            throw new InvalidArgumentException('Judul promo wajib 1-190 karakter.');
        }
        $out['title'] = $title;
    }
    if (array_key_exists('description', $input)) {
        $out['description'] = trim((string)$input['description']) ?: null;
    }
    if (array_key_exists('discountType', $input) || array_key_exists('discount_type', $input)) {
        $type = strtolower(trim((string)($input['discountType'] ?? $input['discount_type'] ?? 'fixed')));
        if (!in_array($type, ['fixed', 'percent'], true)) {
            throw new InvalidArgumentException('Tipe diskon promo tidak valid (fixed/percent).');
        }
        $out['discount_type'] = $type;
    }
    if (array_key_exists('discountValue', $input) || array_key_exists('discount_value', $input)) {
        $value = (float)($input['discountValue'] ?? $input['discount_value'] ?? 0);
        if ($value <= 0) throw new InvalidArgumentException('Nilai diskon promo harus lebih dari nol.');
        $out['discount_value'] = $value;
    }
    if (array_key_exists('minSpend', $input) || array_key_exists('min_spend', $input)) {
        $min = (float)($input['minSpend'] ?? $input['min_spend'] ?? 0);
        if ($min < 0) throw new InvalidArgumentException('Minimum spend tidak boleh negatif.');
        $out['min_spend'] = $min;
    }
    if (array_key_exists('maxDiscount', $input) || array_key_exists('max_discount', $input)) {
        $raw = $input['maxDiscount'] ?? $input['max_discount'] ?? null;
        if ($raw === '' || $raw === null) {
            $out['max_discount'] = null;
        } else {
            $max = (float)$raw;
            if ($max < 0) throw new InvalidArgumentException('Max discount tidak boleh negatif.');
            $out['max_discount'] = $max;
        }
    }
    if (array_key_exists('validFrom', $input) || array_key_exists('valid_from', $input)) {
        $from = trim((string)($input['validFrom'] ?? $input['valid_from'] ?? ''));
        $out['valid_from'] = $from === '' ? null : $from;
    }
    if (array_key_exists('validUntil', $input) || array_key_exists('valid_until', $input)) {
        $until = trim((string)($input['validUntil'] ?? $input['valid_until'] ?? ''));
        $out['valid_until'] = $until === '' ? null : $until;
    }
    if (isset($out['valid_from'], $out['valid_until']) && $out['valid_from'] !== null && $out['valid_until'] !== null && $out['valid_until'] < $out['valid_from']) {
        throw new InvalidArgumentException('Masa berlaku promo tidak valid.');
    }
    if (array_key_exists('usageLimit', $input) || array_key_exists('usage_limit', $input)) {
        $raw = $input['usageLimit'] ?? $input['usage_limit'] ?? null;
        if ($raw === '' || $raw === null) {
            $out['usage_limit'] = null;
        } else {
            $limit = (int)$raw;
            if ($limit < 0) throw new InvalidArgumentException('Usage limit tidak boleh negatif.');
            $out['usage_limit'] = $limit;
        }
    }
    if (array_key_exists('status', $input)) {
        $status = strtolower(trim((string)$input['status']));
        if (!in_array($status, ['draft', 'active', 'paused', 'expired'], true)) {
            throw new InvalidArgumentException('Status promo tidak valid.');
        }
        $out['status'] = $status;
    }
    if (array_key_exists('appliesTo', $input) || array_key_exists('applies_to', $input)) {
        $applies = strtolower(trim((string)($input['appliesTo'] ?? $input['applies_to'] ?? 'both')));
        if (!in_array($applies, ['booking', 'pos', 'both'], true)) {
            throw new InvalidArgumentException('Applies-to promo tidak valid (booking/pos/both).');
        }
        $out['applies_to'] = $applies;
    }
    if (array_key_exists('stackable', $input)) {
        $out['stackable'] = !empty($input['stackable']) ? 1 : 0;
    }
    if (array_key_exists('autoApply', $input) || array_key_exists('auto_apply', $input)) {
        $out['auto_apply'] = !empty($input['autoApply'] ?? $input['auto_apply'] ?? null) ? 1 : 0;
    }
    if (array_key_exists('eventName', $input) || array_key_exists('event_name', $input)) {
        $name = trim((string)($input['eventName'] ?? $input['event_name'] ?? ''));
        if (mb_strlen($name) > 190) {
            throw new InvalidArgumentException('Nama event promo maksimal 190 karakter.');
        }
        $out['event_name'] = $name === '' ? null : $name;
    }
    if (!$isUpdate) {
        if (!isset($out['code'])) throw new InvalidArgumentException('Kode promo wajib diisi.');
        if (!isset($out['title'])) throw new InvalidArgumentException('Judul promo wajib diisi.');
        if (!isset($out['discount_type'])) $out['discount_type'] = 'fixed';
        if (!isset($out['discount_value'])) throw new InvalidArgumentException('Nilai diskon promo wajib diisi.');
        if ($out['discount_type'] === 'percent' && (float)$out['discount_value'] > 100) {
            throw new InvalidArgumentException('Diskon persen maksimal 100%.');
        }
        if (!isset($out['status'])) $out['status'] = 'draft';
        if (!isset($out['applies_to'])) $out['applies_to'] = 'both';
        if (!isset($out['min_spend'])) $out['min_spend'] = 0;
        if (!isset($out['stackable'])) $out['stackable'] = 0;
        if (!isset($out['auto_apply'])) $out['auto_apply'] = 0;
        if (!isset($out['event_name'])) $out['event_name'] = null;
    }
    return $out;
}

function tamasyaPromotionCodeCreate(PDO $pdo, array $actor, string $operationId, array $input): array {
    if ($operationId === '') throw new InvalidArgumentException('Operation ID wajib.');
    $dup = $pdo->prepare('SELECT * FROM promotion_codes WHERE operation_id=? LIMIT 1');
    $dup->execute([$operationId]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing) return $existing;

    $data = tamasyaPromotionCodeNormalize($input, false);
    $dupCode = $pdo->prepare('SELECT id FROM promotion_codes WHERE code=? LIMIT 1');
    $dupCode->execute([$data['code']]);
    if ($dupCode->fetchColumn()) {
        throw new RuntimeException('Kode promo sudah digunakan.');
    }
    $id = 'promo_' . bin2hex(random_bytes(8));
    $fields = ['id', 'code', 'title', 'description', 'discount_type', 'discount_value', 'min_spend', 'max_discount', 'valid_from', 'valid_until', 'usage_limit', 'status', 'applies_to', 'auto_apply', 'event_name', 'stackable', 'created_by', 'updated_by', 'operation_id'];
    $values = [
        $id,
        $data['code'],
        $data['title'],
        $data['description'] ?? null,
        $data['discount_type'],
        $data['discount_value'],
        $data['min_spend'],
        $data['max_discount'] ?? null,
        $data['valid_from'] ?? null,
        $data['valid_until'] ?? null,
        $data['usage_limit'] ?? null,
        $data['status'],
        $data['applies_to'],
        (int)($data['auto_apply'] ?? 0),
        $data['event_name'] ?? null,
        $data['stackable'],
        $actor['id'] ?? null,
        $actor['id'] ?? null,
        $operationId,
    ];
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO promotion_codes (' . implode(',', $fields) . ') VALUES (' . $placeholders . ')';
    $pdo->prepare($sql)->execute($values);
    $stmt = $pdo->prepare('SELECT * FROM promotion_codes WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function tamasyaPromotionCodeUpdate(PDO $pdo, string $id, array $actor, array $input): array {
    $stmt = $pdo->prepare('SELECT * FROM promotion_codes WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Promo code tidak ditemukan.');
    $data = tamasyaPromotionCodeNormalize($input, true);
    if (isset($data['code']) && $data['code'] !== $row['code']) {
        $dup = $pdo->prepare('SELECT id FROM promotion_codes WHERE code=? AND id<>? LIMIT 1');
        $dup->execute([$data['code'], $id]);
        if ($dup->fetchColumn()) throw new RuntimeException('Kode promo sudah digunakan.');
    }
    $fields = [];
    $values = [];
    foreach ($data as $key => $value) {
        $fields[] = $key . '=?';
        $values[] = $value;
    }
    $fields[] = 'updated_by=?';
    $values[] = $actor['id'] ?? null;
    $values[] = $id;
    $sql = 'UPDATE promotion_codes SET ' . implode(',', $fields) . ' WHERE id=?';
    $pdo->prepare($sql)->execute($values);
    $stmt = $pdo->prepare('SELECT * FROM promotion_codes WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function tamasyaPromotionCodesList(PDO $pdo, array $filters = []): array {
    $sql = 'SELECT * FROM promotion_codes WHERE 1=1';
    $params = [];
    if (!empty($filters['status'])) {
        $sql .= ' AND status=?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['appliesTo'])) {
        $sql .= ' AND (applies_to=? OR applies_to="both")';
        $params[] = $filters['appliesTo'];
    }
    $sql .= ' ORDER BY created_at DESC, code ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tamasyaPromotionCodeValidate(PDO $pdo, string $code, string $appliesTo, float $amount): array {
    $code = strtoupper(trim($code));
    if ($code === '') throw new InvalidArgumentException('Kode promo wajib diisi.');
    $stmt = $pdo->prepare('SELECT * FROM promotion_codes WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Kode promo tidak ditemukan.');
    if ((string)$row['status'] !== 'active') throw new RuntimeException('Kode promo tidak aktif.');
    $today = date('Y-m-d');
    if (!empty($row['valid_from']) && $today < $row['valid_from']) throw new RuntimeException('Kode promo belum berlaku.');
    if (!empty($row['valid_until']) && $today > $row['valid_until']) throw new RuntimeException('Kode promo sudah kedaluwarsa.');
    if (!in_array((string)$row['applies_to'], [$appliesTo, 'both'], true)) {
        throw new RuntimeException('Kode promo tidak berlaku untuk transaksi ini.');
    }
    if ($amount < (float)$row['min_spend']) {
        throw new RuntimeException('Minimum spend kode promo belum terpenuhi.');
    }
    if ($row['usage_limit'] !== null && (int)$row['used_count'] >= (int)$row['usage_limit']) {
        throw new RuntimeException('Kuota kode promo sudah habis.');
    }
    $value = (float)$row['discount_value'];
    $discount = (string)$row['discount_type'] === 'percent'
        ? round($amount * $value / 100, 2)
        : min($amount, $value);
    if ($row['max_discount'] !== null) {
        $discount = min($discount, (float)$row['max_discount']);
    }
    $discount = max(0, round($discount, 2));
    if ($discount <= 0) throw new RuntimeException('Kode promo tidak menghasilkan diskon.');
    if ($discount >= $amount) throw new RuntimeException('Diskon promo harus lebih kecil dari total.');
    return [
        'promotionCode' => $row,
        'discountType' => (string)$row['discount_type'],
        'discountValue' => $value,
        'discountAmount' => $discount,
    ];
}

function tamasyaPromotionCodeAutoApply(PDO $pdo, string $appliesTo, float $amount, string $date = ''): ?array {
    if (function_exists('tamasyaPromotionCodesEnabled') && !tamasyaPromotionCodesEnabled()) return null;
    if (!tamasyaPromotionCodesSchemaReady($pdo)) return null;
    try { $pdo->query('SELECT auto_apply, event_name FROM promotion_codes LIMIT 1'); }
    catch (Throwable $e) { return null; }
    $appliesTo = strtolower(trim($appliesTo));
    if ($appliesTo === '') $appliesTo = 'both';
    $amount = round($amount, 2);
    if ($amount <= 0) return null;
    $date = trim($date);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $sql = "SELECT * FROM promotion_codes
            WHERE status='active'
              AND auto_apply=1
              AND (valid_from IS NULL OR valid_from <= ?)
              AND (valid_until IS NULL OR valid_until >= ?)
              AND min_spend <= ?
              AND (usage_limit IS NULL OR used_count < usage_limit)";
    $params = [$date, $date, $amount];
    if ($appliesTo === 'both') {
        $sql .= " AND applies_to IN ('booking','pos','both')";
    } else {
        $sql .= " AND applies_to IN (?, 'both')";
        $params[] = $appliesTo;
    }
    $sql .= " ORDER BY created_at ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eligible = [];
    foreach ($rows as $row) {
        $value = (float)$row['discount_value'];
        $discount = (string)$row['discount_type'] === 'percent'
            ? round($amount * $value / 100, 2)
            : min($amount, $value);
        if ($row['max_discount'] !== null) {
            $discount = min($discount, (float)$row['max_discount']);
        }
        $discount = max(0, round($discount, 2));
        if ($discount <= 0 || $discount >= $amount) continue;
        $result = $row;
        $result['promotionCode'] = $row;
        $result['discountType'] = (string)$row['discount_type'];
        $result['discountValue'] = $value;
        $result['discountAmount'] = $discount;
        $eligible[] = $result;
    }
    if (!$eligible) return null;
    // Conservative deterministic selection: earliest created active code.
    usort($eligible, static function (array $a, array $b): int {
        $cmp = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    return $eligible[0];
}

function tamasyaPromotionCodeUsageSchemaReady(PDO $pdo): bool {
    if (function_exists('tamasyaGrowthTableExists')) {
        return tamasyaGrowthTableExists($pdo, 'promotion_code_usages');
    }
    try {
        $pdo->query('SELECT 1 FROM promotion_code_usages LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function tamasyaPromotionCodeUsagesSchemaReady(PDO $pdo): bool {
    return tamasyaPromotionCodeUsageSchemaReady($pdo);
}

function tamasyaPromotionCodeUsageTypeFromOperation(string $operationId, ?string $usageType = null): string {
    if ($usageType !== null && trim($usageType) !== '') return strtolower(trim($usageType));
    if (str_ends_with($operationId, ':booking')) return 'booking';
    if (str_ends_with($operationId, ':pos')) return 'pos';
    return 'unknown';
}

function tamasyaPromotionCodeLogUsage(PDO $pdo, string $promotionCodeId, string $operationId, $actor = null, $amount = null, ?string $usageType = null, array $context = []): void {
    if ($operationId === '') throw new InvalidArgumentException('Operation ID wajib.');
    if (function_exists('tamasyaPromotionCodesEnabled') && !tamasyaPromotionCodesEnabled()) return;
    if (!tamasyaPromotionCodeUsageSchemaReady($pdo)) return;

    // Idempotent audit row: one usage record per operation ID.
    $dup = $pdo->prepare('SELECT id FROM promotion_code_usages WHERE operation_id=? LIMIT 1');
    $dup->execute([$operationId]);
    if ($dup->fetchColumn()) return;

    $promo = null;
    if ($promotionCodeId !== '') {
        $stmt = $pdo->prepare('SELECT id, code FROM promotion_codes WHERE id=? LIMIT 1');
        $stmt->execute([$promotionCodeId]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $resolvedType = tamasyaPromotionCodeUsageTypeFromOperation($operationId, $usageType);
    $actorId = is_array($actor) ? ($actor['id'] ?? null) : (is_scalar($actor) ? (string)$actor : null);
    $actorName = is_array($actor) ? (string)($actor['name'] ?? $actor['username'] ?? '') : null;
    if ($actorName === '') $actorName = null;
    $amountValue = ($amount === null || $amount === '') ? null : (float)$amount;
    $discountAmount = null;
    if (isset($context['discountAmount'])) $discountAmount = (float)$context['discountAmount'];
    elseif (isset($context['discount_amount'])) $discountAmount = (float)$context['discount_amount'];
    $propertyId = trim((string)($context['propertyId'] ?? $context['property_id'] ?? ($GLOBALS['tamasya_property_id'] ?? '')));
    if ($propertyId === '') $propertyId = null;
    $companyId = trim((string)($context['companyId'] ?? $context['company_id'] ?? ''));
    if ($companyId === '') $companyId = null;
    $entityType = trim((string)($context['entityType'] ?? $context['entity_type'] ?? $resolvedType));
    if ($entityType === '') $entityType = $resolvedType;
    $entityId = trim((string)($context['entityId'] ?? $context['entity_id'] ?? $context['referenceId'] ?? ''));
    if ($entityId === '') $entityId = null;

    $id = 'promouse_' . bin2hex(random_bytes(8));
    $fields = ['id', 'promotion_code_id', 'code', 'usage_type', 'source_type', 'source', 'actor_id', 'actor_name', 'actor', 'amount', 'discount_amount', 'operation_id', 'property_id', 'company_id', 'entity_type', 'entity_id', 'created_at'];
    $values = [
        $id,
        $promotionCodeId !== '' ? $promotionCodeId : null,
        $promo['code'] ?? null,
        $resolvedType,
        $resolvedType,
        $resolvedType,
        $actorId,
        $actorName,
        $actorName,
        $amountValue,
        $discountAmount,
        $operationId,
        $propertyId,
        $companyId,
        $entityType,
        $entityId,
        date('Y-m-d H:i:s'),
    ];
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO promotion_code_usages (' . implode(',', $fields) . ') VALUES (' . $placeholders . ')';
    $pdo->prepare($sql)->execute($values);
}

function tamasyaPromotionCodeMarkUsed(PDO $pdo, string $id, string $operationId, $actor = null, $amount = null, ?string $usageType = null, array $context = []): void {
    if ($operationId === '') throw new InvalidArgumentException('Operation ID wajib.');
    if (function_exists('tamasyaPromotionCodesEnabled') && !tamasyaPromotionCodesEnabled()) return;
    if (tamasyaPromotionCodeUsageSchemaReady($pdo)) {
        $dup = $pdo->prepare('SELECT id FROM promotion_code_usages WHERE operation_id=? LIMIT 1');
        $dup->execute([$operationId]);
        if ($dup->fetchColumn()) {
            return; // Already processed: keep idempotent.
        }
    }
    $stmt = $pdo->prepare('UPDATE promotion_codes SET used_count=used_count+1, updated_at=CURRENT_TIMESTAMP WHERE id=? AND (usage_limit IS NULL OR used_count < usage_limit)');
    $stmt->execute([$id]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Kuota kode promo sudah habis.');
    }
    tamasyaPromotionCodeLogUsage($pdo, $id, $operationId, $actor, $amount, $usageType, $context);
}

// Aliases for compatibility with alternate naming used by callers/tests.
function tamasyaPromotionCodeUsageLog(PDO $pdo, string $promotionCodeId, string $operationId, $actor = null, $amount = null, ?string $usageType = null, array $context = []): void {
    tamasyaPromotionCodeLogUsage($pdo, $promotionCodeId, $operationId, $actor, $amount, $usageType, $context);
}
function tamasyaPromotionCodeRecordUsage(PDO $pdo, string $promotionCodeId, string $operationId, $actor = null, $amount = null, ?string $usageType = null, array $context = []): void {
    tamasyaPromotionCodeLogUsage($pdo, $promotionCodeId, $operationId, $actor, $amount, $usageType, $context);
}
function tamasyaPromotionCodeUsageRecord(PDO $pdo, string $promotionCodeId, string $operationId, $actor = null, $amount = null, ?string $usageType = null, array $context = []): void {
    tamasyaPromotionCodeLogUsage($pdo, $promotionCodeId, $operationId, $actor, $amount, $usageType, $context);
}

function tamasyaPromotionCodeUsagesList(PDO $pdo, array $filters = []): array {
    if (!tamasyaPromotionCodeUsageSchemaReady($pdo)) return [];
    $sql = 'SELECT * FROM promotion_code_usages WHERE 1=1';
    $params = [];
    $promoId = trim((string)($filters['promotionCodeId'] ?? $filters['promotion_code_id'] ?? $filters['promoCodeId'] ?? $filters['promo_code_id'] ?? ''));
    if ($promoId !== '') { $sql .= ' AND promotion_code_id=?'; $params[] = $promoId; }
    $usageType = trim((string)($filters['usageType'] ?? $filters['usage_type'] ?? $filters['source'] ?? $filters['sourceType'] ?? $filters['source_type'] ?? ''));
    if ($usageType !== '') { $sql .= ' AND (usage_type=? OR source_type=? OR source=?)'; $params[] = $usageType; $params[] = $usageType; $params[] = $usageType; }
    $actorId = trim((string)($filters['actorId'] ?? $filters['actor_id'] ?? ''));
    if ($actorId !== '') { $sql .= ' AND actor_id=?'; $params[] = $actorId; }
    $operationId = trim((string)($filters['operationId'] ?? $filters['operation_id'] ?? ''));
    if ($operationId !== '') { $sql .= ' AND operation_id=?'; $params[] = $operationId; }
    $propertyId = trim((string)($filters['propertyId'] ?? $filters['property_id'] ?? ''));
    if ($propertyId !== '') { $sql .= ' AND property_id=?'; $params[] = $propertyId; }
    $sql .= ' ORDER BY created_at DESC, id ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function tamasyaPromotionCodeUsageList(PDO $pdo, array $filters = []): array { return tamasyaPromotionCodeUsagesList($pdo, $filters); }
function tamasyaPromotionCodeUsageHistory(PDO $pdo, array $filters = []): array { return tamasyaPromotionCodeUsagesList($pdo, $filters); }
function tamasyaPromotionCodeUsages(PDO $pdo, array $filters = []): array { return tamasyaPromotionCodeUsagesList($pdo, $filters); }
