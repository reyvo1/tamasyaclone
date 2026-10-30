<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
/** Deprecated compatibility flag removed in fresh V137. */
function tamasyaPosWebCompatibilityRepairAllowed(): bool {
    return false;
}

function tamasyaPosRequiredCompatibilityColumns(): array {
    return [
        'pos_sales' => [
            'subtotal_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER guest_name",
            'discount_type' => "VARCHAR(20) NOT NULL DEFAULT 'none' AFTER subtotal_amount",
            'discount_value' => "DECIMAL(15,3) NOT NULL DEFAULT 0 AFTER discount_type",
            'discount_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_value",
            'discount_reason' => "VARCHAR(255) NULL AFTER discount_amount",
            'discount_authorized_by' => "VARCHAR(80) NULL AFTER discount_reason",
            'discount_authorized_by_name' => "VARCHAR(190) NULL AFTER discount_authorized_by",
        ],
        'pos_sale_items' => [
            'original_gross_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER unit_cost",
            'discount_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER original_gross_amount",
        ],
    ];
}

function tamasyaPosTableColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`','``',$table) . '`');
    return array_map('strtolower', $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : []);
}

/** Fresh V137: POS schema is canonical in database_setup.sql; no web compatibility patch. */
function tamasyaPosEnsureSchema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    tamasyaAssertTablesExist($pdo, [
        'pos_categories','pos_products','pos_sales','pos_sale_items',
        'pos_stock_movements','pos_print_logs','pos_delivery_events'
    ], 'POS / Minibar');
    $missing=[];
    foreach (tamasyaPosRequiredCompatibilityColumns() as $table => $columns) {
        $existing = tamasyaPosTableColumns($pdo, $table);
        foreach (array_keys($columns) as $column) {
            if (!in_array(strtolower($column), $existing, true)) $missing[]=$table.'.'.$column;
        }
    }
    if ($missing) throw new DomainException('Schema POS tidak sesuai baseline fresh V137: '.implode(', ',$missing).'. Gunakan database_setup.sql yang sesuai source release ini.');
    $ready = true;
}

function tamasyaPosNumber($value, int $scale = 2): float {
    if (!is_numeric($value)) return 0.0;
    return round((float)$value, $scale);
}


function tamasyaPosActorDisplayName(PDO $pdo, array $actor): string {
    $staffId = trim((string)($actor['id'] ?? ''));
    if ($staffId !== '') {
        try {
            $stmt = $pdo->prepare("SELECT name,username FROM staff WHERE id=? LIMIT 1");
            $stmt->execute([$staffId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') return $name;
            $username = trim((string)($row['username'] ?? ''));
            if ($username !== '') return $username;
        } catch (Throwable $ignored) {}
    }
    $name = trim((string)($actor['name'] ?? ''));
    if ($name !== '' && $name !== $staffId) return $name;
    $username = trim((string)($actor['username'] ?? ''));
    if ($username !== '') return $username;
    return $staffId !== '' ? $staffId : 'Staf';
}

function tamasyaPosSaleSelectSql(string $alias = 's'): string {
    $safeAlias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 's';
    return "{$safeAlias}.*, ".
        "COALESCE(NULLIF(TRIM(st.name),''),NULLIF(TRIM(st.username),''),NULLIF(TRIM({$safeAlias}.created_by_name),''),{$safeAlias}.created_by) AS staff_display_name, ".
        "COALESCE(st.role,'unknown') AS created_by_role";
}

function tamasyaPosOperationId(array $input, string $suffix = ''): string {
    $base = trim((string)($input['operationId'] ?? ($GLOBALS['tamasya_request_operation_id'] ?? '')));
    if ($base === '') throw new InvalidArgumentException('Operation ID wajib tersedia untuk mencegah transaksi POS ganda.');
    return substr($base . ($suffix !== '' ? ':' . $suffix : ''), 0, 120);
}

function tamasyaPosProductRow(array $row): array {
    return [
        'id'=>(string)$row['id'], 'sku'=>(string)$row['sku'], 'name'=>(string)$row['name'],
        'categoryId'=>$row['category_id'] ?? null, 'categoryName'=>$row['category_name'] ?? null,
        'unit'=>(string)($row['unit'] ?? 'pcs'), 'costPrice'=>(float)($row['cost_price'] ?? 0),
        'salePrice'=>(float)($row['sale_price'] ?? 0), 'stockQuantity'=>(float)($row['stock_quantity'] ?? 0),
        'minStock'=>(float)($row['min_stock'] ?? 0), 'taxKind'=>(string)($row['tax_kind'] ?? 'extra'),
        'barcode'=>$row['barcode'] ?? null, 'location'=>(string)($row['location'] ?? ''),
        'notes'=>$row['notes'] ?? null, 'isActive'=>(bool)($row['is_active'] ?? 0),
        'version'=>(int)($row['version'] ?? 1), 'updatedAt'=>$row['updated_at'] ?? null,
        'lowStock'=>(float)($row['stock_quantity'] ?? 0) <= (float)($row['min_stock'] ?? 0),
    ];
}

function tamasyaPosSaleRow(array $row): array {
    return [
        'id'=>(string)$row['id'], 'receiptNumber'=>(string)$row['receipt_number'],
        'status'=>(string)$row['status'], 'paymentMethod'=>(string)$row['payment_method'],
        'bankAccountId'=>$row['bank_account_id'] ?? null, 'bookingId'=>$row['booking_id'] ?? null,
        'roomNumber'=>$row['room_number'] ?? null, 'guestName'=>$row['guest_name'] ?? null,
        'subtotalAmount'=>(float)($row['subtotal_amount'] ?? $row['gross_amount'] ?? 0),
        'discountType'=>(string)($row['discount_type'] ?? 'none'),
        'discountValue'=>(float)($row['discount_value'] ?? 0),
        'discountAmount'=>(float)($row['discount_amount'] ?? 0),
        'discountReason'=>$row['discount_reason'] ?? null,
        'discountAuthorizedByName'=>$row['discount_authorized_by_name'] ?? null,
        'grossAmount'=>(float)$row['gross_amount'], 'baseAmount'=>(float)$row['base_amount'],
        'taxAmount'=>(float)$row['tax_amount'], 'costAmount'=>(float)$row['cost_amount'],
        'itemCount'=>(int)$row['item_count'], 'transactionId'=>$row['transaction_id'] ?? null,
        'bookingExtraId'=>$row['booking_extra_id'] ?? null, 'saleDate'=>(string)$row['sale_date'],
        'createdBy'=>(string)$row['created_by'], 'createdByName'=>(string)(trim((string)($row['staff_display_name'] ?? '')) !== '' ? $row['staff_display_name'] : (trim((string)($row['created_by_name'] ?? '')) !== '' ? $row['created_by_name'] : $row['created_by'])),
        'createdByRole'=>(string)($row['created_by_role'] ?? $row['createdByRole'] ?? 'unknown'),
        'createdAt'=>$row['created_at'] ?? null, 'notes'=>$row['notes'] ?? null,
        'deliveryStatus'=>(string)($row['delivery_status'] ?? (($row['payment_method'] ?? '')==='room_charge'?'created':'not_required')),
        'deliveryNote'=>$row['delivery_note'] ?? null,
        'preparedByName'=>$row['prepared_by_name'] ?? null, 'preparedAt'=>$row['prepared_at'] ?? null,
        'dispatchedByName'=>$row['dispatched_by_name'] ?? null, 'dispatchedAt'=>$row['dispatched_at'] ?? null,
        'deliveredByName'=>$row['delivered_by_name'] ?? null, 'deliveredAt'=>$row['delivered_at'] ?? null,
        'receivedByName'=>$row['received_by_name'] ?? null, 'deliveryUpdatedAt'=>$row['delivery_updated_at'] ?? null,
        'printCount'=>(int)($row['print_count'] ?? 0), 'lastPrintedAt'=>$row['last_printed_at'] ?? null,
        'lastPrintedByName'=>$row['last_printed_by_name'] ?? null,
        'voidReason'=>$row['void_reason'] ?? null, 'voidedByName'=>$row['voided_by_name'] ?? null, 'voidedAt'=>$row['voided_at'] ?? null,
    ];
}

function tamasyaPosVisibleProductRow(array $row, bool $canViewCost): array {
    if (!$canViewCost) unset($row['costPrice']);
    return $row;
}

function tamasyaPosVisibleSaleRow(array $row, bool $canViewCost): array {
    if (!$canViewCost) unset($row['costAmount']);
    return $row;
}

function tamasyaPosVisibleSaleItemRow(array $row, bool $canViewCost): array {
    if (!$canViewCost) {
        unset($row['unit_cost'], $row['cost_amount'], $row['unitCost'], $row['costAmount']);
    }
    return $row;
}

function tamasyaPosInsertTransaction(PDO $pdo, array $tx, array $actor): string {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'posting transaksi POS/minibar');
    if (!$pdo->inTransaction()) throw new RuntimeException('Posting transaksi POS wajib berada dalam transaksi database.');
    $id = (string)($tx['id'] ?? generateServerId('tx_pos'));
    $date = (string)($tx['date'] ?? date('Y-m-d'));
    $type = (string)($tx['type'] ?? 'income');
    $documentPrefix = (string)($tx['documentPrefix'] ?? ($type === 'expense' ? 'EXP' : 'POS'));
    $documentNumber = (string)($tx['documentNumber'] ?? nextDocumentNumber($pdo, $documentPrefix, $date));
    $operationId = (string)$tx['operationId'];
    $posting=[
        'id'=>$id,'type'=>$type,'category'=>(string)$tx['category'],'categoryId'=>$tx['categoryId']??null,'categorySystemKey'=>$tx['categorySystemKey']??null,
        'subcategory'=>$tx['subcategory']??null,'subcategoryId'=>$tx['subcategoryId']??null,'subcategorySystemKey'=>$tx['subcategorySystemKey']??null,
        'roomNumber'=>$tx['roomNumber']??null,'amount'=>tamasyaPosNumber($tx['amount']),'date'=>$date,'serviceDate'=>$date,
        'description'=>(string)$tx['description'],'createdBy'=>(string)($actor['id']??'staff'),'bankAccountId'=>$tx['bankAccountId']??null,
        'baseAmount'=>isset($tx['baseAmount']) && $tx['baseAmount'] !== '' ? tamasyaPosNumber($tx['baseAmount']) : null,'taxAmount'=>tamasyaPosNumber($tx['taxAmount']??0),
        'taxRate'=>tamasyaPosNumber($tx['taxRate']??0,3),'taxSnapshotStatus'=>'confirmed','taxSource'=>(string)($tx['taxSource']??'pos_snapshot'),
        'taxRuleId'=>$tx['taxRuleId']??null,'taxNote'=>$tx['taxNote']??null,'bookingId'=>$tx['bookingId']??null,'bookingSource'=>(string)($tx['bookingSource']??'POS'),
        'transactionKind'=>(string)$tx['transactionKind'],'sourceEntity'=>'pos_sale','sourceEntityId'=>(string)$tx['sourceEntityId'],'isSystemGenerated'=>1,
        'operationId'=>$operationId,'documentNumber'=>$documentNumber,'recordOrigin'=>'live_operation','shiftExempt'=>!empty($tx['shiftExempt'])?1:0,
        'shiftExemptionReason'=>$tx['shiftExemptionReason']??null,'shiftSessionId'=>$tx['shiftSessionId']??null,
        'updatedBy'=>(string)($actor['id']??'staff'),'updatedSource'=>'pos','version'=>1
    ];
    tamasyaPostFinancialTransaction($pdo,$posting,$actor,'pos',['source'=>'pos']);
    return $id;
}

function tamasyaPosMaxOpenShiftHours(): int {
    $value = getenv('TAMASYA_POS_MAX_OPEN_SHIFT_HOURS');
    $hours = ($value === false || trim((string)$value) === '') ? 24 : (int)$value;
    return max(8, min(72, $hours));
}

function tamasyaPosAssertShiftFresh(array $shift, ?DateTimeImmutable $now = null): void {
    $openedAt = trim((string)($shift['opened_at'] ?? ''));
    if ($openedAt === '') throw new RuntimeException('Shift kas aktif tidak memiliki waktu buka yang valid. Tutup atau koreksi shift melalui menu Shift.');
    try { $opened = new DateTimeImmutable($openedAt); }
    catch (Throwable $e) { throw new RuntimeException('Waktu buka shift kas tidak valid. Tutup atau koreksi shift melalui menu Shift.', 0, $e); }
    $now = $now ?? new DateTimeImmutable('now');
    $ageSeconds = $now->getTimestamp() - $opened->getTimestamp();
    if ($ageSeconds < -300) throw new RuntimeException('Waktu buka shift kas berada di masa depan. Koreksi shift melalui menu Shift.');
    $maxHours = tamasyaPosMaxOpenShiftHours();
    if ($ageSeconds > $maxHours * 3600) {
        throw new RuntimeException('Shift kas terbuka sejak '.$opened->format('d-m-Y H:i').' dan sudah melewati '.$maxHours.' jam. Tutup/koreksi shift lama melalui menu Shift sebelum penjualan POS langsung.');
    }
}

function tamasyaPosRequireOpenShift(PDO $pdo, array $actor, bool $required = true): ?array {
    $shift = getOpenShiftForStaff($pdo, (string)($actor['id'] ?? ''), $pdo->inTransaction());
    if (!$shift) {
        if ($required) throw new RuntimeException('Transaksi tunai POS wajib dilakukan pada shift kas yang aktif. Buka shift terlebih dahulu.');
        return null;
    }
    tamasyaPosAssertShiftFresh($shift);
    return $shift;
}

function tamasyaPosPaymentBank(PDO $pdo, string $paymentMethod, ?string $bankAccountId): ?string {
    return tamasyaResolvePaymentAccount($pdo,$paymentMethod,$bankAccountId,[
        'allowedMethods'=>['cash','transfer','qris','card','room_charge'],
        'lock'=>$pdo->inTransaction(),
        'context'=>'Pembayaran POS'
    ]);
}

function tamasyaPosActiveBookings(PDO $pdo): array {
    $sql="SELECT b.id,b.guestName,b.roomNumber,b.roomType,b.checkIn,b.checkOut,b.totalAmount,b.paymentStatus,b.status,b.bookingSource,b.isOpenEnded,
                 COALESCE(b.amountPaid,0) AS amountPaid,COALESCE(b.balanceDue,0) AS balanceDue,r.status AS storedRoomStatus
          FROM bookings b
          LEFT JOIN rooms r ON r.number=b.roomNumber
          WHERE b.status='active' AND TRIM(COALESCE(b.roomNumber,''))<>''
          ORDER BY CAST(b.roomNumber AS UNSIGNED),b.roomNumber,b.guestName LIMIT 300";
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $canonicalMap=tamasyaRoomOperationalStatusMap($pdo,array_column($rows,'roomNumber'));
    return array_map(static function(array $b) use ($canonicalMap): array {
        $roomNumber=(string)$b['roomNumber'];
        $canonical=(string)($canonicalMap[$roomNumber]??'maintenance');
        $stored=strtolower(trim((string)($b['storedRoomStatus']??'')));
        return [
            'id'=>(string)$b['id'],'guestName'=>(string)$b['guestName'],'roomNumber'=>$roomNumber,
            'roomType'=>(string)$b['roomType'],'checkIn'=>(string)$b['checkIn'],'checkOut'=>(string)$b['checkOut'],
            'totalAmount'=>(float)$b['totalAmount'],'amountPaid'=>(float)($b['amountPaid'] ?? 0),'balanceDue'=>(float)($b['balanceDue'] ?? 0),
            'paymentStatus'=>(string)$b['paymentStatus'],'status'=>(string)$b['status'],
            'bookingSource'=>(string)($b['bookingSource'] ?? 'Direct'),'roomStatus'=>$canonical,'storedRoomStatus'=>$b['storedRoomStatus']??null,
            'isOpenEnded'=>!empty($b['isOpenEnded']),
            'isOverdue'=>empty($b['isOpenEnded']) && !empty($b['checkOut']) && (string)$b['checkOut'] < date('Y-m-d'),
            // POS uses canonical lifecycle state. This flag is cache-health only and
            // must never become a financial or access-control decision.
            'roomStatusMismatch'=>$stored!==$canonical
        ];
    },$rows);
}

function tamasyaPosDiscountPlan(float $subtotal, string $discountType, float $discountValue, ?string $discountReason, bool $canDiscount): array {
    $subtotal = round(max(0,$subtotal),2);
    $discountType = strtolower(trim($discountType));
    if (!in_array($discountType,['none','percentage','fixed'],true)) {
        throw new InvalidArgumentException('Jenis diskon POS tidak valid.');
    }
    $discountValue = round(max(0,$discountValue),3);
    $reason = trim((string)$discountReason);
    if ($discountType === 'none' || $discountValue <= 0) {
        return ['type'=>'none','value'=>0.0,'amount'=>0.0,'reason'=>null];
    }
    if (!$canDiscount) throw new RuntimeException('Diskon POS hanya dapat diberikan oleh Manager atau Admin.');
    if ((function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason)) < 5) throw new InvalidArgumentException('Alasan diskon minimal 5 karakter wajib diisi.');
    if ($discountType === 'percentage') {
        if ($discountValue > 99.99) throw new InvalidArgumentException('Diskon persentase maksimal 99,99%.');
        $amount = round($subtotal * $discountValue / 100,2);
    } else {
        $amount = round($discountValue,2);
    }
    if ($amount <= 0) return ['type'=>'none','value'=>0.0,'amount'=>0.0,'reason'=>null];
    if ($amount >= $subtotal) throw new InvalidArgumentException('Diskon harus lebih kecil dari subtotal penjualan.');
    return ['type'=>$discountType,'value'=>$discountValue,'amount'=>$amount,'reason'=>$reason];
}

/** Allocate exact currency cents; independent rounding can over-allocate a small discount. */
function tamasyaPosAllocateDiscount(array $amounts, float $discount): array {
    $cents=array_map(static fn($amount): int => (int)round((float)$amount*100),$amounts);
    $total=array_sum($cents);$target=(int)round($discount*100);
    if(!$cents || min($cents)<0 || $target<0 || $target>$total)throw new InvalidArgumentException('Alokasi diskon POS tidak valid.');
    if($total===0)return array_fill(0,count($cents),0.0);
    $allocated=[];$remainders=[];$used=0;
    foreach($cents as $index=>$amount){
        $exact=$target*($amount/$total);$part=(int)floor($exact);
        $allocated[$index]=$part;$used+=$part;$remainders[$index]=$exact-$part;
    }
    arsort($remainders,SORT_NUMERIC);
    foreach($remainders as $index=>$remainder){
        if($used>=$target)break;
        if($allocated[$index]<$cents[$index]){$allocated[$index]++;$used++;}
    }
    if($used!==$target)throw new RuntimeException('Alokasi diskon POS tidak seimbang.');
    ksort($allocated);
    return array_map(static fn(int $amount): float => $amount/100,$allocated);
}
