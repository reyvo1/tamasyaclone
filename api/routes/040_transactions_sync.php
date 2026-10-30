<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'transactions',
  1 => 'transaction-proof',
  2 => 'sync',
  3 => 'reporting-periods',
  4 => 'historical-backfill-review',
  5 => 'finance-ledger-page',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    // ----------------------------------------------------------------
    // GET /api/finance-ledger-page
    // V137 canonical read-side pagination for the Finance transaction log.
    // IMPORTANT: this endpoint is intentionally READ-ONLY. It does not alter
    // the canonical transactions workflow, report calculations, sync state,
    // schema, or historical retention. The browser uses it only to render a
    // bounded ledger list while canonical full data remains the source
    // for accounting calculations and offline fallback.
    // ----------------------------------------------------------------
    case 'finance-ledger-page':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        if (!tamasyaCanSeeFinancialData($loggedInStaff)) {
            http_response_code(403);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Forbidden: akses keuangan diperlukan.']);
            break;
        }
        $ledgerFrom = trim((string)($_GET['from'] ?? date('Y-m-01')));
        $ledgerTo = trim((string)($_GET['to'] ?? date('Y-m-d')));
        $ledgerType = strtolower(trim((string)($_GET['type'] ?? 'all')));
        $ledgerCategory = trim((string)($_GET['category'] ?? 'all'));
        $ledgerSearch = trim((string)($_GET['search'] ?? ''));
        $ledgerLimit = (int)($_GET['limit'] ?? 20);
        $ledgerLimit = max(10, min(250, $ledgerLimit));
        if (!validIsoDate($ledgerFrom) || !validIsoDate($ledgerTo) || $ledgerFrom > $ledgerTo) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Rentang tanggal Log Keuangan tidak valid.']);
            break;
        }
        if (!in_array($ledgerType, ['all','income','expense'], true)) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Filter tipe transaksi tidak valid.']);
            break;
        }
        if (strlen($ledgerCategory) > 100 || strlen($ledgerSearch) > 80) {
            http_response_code(422);
            echo tamasyaJsonEncode(['success'=>false,'error'=>'Filter Log Keuangan terlalu panjang.']);
            break;
        }
        $ledgerFromTs = strtotime($ledgerFrom . ' 00:00:00');
        $ledgerToTs = strtotime($ledgerTo . ' 00:00:00');
        $ledgerRangeDays = ($ledgerFromTs !== false && $ledgerToTs !== false)
            ? (int)floor(($ledgerToTs - $ledgerFromTs) / 86400) + 1
            : 0;
        // Wildcard text search over multi-year history can force a large scan
        // even with a safe prepared statement. Keep the expensive mode bounded;
        // users can still inspect older history by selecting a narrower period.
        if ($ledgerSearch !== '' && $ledgerRangeDays > 731) {
            http_response_code(422);
            echo tamasyaJsonEncode([
                'success'=>false,
                'error'=>'Untuk pencarian teks, batasi periode maksimal 2 tahun agar database produksi tetap responsif.'
            ]);
            break;
        }
        try {
            $ledgerWhere = ['`date` BETWEEN ? AND ?'];
            $ledgerParams = [$ledgerFrom, $ledgerTo];
            if ($ledgerType !== 'all') {
                $ledgerWhere[] = '`type` = ?';
                $ledgerParams[] = $ledgerType;
            }
            if ($ledgerCategory !== '' && strtolower($ledgerCategory) !== 'all') {
                $ledgerWhere[] = '`category` = ?';
                $ledgerParams[] = $ledgerCategory;
            }
            if ($ledgerSearch !== '') {
                $ledgerLike = '%' . $ledgerSearch . '%';
                $ledgerWhere[] = "(description LIKE ? OR createdBy LIKE ? OR category LIKE ? OR COALESCE(subcategory,'') LIKE ? OR CAST(amount AS CHAR) LIKE ? OR COALESCE(roomNumber,'') LIKE ? OR id LIKE ? OR COALESCE(documentNumber,'') LIKE ?)";
                for ($ledgerI = 0; $ledgerI < 8; $ledgerI++) $ledgerParams[] = $ledgerLike;
            }
            $ledgerSql = "SELECT id,type,category,subcategory,amount,`date`,description,createdBy,roomNumber,documentNumber,transactionKind,recordOrigin,createdAt,
                isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId
                FROM transactions
                WHERE " . implode(' AND ', $ledgerWhere) . "
                ORDER BY `date` DESC, id DESC
                LIMIT " . ($ledgerLimit + 1);
            $ledgerStmt = $pdo->prepare($ledgerSql);
            $ledgerStmt->execute($ledgerParams);
            $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ledgerHasMore = count($ledgerRows) > $ledgerLimit;
            if ($ledgerHasMore) array_pop($ledgerRows);
            foreach ($ledgerRows as &$ledgerRow) {
                $ledgerRow['amount'] = (float)($ledgerRow['amount'] ?? 0);
                // FIX50: expose the split composition so the Log Keuangan can show
                // cash + transfer breakdown instead of one collapsed amount.
                $ledgerRow['isSplitPayment'] = !empty($ledgerRow['isSplitPayment']);
                $ledgerRow['splitCashAmount'] = (float)($ledgerRow['splitCashAmount'] ?? 0);
                $ledgerRow['splitTransferAmount'] = (float)($ledgerRow['splitTransferAmount'] ?? 0);
                $ledgerRow['splitTransferBankAccountId'] = $ledgerRow['splitTransferBankAccountId'] ?? null;
            }
            unset($ledgerRow);
            echo tamasyaJsonEncode([
                'success'=>true,
                'rows'=>$ledgerRows,
                'hasMore'=>$ledgerHasMore,
                'limit'=>$ledgerLimit,
                'range'=>['from'=>$ledgerFrom,'to'=>$ledgerTo,'days'=>$ledgerRangeDays],
                'filters'=>['type'=>$ledgerType,'category'=>$ledgerCategory,'search'=>$ledgerSearch],
                'source'=>'server_read_projection',
                'requestId'=>tamasyaRuntimeRequestId(),
            ]);
        } catch (Throwable $ledgerError) {
            tamasyaApplyExceptionHttpStatus($ledgerError, 500);
            echo tamasyaJsonEncode([
                'success'=>false,
                'error'=>clientExceptionMessage('Log Keuangan tidak dapat dimuat',$ledgerError),
                'requestId'=>tamasyaRuntimeRequestId(),
            ]);
        }
        break;

    case 'transactions':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'POST') {
            $catalogIntent=array_key_exists('catalogIntent',$input) ? $input['catalogIntent'] : null;
            if ($catalogIntent !== null && (!is_array($catalogIntent) || array_is_list($catalogIntent))) {
                http_response_code(422);echo json_encode(['success'=>false,'error'=>'Bukti item/tarif harus berbentuk objek.']);break;
            }
            $type = strtolower(trim((string)($input['type'] ?? '')));
            $categoryId = trim((string)($input['categoryId'] ?? $input['category_id'] ?? '')) ?: null;
            $category = trim((string)($input['category'] ?? ''));
            $subcategoryId = trim((string)($input['subcategoryId'] ?? $input['subcategory_id'] ?? '')) ?: null;
            $subcategory = array_key_exists('subcategory', $input) && $input['subcategory'] !== '' ? $input['subcategory'] : null;
            $categorySystemKey = null;
            $subcategorySystemKey = null;
            $roomNumber = array_key_exists('roomNumber', $input) && $input['roomNumber'] !== '' ? $input['roomNumber'] : null;
            $proofUrl = $input['proofUrl'] ?? null;
            $amount = round((float)($input['amount'] ?? 0), 2);
            $date = (string)($input['date'] ?? date('Y-m-d'));
            $description = trim((string)($input['description'] ?? ''));
            $createdBy = currentStaffLabel($loggedInStaff);
            $bankAccountProvided = array_key_exists('bankAccountId', $input);
            $bankAccountId = $bankAccountProvided && $input['bankAccountId'] !== '' ? $input['bankAccountId'] : null;
            // FIX29: manual/backfill entries may record a split cash+transfer receipt.
            $splitCashAmount = isset($input['splitCashAmount']) && $input['splitCashAmount'] !== '' ? round((float)$input['splitCashAmount'], 2) : 0.0;
            $splitTransferAmount = isset($input['splitTransferAmount']) && $input['splitTransferAmount'] !== '' ? round((float)$input['splitTransferAmount'], 2) : 0.0;
            // FIX51: the manual backfill form keeps the concrete method (qris/transfer)
            // in paymentMethod while both split columns are filled, so the previous
            // detection ignored the split and collapsed the receipt into a single
            // posting (cash-only for qris, error for transfer). Any receipt carrying
            // both a cash and a transfer portion must be treated as split.
            $isSplitPayment = !empty($input['isSplitPayment'])
                || strtolower(trim((string)($input['paymentMethod'] ?? ''))) === 'split'
                || ($splitCashAmount > 0 && $splitTransferAmount > 0);
            $splitTransferBankAccountId = array_key_exists('splitTransferBankAccountId', $input) && $input['splitTransferBankAccountId'] !== '' ? trim((string)$input['splitTransferBankAccountId']) : null;
            // P0 split-payment hardening (2026-09-10): older/cached Finance UIs could
            // expose two settlement selectors at once. Never silently accept two
            // different bank destinations for one cash+bank split receipt.
            if ($isSplitPayment && $splitTransferBankAccountId !== null && $bankAccountId !== null
                && strcasecmp(trim((string)$bankAccountId), $splitTransferBankAccountId) !== 0) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Pembayaran Split memiliki dua akun transfer berbeda. Pilih rekening Transfer/QRIS hanya pada bagian Split lalu simpan ulang."]);
                break;
            }
            // Compatibility for one older client shape: when only bankAccountId is
            // supplied for a split, treat it as the non-cash leg. Current UI sends the
            // canonical splitTransferBankAccountId and leaves bankAccountId null.
            if ($isSplitPayment && $splitTransferBankAccountId === null && $bankAccountId !== null) {
                $splitTransferBankAccountId = trim((string)$bankAccountId);
            }
            $bookingId = array_key_exists('bookingId', $input) && $input['bookingId'] !== '' ? $input['bookingId'] : null;
            $bookingSource = array_key_exists('bookingSource', $input) && $input['bookingSource'] !== '' ? $input['bookingSource'] : null;
            $transactionKind = normalizeTransactionKind((string)($input['transactionKind'] ?? 'manual'));
            $sourceEntity = trim((string)($input['sourceEntity'] ?? '')) ?: null;
            $sourceEntityId = trim((string)($input['sourceEntityId'] ?? '')) ?: null;
            $operationId = trim((string)($input['operationId'] ?? '')) ?: null;
            $baseAmount = isset($input['baseAmount']) && $input['baseAmount'] !== '' ? (float)$input['baseAmount'] : null;
            $taxAmount = isset($input['taxAmount']) && $input['taxAmount'] !== '' ? (float)$input['taxAmount'] : null;
            $taxRate = isset($input['taxRate']) && $input['taxRate'] !== '' ? (float)$input['taxRate'] : null;
            $recordOrigin = strtolower(trim((string)($input['recordOrigin'] ?? 'live_operation')));
            $isHistoricalImport = $recordOrigin === 'historical_import';
            $shiftExempt = $isHistoricalImport ? 1 : 0;
            $shiftExemptionReason = $isHistoricalImport ? trim((string)($input['shiftExemptionReason'] ?? '')) : null;
            $importBatchId = $isHistoricalImport ? trim((string)($input['importBatchId'] ?? '')) : null;
            $requestedDocumentNumber = trim((string)($input['documentNumber'] ?? ''));
            $historicalMetadata = [
                'serviceDate'=>$date,
                'historicalSourceType'=>null,
                'historicalSourceReference'=>null,
                'sourceReportedBy'=>null,
                'periodCorrectionReason'=>null,
            ];
            $periodImpact = [
                'periodKey'=>substr($date,0,7),
                'cashStatus'=>'open','taxStatus'=>'open','periodStatusAtEntry'=>'open',
                'periodImpactStatus'=>'normal','requiresTaxAmendment'=>false,
                'historicalReviewStatus'=>'not_required','warning'=>null,'reportReference'=>null,
            ];
            if (strlen($requestedDocumentNumber) > 80) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Nomor dokumen maksimal 80 karakter."]);
                break;
            }

            if (!in_array($type, ['income', 'expense'], true) || ($category === '' && $categoryId === null) || $amount <= 0 || $description === '' || !validIsoDate($date)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Data transaksi tidak valid. Periksa tipe, kategori, nominal, tanggal, dan deskripsi."]);
                break;
            }
            // Create/edit/offline must use the same active finance catalog and policy.
            try {
                $financeCatalog=tamasyaResolveActiveFinanceCatalogSelection($pdo,$type,$category,$subcategory,false,$categoryId,$subcategoryId);
                $activeCategory=$financeCatalog['category'];
                $categoryId=$financeCatalog['categoryId'];
                $category=$financeCatalog['categoryName'];
                $categorySystemKey=$financeCatalog['categorySystemKey']!==''?$financeCatalog['categorySystemKey']:null;
                $subcategoryId=$financeCatalog['subcategoryId'];
                $subcategory=$financeCatalog['subcategoryName'];
                $subcategorySystemKey=($financeCatalog['subcategorySystemKey']??'')!==''?$financeCatalog['subcategorySystemKey']:null;
                $manualEntryPolicy=tamasyaManualFinanceEntryPolicy($activeCategory,$recordOrigin,array_merge($input,[
                    'type'=>$type,'category'=>$category,'subcategory'=>$subcategory,'description'=>$description,'bookingId'=>$bookingId
                ]));
            } catch (Throwable $catalogValidationError) {
                http_response_code(tamasyaExceptionHttpStatus($catalogValidationError,422));
                echo json_encode(["success"=>false,"error"=>clientExceptionMessage("Validasi master/kebijakan transaksi manual gagal",$catalogValidationError)]);
                break;
            }

            if (!in_array($recordOrigin,['live_operation','historical_import'],true)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Asal pencatatan transaksi tidak valid."]);
                break;
            }
            if (($sourceEntity === null) !== ($sourceEntityId === null)) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Relasi transaksi sumber wajib mengisi sourceEntity dan sourceEntityId bersama-sama."]);
                break;
            }
            if($bookingId!==null){
                // UI may send the explicit booking relation. Accept it only when it
                // exactly matches bookingId, then canonicalize server-side.
                if($sourceEntity!==null && $sourceEntity!=='booking'){
                    http_response_code(422);echo json_encode(["success"=>false,"error"=>"Transaksi yang ditautkan ke booking hanya boleh memakai sourceEntity=booking."]);break;
                }
                if($sourceEntityId!==null && (string)$sourceEntityId!==(string)$bookingId){
                    http_response_code(422);echo json_encode(["success"=>false,"error"=>"sourceEntityId booking tidak sama dengan bookingId."]);break;
                }
                $sourceEntity='booking';$sourceEntityId=(string)$bookingId;
            }elseif($sourceEntity!==null && ($sourceEntity!=='historical_transaction' || !$isHistoricalImport)){
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Relasi transaksi induk hanya tersedia untuk rincian historical_import atau booking canonical."]);
                break;
            }
            if ($isHistoricalImport && (strlen((string)$shiftExemptionReason) < 10 || $date > date('Y-m-d'))) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Data historis wajib memakai tanggal hari ini/masa lalu dan alasan backfill minimal 10 karakter."]);
                break;
            }
            try {
                if ($isHistoricalImport) {
                    $historicalMetadata=tamasyaValidateHistoricalBackfillMetadata($input,$date);
                }
                $periodImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$date,$type,$isHistoricalImport,false);
            } catch (Throwable $historicalValidationError) {
                http_response_code(tamasyaExceptionHttpStatus($historicalValidationError,422));
                echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Validasi metadata transaksi historis gagal',$historicalValidationError)]);
                break;
            }
            if ($operationId === null || strlen($operationId) > 100) {
                http_response_code(422);
                echo json_encode(["success"=>false,"error"=>"Operation ID transaksi wajib dan maksimal 100 karakter."]);
                break;
            }
            if (!in_array($transactionKind,['manual','booking_payment'],true)) {
                http_response_code(409);
                echo json_encode(["success"=>false,"error"=>"Jenis transaksi sistem hanya boleh dibuat dari workflow sumbernya."]);
                break;
            }
            // Generic historical/backfill cash entry is deliberately standalone.
            // Linking it directly to a booking makes bookingLedgerTotals() count the
            // receipt as folio payment before the operator has classified it as
            // room/extension/extra.  That was the source of historical "Layanan
            // Extra" receipts appearing as room payments.  Historical booking
            // attribution must go through the explicit allocation/detail workflow,
            // which owns action, component tax, folio mutation and audit evidence.
            if ($isHistoricalImport && ($bookingId !== null || $transactionKind !== 'manual' || $sourceEntity === 'booking')) {
                http_response_code(409);
                echo json_encode(["success"=>false,"error"=>"Backfill historis generic tidak boleh ditautkan langsung ke reservasi. Simpan sebagai transaksi historis mandiri, lalu gunakan workflow Rincian/Alokasi Booking untuk Kamar, Perpanjangan, atau Layanan Extra."]);
                break;
            }
            $linkedBooking = null;
            if ($bookingId !== null) {
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                $stmt->execute([$bookingId]);
                $linkedBooking = $stmt->fetch();
                if (!$linkedBooking) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Booking terkait tidak ditemukan."]);
                    break;
                }
                if ($type !== 'income' || strtolower((string)($linkedBooking['status']??'')) === 'cancelled') {
                    http_response_code(409);
                    echo json_encode(["success"=>false,"error"=>"Booking hanya boleh menerima pemasukan pada reservasi aktif/selesai. Refund wajib melalui pembatalan."]);
                    break;
                }
                // Only LIVE Finance receipts may be linked directly to the folio.
                // Historical/backfill linkage was rejected above and must use the
                // explicit allocation workflow so component identity cannot be lost.
                $transactionKind='booking_payment';
                $bookingSource=trim((string)($linkedBooking['bookingSource']??''))?:'Direct';
                $sourceEntity='booking';$sourceEntityId=(string)$bookingId;
            }
            if ($taxRate !== null && ($taxRate < 0 || $taxRate > 100)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Tarif pajak tidak valid."]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $historicalParent = null;
                if ($sourceEntity === 'historical_transaction') {
                    $parentStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
                    $parentStmt->execute([$sourceEntityId]);
                    $historicalParent=$parentStmt->fetch(PDO::FETCH_ASSOC)?:null;
                    if(!$historicalParent)throw new InvalidArgumentException('Transaksi historis induk tidak ditemukan.');
                    // Bila pengguna membuka kembali salah satu rincian historis, tautkan rincian baru
                    // ke transaksi induk utama agar relasi tidak membentuk rantai anak-ke-anak.
                    if((string)($historicalParent['sourceEntity']??'')==='historical_transaction' && trim((string)($historicalParent['sourceEntityId']??''))!==''){
                        $canonicalParentId=trim((string)$historicalParent['sourceEntityId']);
                        $canonicalParentStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
                        $canonicalParentStmt->execute([$canonicalParentId]);
                        $canonicalParent=$canonicalParentStmt->fetch(PDO::FETCH_ASSOC)?:null;
                        if(!$canonicalParent)throw new InvalidArgumentException('Transaksi historis induk utama tidak ditemukan.');
                        $historicalParent=$canonicalParent;
                        $sourceEntityId=$canonicalParentId;
                    }
                    if(!tamasyaTransactionIsHistorical($historicalParent))throw new InvalidArgumentException('Rincian historis hanya boleh ditautkan ke transaksi historical_import.');
                    if(strtolower((string)($historicalParent['type']??''))!=='income')throw new InvalidArgumentException('Transaksi historis induk harus berupa pemasukan.');
                    $parentRoom=trim((string)($historicalParent['roomNumber']??''));
                    if($parentRoom!=='' && $roomNumber!==null && trim((string)$roomNumber)!==$parentRoom){
                        throw new InvalidArgumentException('Kamar rincian historis berbeda dengan transaksi induk.');
                    }
                    if($roomNumber===null && $parentRoom!=='')$roomNumber=$parentRoom;
                    if($bookingSource===null && trim((string)($historicalParent['bookingSource']??''))!=='')$bookingSource=(string)$historicalParent['bookingSource'];
                    // Inherit the parent settlement account only when the client omitted
                    // bankAccountId entirely. An explicit null/empty value means cash and
                    // must be allowed to correct a parent that was previously posted to OTA/bank.
                    if(!$bankAccountProvided && $bankAccountId===null && trim((string)($historicalParent['bankAccountId']??''))!=='')$bankAccountId=(string)$historicalParent['bankAccountId'];
                }
                if ($linkedBooking) {
                    $lockBooking=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
                    $lockBooking->execute([$bookingId]);
                    $linkedBooking=$lockBooking->fetch(PDO::FETCH_ASSOC);
                    if(!$linkedBooking)throw new RuntimeException('Booking terkait tidak ditemukan saat transaksi dikunci.');
                    $ledgerBefore=bookingLedgerTotals($pdo,$bookingId);
                    $remaining=max(0,(float)$linkedBooking['totalAmount']-$ledgerBefore['net']);
                    if($amount>$remaining+0.01){
                        http_response_code(409);
                        throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan booking Rp '.number_format($remaining,0,',','.').'.');
                    }
                }
                // Normalize settlement evidence before idempotency comparison.  Source
                // labels describe provenance; the account/method describes where money
                // actually settled.  A Traveloka booking paid in cash therefore stays cash.
                $bankAccountId=tamasyaValidateFinanceBankAccount($pdo,$bankAccountId,true,true);
                if($isSplitPayment){
                    if($type!=='income'){
                        http_response_code(422);
                        throw new InvalidArgumentException('Pembayaran split hanya tersedia untuk transaksi pemasukan.');
                    }
                    $splitTransferBankAccountId=trim((string)$splitTransferBankAccountId);
                    if($splitCashAmount<=0 || $splitTransferAmount<=0 || $splitTransferBankAccountId===''
                        || !moneyMatches($splitCashAmount+$splitTransferAmount,$amount,0.01)){
                        http_response_code(422);
                        throw new InvalidArgumentException('Nominal split wajib sama dengan nominal transaksi; tunai dan transfer harus keduanya lebih dari 0 dan akun transfer/QRIS dipilih.');
                    }
                    tamasyaInferPaymentMethodFromAccount($pdo,$splitTransferBankAccountId,true,['transfer','qris']);
                    $splitTransferBankAccountId=tamasyaValidateFinanceBankAccount($pdo,$splitTransferBankAccountId,true,true);
                    // A single canonical split row carries both settlement legs.  The
                    // top-level account is intentionally cash/null; journal and shift
                    // projections read the durable split snapshot below.
                    $bankAccountId=null;
                }else{
                    $splitCashAmount=0.0;
                    $splitTransferAmount=0.0;
                    $splitTransferBankAccountId=null;
                }
                tamasyaValidateManualOtaReceivableContext([
                    'type'=>$type,'bankAccountId'=>$bankAccountId,
                    'bookingSource'=>$bookingSource ?: ($linkedBooking['bookingSource']??'Direct')
                ],$recordOrigin);

                // Replay must prove the complete financial intent, not merely amount/type.
                // This prevents an operationId previously used for cash from being reused
                // for OTA/bank/source/category/split data with a different economic effect.
                $replayIncoming=[
                    'type'=>$type,'category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                    'subcategory'=>$subcategory,'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,
                    'amount'=>$amount,'date'=>$date,'description'=>$description,'proofUrl'=>$proofUrl,'roomNumber'=>$roomNumber,
                    'bankAccountId'=>$bankAccountId,'bookingId'=>$bookingId,'bookingSource'=>$bookingSource,
                    'transactionKind'=>$transactionKind,'recordOrigin'=>$recordOrigin,'sourceEntity'=>$sourceEntity,'sourceEntityId'=>$sourceEntityId,
                    'isSplitPayment'=>$isSplitPayment?1:0,'splitCashAmount'=>$splitCashAmount,'splitTransferAmount'=>$splitTransferAmount,
                    'splitTransferBankAccountId'=>$splitTransferBankAccountId,'serviceDate'=>$historicalMetadata['serviceDate'],
                    'historicalSourceType'=>$historicalMetadata['historicalSourceType'],'historicalSourceReference'=>$historicalMetadata['historicalSourceReference'],
                    'sourceReportedBy'=>$historicalMetadata['sourceReportedBy'],'importBatchId'=>$importBatchId,
                    'shiftExemptionReason'=>$shiftExemptionReason,'periodCorrectionReason'=>$historicalMetadata['periodCorrectionReason'],
                ];
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE operationId = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$operationId]);
                $existing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
                if ($existing) {
                    tamasyaAssertManualTransactionReplayMatches($existing,$replayIncoming);
                    tamasyaCatalogAssertTransactionReplay($pdo,(string)$existing['id'],$catalogIntent);
                    if($bookingId){
                        recalculateBookingFinancials($pdo,$bookingId,true);
                        assertBookingLedgerInvariant($pdo,$bookingId,$loggedInStaff,'web',false);
                    }
                    tamasyaFinancialCommit($pdo);
                    echo json_encode(["success" => true, "duplicate" => true, "transactionId" => $existing['id'], "db" => getRoleScopedHotelData($pdo, $loggedInStaff)]);
                    break;
                }
                // Resolve period/tax only after canonical rows are locked and duplicate
                // replay has been ruled out. This keeps retry idempotency stable and
                // prevents a concurrent tax-rule/reporting-period edit from changing
                // the snapshot between read and commit.
                if($isHistoricalImport){
                    $periodImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$date,$type,true,true);
                }
                // FIX44: tax resolution must see the canonical catalog identity the
                // server just locked (categoryId/categorySystemKey), not only the raw
                // client body. The old `$input+[...]` union let stale client fields
                // override server-canonical values, so a backfilled "Layanan Extra"
                // receipt lost its extra_service identity: the tax kind degraded to
                // transaction_kind ('booking_payment'/'booking_charge' -> wildcard '*')
                // and the generic '*' PBJT rule won over the explicit kind='extra'
                // no-tax rule, while the UI preview (which knows the systemKey)
                // displayed "bebas pajak". array_merge keeps server authority.
                $taxPayload=array_merge($input,[
                    'type'=>$type,'category'=>$category,'subcategory'=>$subcategory,'description'=>$description,
                    'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                    'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,
                    'bookingSource'=>$bookingSource ?: ($linkedBooking['bookingSource']??'Direct'),
                    'transactionKind'=>$transactionKind,'date'=>$date
                ]);
                $taxSnapshot=tamasyaResolveTransactionTaxSnapshot($pdo,$taxPayload,(float)$amount,$isHistoricalImport);
                $baseAmount=$taxSnapshot['baseAmount'];
                $taxAmount=$taxSnapshot['taxAmount'];
                $taxRate=$taxSnapshot['taxRate'];
                $taxSnapshotStatus=$taxSnapshot['taxSnapshotStatus'];
                $taxSource=$taxSnapshot['taxSource'];
                $taxRuleId=$taxSnapshot['taxRuleId'];
                $taxRuleName=$taxSnapshot['taxRuleName']??null;
                $taxNote=$type==='income' ? (trim((string)($input['taxNote']??''))?:null) : null;
                // Revalidate master/rate under the item row lock, in the existing DB txn.
                $catalogSnapshot=$catalogIntent!==null ? tamasyaCatalogTransactionIntent($pdo,$catalogIntent,[
                    'transactionKind'=>$transactionKind,'recordOrigin'=>$recordOrigin,'bookingId'=>$bookingId,
                    'sourceEntity'=>$sourceEntity,'sourceEntityId'=>$sourceEntityId,'categoryId'=>$categoryId,
                    'subcategoryId'=>$subcategoryId,'type'=>$type,'date'=>$date,'amount'=>$amount
                ]) : null;
                $txId = generateServerId('tx_manual');
                $documentPrefix = $type === 'expense' ? 'EXP' : 'PAY';
                $documentNumber = $requestedDocumentNumber !== '' ? $requestedDocumentNumber : nextDocumentNumber($pdo,$documentPrefix,$date);
                // A Finance screen may capture a receipt that economically belongs to a booking.
                // Persistence ownership follows transactionKind, not the UI route that collected it.
                $postingAuthority=in_array($transactionKind,['booking_payment','booking_charge'],true)?'booking':'finance_manual';
                $baseTx=[
                    'type'=>$type,'category'=>$category,'categoryId'=>$categoryId,'categorySystemKey'=>$categorySystemKey,
                    'subcategory'=>$subcategory,'subcategoryId'=>$subcategoryId,'subcategorySystemKey'=>$subcategorySystemKey,'roomNumber'=>$roomNumber,
                    'date'=>$date,'serviceDate'=>$historicalMetadata['serviceDate'],'description'=>$description,'proofUrl'=>$proofUrl,
                    'createdBy'=>$createdBy,'baseAmount'=>$baseAmount,'taxAmount'=>$taxAmount,'taxRate'=>$taxRate,
                    'taxSnapshotStatus'=>$taxSnapshotStatus,'taxSource'=>$taxSource,'taxRuleId'=>$taxRuleId,'taxRuleName'=>$taxRuleName,'taxNote'=>$taxNote,'bookingId'=>$bookingId,
                    'bookingSource'=>$bookingSource,'transactionKind'=>$transactionKind,'isSystemGenerated'=>0,
                    'recordOrigin'=>$recordOrigin,'shiftExempt'=>$shiftExempt,'shiftExemptionReason'=>$shiftExemptionReason,
                    'importBatchId'=>$importBatchId,'historicalSourceType'=>$historicalMetadata['historicalSourceType'],
                    'historicalSourceReference'=>$historicalMetadata['historicalSourceReference'],'sourceReportedBy'=>$historicalMetadata['sourceReportedBy'],
                    'sourceEntity'=>$sourceEntity,'sourceEntityId'=>$sourceEntityId,'reportingPeriod'=>$periodImpact['periodKey'],
                    'periodStatusAtEntry'=>$periodImpact['periodStatusAtEntry'],'periodImpactStatus'=>$periodImpact['periodImpactStatus'],
                    'requiresTaxAmendment'=>!empty($periodImpact['requiresTaxAmendment'])?1:0,'historicalReviewStatus'=>$periodImpact['historicalReviewStatus'],
                    'periodCorrectionReason'=>$historicalMetadata['periodCorrectionReason'],'inputDelayDays'=>$isHistoricalImport?tamasyaBackfillInputDelayDays($date):0,
                    'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
                ];
                $postingOptions=['requireCatalog'=>true,'lockCatalog'=>true,'source'=>'web'];
                // Resolve the operating shift BEFORE posting so the cash-coverage guard
                // inside tamasyaPostFinancialTransaction() can see it. The shift used to
                // be attached after the row was written, so the guard received an empty
                // shiftSessionId, returned early, and an expense could drive the open
                // drawer below zero and surface later as an unexplained closing variance.
                // When no shift is open this stays null and the existing guard below
                // still rejects the save, so behaviour for the no-shift case is unchanged.
                $requiresOpenShift=tamasyaManualTransactionRequiresOpenShift([
                    'type'=>$type,'amount'=>$amount,'bankAccountId'=>$bankAccountId,
                    'isSplitPayment'=>$isSplitPayment?1:0,
                    'splitCashAmount'=>$isSplitPayment?$splitCashAmount:0,
                ]);
                $liveShiftSessionId=null;
                if(!$isHistoricalImport && $requiresOpenShift){
                    $openShiftForCoverage=getOpenShiftForStaff($pdo,(string)($loggedInStaff['id']??''),false);
                    $liveShiftSessionId=$openShiftForCoverage?(string)$openShiftForCoverage['id']:null;
                }
                // Manual/backfill split uses ONE canonical transaction row on every
                // path (online and offline).  The journal expands the durable snapshot
                // into cash + bank debit legs, so accounting stays native without sibling
                // rows that can be orphaned by edit/delete/retry.
                tamasyaPostFinancialTransaction($pdo,array_merge($baseTx,[
                    'id'=>$txId,'amount'=>$amount,'bankAccountId'=>$bankAccountId,
                    'isSplitPayment'=>$isSplitPayment?1:0,
                    'splitCashAmount'=>$isSplitPayment?$splitCashAmount:0,
                    'splitTransferAmount'=>$isSplitPayment?$splitTransferAmount:0,
                    'splitTransferBankAccountId'=>$isSplitPayment?$splitTransferBankAccountId:null,
                    'documentNumber'=>$documentNumber,'operationId'=>$operationId,
                    'shiftSessionId'=>$liveShiftSessionId,
                ]),$loggedInStaff,$postingAuthority,$postingOptions);
                if ($catalogSnapshot!==null) tamasyaCatalogSaveTransactionSnapshot($pdo,$txId,$operationId,$catalogSnapshot);
                if(!$isHistoricalImport && $requiresOpenShift && !getOpenShiftForStaff($pdo,(string)($loggedInStaff['id']??''),false)){
                    throw new RuntimeException('Transaksi tunai masih berstatus operasional live dan wajib memiliki shift aktif. Pilih akun transfer/QRIS untuk transaksi non-tunai, atau buka shift kas. Untuk arsip lama, pilih Mode Data Historis / Backfill.');
                }
                if(!$isHistoricalImport){
                    attachTransactionToActorShift($pdo,$txId,$loggedInStaff,$requiresOpenShift);
                }
                if($bookingId && !$isHistoricalImport){
                    $allocationLedgerNet=(float)($ledgerBefore['net']??0);
                    tamasyaAttachBookingReceiptRevenueAllocations($pdo,$linkedBooking,$txId,$amount,$allocationLedgerNet,$operationId,$loggedInStaff,'web',$date);
                }
                if($bookingId){
                    recalculateBookingFinancials($pdo,$bookingId,true);
                    assertBookingLedgerInvariant($pdo,$bookingId,$loggedInStaff,'web',false);
                }
                $transactionAfterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$transactionAfterStmt->execute([$txId]);$transactionAfter=$transactionAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if($isHistoricalImport && $transactionAfter){
                    tamasyaRegisterHistoricalBackfillAdjustment($pdo,$transactionAfter,$periodImpact,$loggedInStaff,(string)$shiftExemptionReason,$operationId);
                }
                // Jurnal adalah proyeksi turunan transaksi dan diperbarui di transaksi DB yang sama.
                // Hanya transaksi baru/berubah (source_version berbeda) yang diproyeksikan ulang.
                syncJournalProjections($pdo,true);
                $createAuditSnapshot=tamasyaTransactionAuditSnapshot($transactionAfter)??[];
                $createAuditSnapshot['auditContext']=[
                    'entryMode'=>$isHistoricalImport?'historical_backfill':'manual_live_operation',
                    'reason'=>$isHistoricalImport?$shiftExemptionReason:(trim((string)($input['auditReason']??''))?:'Pencatatan transaksi manual operasional'),
                    'importBatchId'=>$importBatchId,
                    'historicalParentTransactionId'=>$sourceEntity==='historical_transaction'?$sourceEntityId:null,
                    'splitStorageMode'=>$isSplitPayment?'single_canonical_row':null,
                    'splitCashAmount'=>$isSplitPayment?$splitCashAmount:null,
                    'splitTransferAmount'=>$isSplitPayment?$splitTransferAmount:null,
                    'splitTransferBankAccountId'=>$isSplitPayment?$splitTransferBankAccountId:null,
                ];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$isHistoricalImport?'Mencatat backfill transaksi historis':'Mencatat transaksi manual','transaction',$txId,null,$createAuditSnapshot,'web');

                $notifId = generateServerId('n');
                $sign = $type === 'income' ? 'Pemasukan (+)' : 'Pengeluaran (-)';
                $notifMsg = ($isHistoricalImport?'Data historis':'Transaksi manual')." dicatat oleh {$createdBy}: {$sign} Rp " . number_format($amount, 0, ',', '.') . " ({$description}).";
                $stmt = $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'finance')");
                $stmt->execute([$notifId,$notifMsg,date('Y-m-d H:i:s')]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                $emoji = $type === 'income' ? '📥 *PEMASUKAN BARU*' : '📤 *PENGELUARAN BARU*';
                $tgMsg = $emoji . ($isHistoricalImport?' (Backfill Historis)':' (Aplikasi)')."\n\n📁 Kategori: *{$category}*" . ($subcategory ? " > {$subcategory}" : '') . "\n💰 Nominal: *Rp " . number_format($amount,0,',','.') . "*\n📝 Deskripsi: *{$description}*\n👤 Dicatat: *{$createdBy}*\n📅 Tanggal: {$date}";
                if(!$isHistoricalImport)broadcastTelegramNotification($pdo, $tgMsg, true);
                echo json_encode(["success" => true, "message" => "Transaksi tersimpan.", "transactionId" => $txId, "documentNumber"=>$documentNumber, "periodImpact"=>$periodImpact, "warning"=>tamasyaMergeFinanceWarnings($periodImpact['warning']??null,$manualEntryPolicy['warnings']??[]), "db" => getRoleScopedHotelData($pdo, $loggedInStaff)]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal menyimpan transaksi", $e)]);
            }
        } elseif ($method === 'PUT') {
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if ($id === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "ID transaksi wajib diisi."]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $oldTx = $stmt->fetch();
                if (!$oldTx) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(["success" => false, "error" => "Transaksi tidak ditemukan."]);
                    break;
                }
                if (!empty($oldTx['lockedAt'])) {
                    $pdo->rollBack();
                    http_response_code(423);
                    echo json_encode(["success" => false, "error" => "Transaksi sudah dikunci oleh proses tutup shift. Buat transaksi penyesuaian agar audit tetap utuh."]);
                    break;
                }
                // Catalog-attributed receipts have immutable price snapshots. Use a separate
                // correction/reversal, never rewrite the original economic identity.
                if (tamasyaCatalogTransactionHasSnapshot($pdo,(string)$id)) {
                    throw new DomainException('Transaksi katalog berjejak tidak bisa diedit langsung. Gunakan pembatalan atau transaksi koreksi resmi.');
                }
                if (transactionIsProtected($oldTx)) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(["success" => false, "error" => "Transaksi otomatis harus diperbaiki dari modul sumbernya, bukan dari Log Kas."]);
                    break;
                }
                $changeReason=trim((string)($input['changeReason']??''));
                if(tamasyaStringLength($changeReason)<8){
                    $pdo->rollBack();
                    http_response_code(422);
                    echo json_encode(["success"=>false,"error"=>"Alasan perubahan wajib diisi minimal 8 karakter agar audit transaksi manual lengkap."]);
                    break;
                }
                if(trim((string)($oldTx['documentNumber']??''))===''){
                    $legacyPrefix=strtolower((string)($oldTx['type']??''))==='expense'?'EXP':'PAY';
                    $oldTx['documentNumber']=nextDocumentNumber($pdo,$legacyPrefix,$oldTx['date']??date('Y-m-d'));
                    tamasyaEnsureTransactionDocumentNumber($pdo,$id,(string)$oldTx['documentNumber'],$loggedInStaff);
                }
                $isHistoricalTx=tamasyaTransactionIsHistorical($oldTx);
                $finalType = strtolower((string)($input['type'] ?? $oldTx['type']));
                $finalCategoryId = trim((string)($input['categoryId'] ?? $input['category_id'] ?? $oldTx['categoryId'] ?? '')) ?: null;
                $finalCategory = trim((string)($input['category'] ?? $oldTx['category'] ?? ''));
                $finalSubcategoryId = trim((string)($input['subcategoryId'] ?? $input['subcategory_id'] ?? $oldTx['subcategoryId'] ?? '')) ?: null;
                $finalSubcategory = array_key_exists('subcategory',$input) ? (trim((string)$input['subcategory'])?:null) : (($oldTx['subcategory']??null)!==null?(trim((string)$oldTx['subcategory'])?:null):null);
                $finalCategorySystemKey = null;
                $finalSubcategorySystemKey = null;
                $finalAmount = array_key_exists('amount',$input) ? round((float)$input['amount'],2) : round((float)$oldTx['amount'],2);
                $finalDate = (string)($input['date'] ?? $oldTx['date']);
                $finalDescription = trim((string)($input['description'] ?? $oldTx['description']));
                if (!in_array($finalType,['income','expense'],true) || ($finalCategory==='' && $finalCategoryId===null) || $finalAmount<=0 || $finalDescription==='' || !validIsoDate($finalDate)) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Data perubahan transaksi tidak valid."]);
                    break;
                }
                try{
                    $editCatalog=tamasyaResolveActiveFinanceCatalogSelection($pdo,$finalType,$finalCategory,$finalSubcategory,true,$finalCategoryId,$finalSubcategoryId);
                    $finalCategoryRow=$editCatalog['category'];
                    $finalCategoryId=$editCatalog['categoryId'];
                    $finalCategory=$editCatalog['categoryName'];
                    $finalCategorySystemKey=($editCatalog['categorySystemKey']??'')!==''?$editCatalog['categorySystemKey']:null;
                    $finalSubcategoryId=$editCatalog['subcategoryId'];
                    $finalSubcategory=$editCatalog['subcategoryName'];
                    $finalSubcategorySystemKey=($editCatalog['subcategorySystemKey']??'')!==''?$editCatalog['subcategorySystemKey']:null;
                    $input['categoryId']=$finalCategoryId;
                    $input['categorySystemKey']=$finalCategorySystemKey;
                    $input['category']=$finalCategory;
                    $input['subcategoryId']=$finalSubcategoryId;
                    $input['subcategorySystemKey']=$finalSubcategorySystemKey;
                    $input['subcategory']=$finalSubcategory;
                }catch(Throwable $catalogEditError){
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($catalogEditError,422));
                    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Validasi kategori perubahan transaksi gagal',$catalogEditError)]);break;
                }
                $historicalMetadata=[
                    'serviceDate'=>$oldTx['serviceDate']??$finalDate,
                    'historicalSourceType'=>$oldTx['historicalSourceType']??null,
                    'historicalSourceReference'=>$oldTx['historicalSourceReference']??null,
                    'sourceReportedBy'=>$oldTx['sourceReportedBy']??null,
                    'periodCorrectionReason'=>$oldTx['periodCorrectionReason']??null,
                ];
                $periodImpact=[
                    'periodKey'=>$oldTx['reportingPeriod']??substr($finalDate,0,7),
                    'cashStatus'=>$oldTx['periodStatusAtEntry']??'open','taxStatus'=>$oldTx['periodStatusAtEntry']??'open',
                    'periodStatusAtEntry'=>$oldTx['periodStatusAtEntry']??'open',
                    'periodImpactStatus'=>$oldTx['periodImpactStatus']??($isHistoricalTx?'baseline_preserved':'normal'),
                    'requiresTaxAmendment'=>!empty($oldTx['requiresTaxAmendment']),
                    'historicalReviewStatus'=>$oldTx['historicalReviewStatus']??($isHistoricalTx?'verified':'not_required'),
                    'warning'=>null,'reportReference'=>null,
                ];
                try{
                    if($isHistoricalTx){
                        $historicalMetadata=tamasyaValidateHistoricalBackfillMetadata(array_merge($oldTx,$input),$finalDate);
                        $periodImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$finalDate,$finalType,true,true);
                    }
                }catch(Throwable $historicalValidationError){
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($historicalValidationError,422));
                    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Validasi perubahan transaksi historis gagal',$historicalValidationError)]);break;
                }

                $approvalId=requireSensitiveApproval($pdo,$loggedInStaff,'edit_transaction','transaction',$id,$finalAmount,$input);
                if ($approvalId===false) { tamasyaFinancialCommit($pdo); break; }

                $finalBookingId = array_key_exists('bookingId', $input) ? trim((string)$input['bookingId']) : trim((string)($oldTx['bookingId'] ?? ''));
                $finalRoomNumber = array_key_exists('roomNumber', $input) ? trim((string)$input['roomNumber']) : trim((string)($oldTx['roomNumber'] ?? ''));
                $finalBookingSource = array_key_exists('bookingSource', $input) ? trim((string)$input['bookingSource']) : trim((string)($oldTx['bookingSource'] ?? ''));
                $linkedBooking = null;
                if ($finalBookingId !== '') {
                    $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
                    $stmtBooking->execute([$finalBookingId]);
                    $linkedBooking = $stmtBooking->fetch(PDO::FETCH_ASSOC)?:null;
                    if (!$linkedBooking) {
                        $pdo->rollBack();http_response_code(400);
                        echo json_encode(["success" => false, "error" => "Booking terkait tidak ditemukan."]);break;
                    }
                    // bookingSource on a linked transaction is never client-authoritative.
                    $finalBookingSource=trim((string)($linkedBooking['bookingSource']??''))?:'Direct';
                    $input['bookingSource']=$finalBookingSource;
                }
                try{
                    $editManualPolicy=tamasyaManualFinanceEntryPolicy($finalCategoryRow,$isHistoricalTx?'historical_import':'live_operation',array_merge($oldTx,$input,[
                        'type'=>$finalType,'category'=>$finalCategory,'subcategory'=>$finalSubcategory,'description'=>$finalDescription,'bookingId'=>$finalBookingId
                    ]));
                }catch(Throwable $editPolicyError){
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($editPolicyError,422));
                    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Kebijakan perubahan transaksi menolak input',$editPolicyError)]);break;
                }
                $allocationState=tamasyaActiveTransactionAllocationState($pdo, $id, true);
                $activeAllocated=round((float)($allocationState['allocated_amount'] ?? 0),2);
                if($activeAllocated>0){
                    if($finalType!=='income'){
                        $pdo->rollBack();http_response_code(409);
                        echo json_encode(['success'=>false,'error'=>'Transaksi yang mempunyai rincian aktif harus tetap berupa pemasukan. Batalkan rincian terlebih dahulu.']);break;
                    }
                    if($finalAmount+0.01<$activeAllocated){
                        $pdo->rollBack();http_response_code(409);
                        echo json_encode(['success'=>false,'error'=>'Nominal transaksi tidak boleh lebih kecil dari total rincian aktif Rp '.number_format($activeAllocated,0,',','.').'.']);break;
                    }
                    // Arsip reporting_only tidak mengubah relasi booking operasional.
                    // Hanya allocation operasional yang mengunci booking/kamar/sumber.
                    $operationalAllocated=round((float)($allocationState['operational_allocated_amount']??0),2);
                    if($operationalAllocated>0){
                        $allocationBookingId=trim((string)($allocationState['operational_booking_id'] ?? ''));
                        if((int)($allocationState['operational_booking_count'] ?? 0)>1 || ($allocationBookingId!=='' && $finalBookingId!==$allocationBookingId)){
                            $pdo->rollBack();http_response_code(409);
                            echo json_encode(['success'=>false,'error'=>'Relasi booking operasional tidak boleh diubah selama transaksi masih mempunyai rincian aktif.']);break;
                        }
                        if($finalRoomNumber!==trim((string)($oldTx['roomNumber'] ?? ''))){
                            $pdo->rollBack();http_response_code(409);
                            echo json_encode(['success'=>false,'error'=>'Kamar booking operasional tidak boleh diubah selama transaksi masih mempunyai rincian aktif. Batalkan rincian operasional terlebih dahulu.']);break;
                        }
                    }
                }
                // Generic Log Kas hanya boleh menghasilkan transaksi manual atau
                // pembayaran booking yang sah. Jangan biarkan edit manual mengubah
                // kind menjadi workflow sistem, atau menautkan expense ke booking.
                $requestedFinalKind=normalizeTransactionKind((string)($input['transactionKind'] ?? ($oldTx['transactionKind'] ?? 'manual')));
                if($isHistoricalTx && $finalBookingId!==''){
                    $pdo->rollBack();http_response_code(409);
                    echo json_encode(['success'=>false,'error'=>'Transaksi historis tidak boleh ditautkan ke booking lewat Edit Transaksi Kas. Gunakan workflow rincian/alokasi booking agar ledger historis tetap konsisten.']);break;
                }
                if($finalBookingId!==''){
                    if($finalType!=='income' || strtolower((string)($linkedBooking['status']??''))==='cancelled'){
                        $pdo->rollBack();http_response_code(409);
                        echo json_encode(['success'=>false,'error'=>'Booking hanya boleh menerima pemasukan pada reservasi yang tidak dibatalkan.']);break;
                    }
                    $input['transactionKind']='booking_payment';
                    $finalBookingSource=trim((string)($linkedBooking['bookingSource']??''))?:'Direct';
                    $input['bookingSource']=$finalBookingSource;
                }else{
                    if($requestedFinalKind!=='manual'){
                        $pdo->rollBack();http_response_code(409);
                        echo json_encode(['success'=>false,'error'=>'Jenis transaksi otomatis harus diperbaiki dari modul sumbernya; Edit Transaksi Kas hanya untuk transaksi manual.']);break;
                    }
                    $input['transactionKind']='manual';
                }
                // Canonicalize settlement evidence for edits.  Explicit null/empty means
                // CASH and must clear a previous ota_receivable/bank value; omission means
                // preserve the old value.  This fixes historical rows that previously could
                // not be corrected from a mistaken OTA receivable back to cash.
                try{
                    $effectiveManualBank=array_key_exists('bankAccountId',$input)
                        ? tamasyaValidateFinanceBankAccount($pdo,$input['bankAccountId'],true,true)
                        : tamasyaValidateFinanceBankAccount($pdo,$oldTx['bankAccountId']??null,true,true);
                    if(array_key_exists('bankAccountId',$input))$input['bankAccountId']=$effectiveManualBank;

                    $splitEditRequested=array_key_exists('isSplitPayment',$input)
                        || array_key_exists('splitCashAmount',$input)
                        || array_key_exists('splitTransferAmount',$input)
                        || array_key_exists('splitTransferBankAccountId',$input);
                    $effectiveSplitActive=$splitEditRequested
                        ? (!empty($input['isSplitPayment']) || (float)($input['splitCashAmount']??0)>0 || (float)($input['splitTransferAmount']??0)>0)
                        : !empty($oldTx['isSplitPayment']);
                    $effectiveSplitCash=$effectiveSplitActive
                        ? round((float)($splitEditRequested?($input['splitCashAmount']??0):($oldTx['splitCashAmount']??0)),2)
                        : 0.0;
                    $effectiveSplitTransfer=$effectiveSplitActive
                        ? round((float)($splitEditRequested?($input['splitTransferAmount']??0):($oldTx['splitTransferAmount']??0)),2)
                        : 0.0;
                    $effectiveSplitBank=$effectiveSplitActive
                        ? trim((string)($splitEditRequested?($input['splitTransferBankAccountId']??''):($oldTx['splitTransferBankAccountId']??'')))
                        : '';

                    if($effectiveSplitActive){
                        if($finalType!=='income'){
                            // Expense cannot carry a receipt split. Changing an old split
                            // income to expense clears the snapshot instead of leaving stale legs.
                            $effectiveSplitActive=false;
                            $effectiveSplitCash=0.0;$effectiveSplitTransfer=0.0;$effectiveSplitBank='';
                        }elseif(!$splitEditRequested && !moneyMatches($effectiveSplitCash+$effectiveSplitTransfer,(float)$finalAmount,0.01)){
                            // Edit UI does not require the operator to retype an existing split.
                            // Preserve its ratio when only the total amount changes.
                            $oldSplitTotal=$effectiveSplitCash+$effectiveSplitTransfer;
                            if($oldSplitTotal<=0)throw new InvalidArgumentException('Snapshot split lama tidak valid; masukkan ulang komposisi split.');
                            $effectiveSplitCash=round((float)$finalAmount*($effectiveSplitCash/$oldSplitTotal),2);
                            $effectiveSplitTransfer=round((float)$finalAmount-$effectiveSplitCash,2);
                        }
                    }
                    if($effectiveSplitActive){
                        if(array_key_exists('bankAccountId',$input) && $effectiveManualBank!==null && $effectiveSplitBank!==''
                            && strcasecmp(trim((string)$effectiveManualBank),trim((string)$effectiveSplitBank))!==0){
                            throw new InvalidArgumentException('Pembayaran Split memiliki dua akun transfer berbeda. Gunakan hanya akun Transfer/QRIS pada bagian Split.');
                        }
                        if($effectiveSplitCash<=0 || $effectiveSplitTransfer<=0 || $effectiveSplitBank==='' || !moneyMatches($effectiveSplitCash+$effectiveSplitTransfer,(float)$finalAmount,0.01)){
                            throw new InvalidArgumentException('Komposisi split edit tidak valid: tunai + transfer harus sama dengan nominal dan kedua bagian harus lebih dari 0.');
                        }
                        tamasyaInferPaymentMethodFromAccount($pdo,$effectiveSplitBank,true,['transfer','qris']);
                        $effectiveSplitBank=tamasyaValidateFinanceBankAccount($pdo,$effectiveSplitBank,true,true);
                        $effectiveManualBank=null;
                        $input['bankAccountId']=null;
                    }
                    $input['isSplitPayment']=$effectiveSplitActive?1:0;
                    $input['splitCashAmount']=$effectiveSplitActive?$effectiveSplitCash:0;
                    $input['splitTransferAmount']=$effectiveSplitActive?$effectiveSplitTransfer:0;
                    $input['splitTransferBankAccountId']=$effectiveSplitActive?$effectiveSplitBank:null;

                    tamasyaValidateManualOtaReceivableContext([
                        'type'=>$finalType,'bankAccountId'=>$effectiveManualBank,
                        'bookingSource'=>$finalBookingSource!==''?$finalBookingSource:($oldTx['bookingSource']??'Direct')
                    ],$isHistoricalTx?'historical_import':'live_operation');
                }catch(Throwable $settlementEditError){
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($settlementEditError,422));
                    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Validasi metode pembayaran/rekening edit gagal',$settlementEditError)]);break;
                }
                // Legacy contract retained conceptually: if($isHistoricalImport) tamasyaNormalizeHistoricalTaxSnapshot.
                // Perubahan nominal data historis wajib disertai snapshot, rule-by-date, atau status unresolved eksplisit.
                $taxContext=array_merge($oldTx,$input,[
                    'type'=>$finalType,'category'=>$finalCategory,'description'=>$finalDescription,'date'=>$finalDate,
                    'bookingSource'=>$finalBookingSource!==''?$finalBookingSource:($oldTx['bookingSource']??'Direct'),
                    'transactionKind'=>array_key_exists('transactionKind',$input)?$input['transactionKind']:($oldTx['transactionKind']??'manual')
                ]);
                if($isHistoricalTx && !array_key_exists('historicalTaxMode',$taxContext)){
                    $oldSource=strtolower(trim((string)($oldTx['taxSource']??'')));
                    if(in_array($oldSource,['historical_rule','rule_by_date'],true))$taxContext['historicalTaxMode']='rule_by_date';
                    elseif(($oldTx['taxSnapshotStatus']??'')==='unresolved')$taxContext['historicalTaxMode']='unresolved';
                    else $taxContext['historicalTaxMode']='document';
                }
                $resolvedTaxSnapshot=tamasyaResolveTransactionTaxSnapshot($pdo,$taxContext,(float)$finalAmount,$isHistoricalTx);
                foreach(['baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId'] as $taxField){
                    $input[$taxField]=$resolvedTaxSnapshot[$taxField]??null;
                }
                $input['taxRuleName']=$resolvedTaxSnapshot['taxRuleName']??($taxContext['taxRuleName']??null);
                if($finalType==='expense')$input['taxNote']=null; elseif(array_key_exists('taxNote',$taxContext))$input['taxNote']=trim((string)$taxContext['taxNote'])?:null;
                $finalTransactionKind=normalizeTransactionKind((string)($input['transactionKind']??($oldTx['transactionKind']??'manual')));
                $financialImpactChanged=(
                    strtolower((string)($oldTx['type']??''))!==$finalType ||
                    !moneyMatches((float)($oldTx['amount']??0),(float)$finalAmount,0.01) ||
                    (string)($oldTx['date']??'')!==$finalDate ||
                    trim((string)($oldTx['categoryId']??''))!==trim((string)($finalCategoryId??'')) ||
                    tamasyaNormalizeCatalogKey($oldTx['categorySystemKey']??'')!==tamasyaNormalizeCatalogKey($finalCategorySystemKey??'') ||
                    trim((string)($oldTx['subcategoryId']??''))!==trim((string)($finalSubcategoryId??'')) ||
                    tamasyaNormalizeCatalogKey($oldTx['subcategorySystemKey']??'')!==tamasyaNormalizeCatalogKey($finalSubcategorySystemKey??'') ||
                    strtolower(trim((string)($oldTx['bankAccountId']??'')))!==strtolower(trim((string)($effectiveManualBank??''))) ||
                    strtolower(trim((string)($oldTx['bookingSource']??'')))!==strtolower(trim((string)$finalBookingSource)) ||
                    normalizeTransactionKind((string)($oldTx['transactionKind']??'manual'))!==$finalTransactionKind ||
                    (int)!empty($oldTx['isSplitPayment'])!==($effectiveSplitActive?1:0) ||
                    !moneyMatches((float)($oldTx['splitCashAmount']??0),(float)$effectiveSplitCash,0.01) ||
                    !moneyMatches((float)($oldTx['splitTransferAmount']??0),(float)$effectiveSplitTransfer,0.01) ||
                    strtolower(trim((string)($oldTx['splitTransferBankAccountId']??'')))!==strtolower(trim((string)($effectiveSplitBank??''))) ||
                    !moneyMatches((float)($oldTx['baseAmount']??0),(float)($resolvedTaxSnapshot['baseAmount']??0),0.01) ||
                    !moneyMatches((float)($oldTx['taxAmount']??0),(float)($resolvedTaxSnapshot['taxAmount']??0),0.01) ||
                    (($oldTx['taxRate']??null)===null)!== (($resolvedTaxSnapshot['taxRate']??null)===null) ||
                    (($oldTx['taxRate']??null)!==null && !moneyMatches((float)$oldTx['taxRate'],(float)($resolvedTaxSnapshot['taxRate']??0),0.001))
                );
                if($isHistoricalTx && !$financialImpactChanged){
                    $periodImpact['periodKey']=$oldTx['reportingPeriod']??substr($finalDate,0,7);
                    $periodImpact['periodStatusAtEntry']=$oldTx['periodStatusAtEntry']??'legacy_synced';
                    $periodImpact['periodImpactStatus']=$oldTx['periodImpactStatus']??'baseline_preserved';
                    $periodImpact['requiresTaxAmendment']=!empty($oldTx['requiresTaxAmendment']);
                    $periodImpact['historicalReviewStatus']=$oldTx['historicalReviewStatus']??'verified';
                    $periodImpact['warning']=null;
                }
                if($isHistoricalTx){
                    $input['serviceDate']=$historicalMetadata['serviceDate'];
                    $input['historicalSourceType']=$historicalMetadata['historicalSourceType'];
                    $input['historicalSourceReference']=$historicalMetadata['historicalSourceReference'];
                    $input['sourceReportedBy']=$historicalMetadata['sourceReportedBy'];
                    $input['reportingPeriod']=$periodImpact['periodKey'];
                    $input['periodStatusAtEntry']=$periodImpact['periodStatusAtEntry'];
                    $input['periodImpactStatus']=$periodImpact['periodImpactStatus'];
                    $input['requiresTaxAmendment']=!empty($periodImpact['requiresTaxAmendment'])?1:0;
                    $input['historicalReviewStatus']=$periodImpact['historicalReviewStatus'];
                    $input['periodCorrectionReason']=$historicalMetadata['periodCorrectionReason'];
                    $input['inputDelayDays']=tamasyaBackfillInputDelayDays($finalDate,(string)($oldTx['createdAt']??''));
                }

                $mutationChanges=[];
                $map=[
                    'type'=>fn($v)=>strtolower((string)$v), 'category'=>fn($v)=>trim((string)$v),
                    'categoryId'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'categorySystemKey'=>fn($v)=>$v===''||$v===null?null:tamasyaNormalizeCatalogKey($v),
                    'subcategoryId'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'subcategorySystemKey'=>fn($v)=>$v===''||$v===null?null:tamasyaNormalizeCatalogKey($v),
                    'amount'=>fn($v)=>round((float)$v,2), 'date'=>fn($v)=>(string)$v,
                    'description'=>fn($v)=>trim((string)$v), 'bookingSource'=>fn($v)=>$v ?: null,
                    'baseAmount'=>fn($v)=>$v===''||$v===null?null:(float)$v,
                    'taxAmount'=>fn($v)=>$v===''||$v===null?null:(float)$v,
                    'taxRate'=>fn($v)=>$v===''||$v===null?null:(float)$v,
                    'taxSnapshotStatus'=>fn($v)=>trim((string)$v)?:'unresolved',
                    'taxSource'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'taxRuleId'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'taxRuleName'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'taxNote'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'transactionKind'=>fn($v)=>normalizeTransactionKind((string)$v),
                    'serviceDate'=>fn($v)=>$v===''||$v===null?null:(string)$v,
                    'historicalSourceType'=>fn($v)=>$v===''||$v===null?null:tamasyaNormalizeHistoricalSourceType($v),
                    'historicalSourceReference'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'sourceReportedBy'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'reportingPeriod'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'periodStatusAtEntry'=>fn($v)=>trim((string)$v)?:'open',
                    'periodImpactStatus'=>fn($v)=>trim((string)$v)?:'normal',
                    'requiresTaxAmendment'=>fn($v)=>!empty($v)?1:0,
                    'historicalReviewStatus'=>fn($v)=>trim((string)$v)?:'not_required',
                    'periodCorrectionReason'=>fn($v)=>$v===''||$v===null?null:trim((string)$v),
                    'inputDelayDays'=>fn($v)=>max(0,(int)$v),
                    'isSplitPayment'=>fn($v)=>!empty($v)?1:0,
                    'splitCashAmount'=>fn($v)=>round((float)$v,2),
                    'splitTransferAmount'=>fn($v)=>round((float)$v,2),
                    'splitTransferBankAccountId'=>fn($v)=>$v===''||$v===null?null:trim((string)$v)
                ];
                foreach ($map as $key=>$normalizer) {
                    if (array_key_exists($key,$input)) $mutationChanges[$key]=$normalizer($input[$key]);
                }
                foreach (['subcategory','roomNumber','bankAccountId','bookingId'] as $key) {
                    if (array_key_exists($key,$input)) $mutationChanges[$key]=$input[$key] === '' ? null : $input[$key];
                }
                if (array_key_exists('proofUrl',$input) && !isPlaceholderProof($input['proofUrl'])) {
                    $mutationChanges['proofUrl']=$input['proofUrl'] ?: null;
                }
                if (array_key_exists('bookingId',$input) && $input['bookingId']) {
                    $stmtCheck=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE id = ?"); $stmtCheck->execute([$input['bookingId']]);
                    if ((int)$stmtCheck->fetchColumn()===0) { $pdo->rollBack(); http_response_code(400); echo json_encode(["success"=>false,"error"=>"Booking terkait tidak ditemukan."]); break; }
                }
                if (!$mutationChanges) { $pdo->rollBack(); http_response_code(400); echo json_encode(["success"=>false,"error"=>"Tidak ada perubahan."]); break; }
                // An edit can raise a cash expense just as a create can, but this branch went
                // through tamasyaMutateFinancialTransaction() without ever consulting the
                // open-shift cash-coverage guard. On an open shift the amount could be
                // raised to anything, leaving expected_cash negative at close time.
                // A locked/closed shift is already refused above, so only the live case
                // is evaluated here.
                $editCoverageTx=array_merge($oldTx,$mutationChanges);
                if(!$isHistoricalTx){
                    $editOldType=strtolower(trim((string)($oldTx['type']??'')));
                    $editNewType=strtolower(trim((string)($editCoverageTx['type']??'')));
                    if(trim((string)($editCoverageTx['shiftSessionId']??''))===''){
                        $editTargetCashLeg=tamasyaCanonicalCashLegAmount($editCoverageTx);
                        $editRequiresShift=$editTargetCashLeg>0.009;
                        $editCoverageTx['shiftSessionId']=resolveOpenShiftSessionId($pdo,$loggedInStaff,$editRequiresShift);
                    }
                    if($editNewType==='expense') tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo,$editCoverageTx,$id);
                    if($editOldType==='income' || $editNewType==='income') tamasyaAssertOpenShiftCashProjection($pdo,$editCoverageTx,$id);
                }
                tamasyaMutateFinancialTransaction(
                    $pdo,$id,$mutationChanges,$loggedInStaff,'finance_correction','manual_edit',
                    ['source'=>'web','lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true,'reason'=>$changeReason]
                );
                $allocationBookingIds=tamasyaRefreshTransactionAllocationTaxSnapshots($pdo,$id,$resolvedTaxSnapshot,$loggedInStaff,'web-edit-allocation-tax');
                $touchedBookingIds=array_values(array_unique(array_filter(array_merge([(string)($oldTx['bookingId']??''),$finalBookingId],$allocationBookingIds))));
                foreach($touchedBookingIds as $touchedBookingId){
                    recalculateBookingFinancials($pdo,$touchedBookingId,true);
                    assertBookingLedgerInvariant($pdo,$touchedBookingId,$loggedInStaff,'web',false);
                }
                $transactionAfterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$transactionAfterStmt->execute([$id]);$transactionAfter=$transactionAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $editAdjustmentResult=['warning'=>null,'requiresReview'=>false,'crossPeriod'=>false];
                if($isHistoricalTx && $financialImpactChanged && $transactionAfter){
                    $editAdjustmentResult=tamasyaRegisterHistoricalBackfillEditAdjustments($pdo,$oldTx,$transactionAfter,$loggedInStaff,$changeReason,'edit_'.$id.'_v'.(int)($transactionAfter['version']??1));
                    if(!empty($editAdjustmentResult['crossPeriod'])){
                        tamasyaMutateFinancialTransaction($pdo,$id,[
                            'periodImpactStatus'=>'cross_period_adjustment',
                            'historicalReviewStatus'=>$editAdjustmentResult['reviewStatus'],
                            'requiresTaxAmendment'=>!empty($editAdjustmentResult['requiresTaxAmendment'])?1:0,
                        ],$loggedInStaff,'historical_review','historical_period_adjustment',['lockRows'=>true,'requireAll'=>true]);
                        $transactionAfterStmt->execute([$id]);$transactionAfter=$transactionAfterStmt->fetch(PDO::FETCH_ASSOC)?:$transactionAfter;
                    }
                }
                syncJournalProjections($pdo,true);
                $beforeAuditSnapshot=tamasyaTransactionAuditSnapshot($oldTx)??[];
                $afterAuditSnapshot=tamasyaTransactionAuditSnapshot($transactionAfter)??[];
                $afterAuditSnapshot['auditContext']=[
                    'changeReason'=>$changeReason,
                    'changedFields'=>tamasyaAuditChangedFieldNames($beforeAuditSnapshot,$afterAuditSnapshot),
                    'approvalId'=>$approvalId,
                ];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui transaksi manual','transaction',(string)$id,$beforeAuditSnapshot,$afterAuditSnapshot,'web');
                markApprovalUsed($pdo,$approvalId,$loggedInStaff);
                $notifId=generateServerId('n');
                $stmt=$pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'finance')");
                $stmt->execute([$notifId,"Transaksi {$finalDescription} diperbarui oleh ".currentStaffLabel($loggedInStaff).'.',date('Y-m-d H:i:s')]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                broadcastTelegramNotification($pdo,"✏️ *LOG KAS DIPERBARUI*\n\n📝 {$finalDescription}\n💰 Rp ".number_format($finalAmount,0,',','.')."\n👤 ".currentStaffLabel($loggedInStaff),true);
                echo json_encode(["success"=>true,"message"=>"Transaksi diperbarui.","periodImpact"=>$periodImpact,"warning"=>tamasyaMergeFinanceWarnings($financialImpactChanged?(($editAdjustmentResult['warning']??null)?:($periodImpact['warning']??null)):null,$editManualPolicy['warnings']??[]),"db"=>getRoleScopedHotelData($pdo, $loggedInStaff)]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500); echo json_encode(["success"=>false,"error"=>clientExceptionMessage("Gagal mengubah transaksi", $e)]);
            }
        } elseif ($method === 'DELETE') {
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if ($id === '') { http_response_code(400); echo json_encode(["success"=>false,"error"=>"ID transaksi wajib diisi."]); break; }
            try {
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $tx=$stmt->fetch();
                if (!$tx) { $pdo->rollBack(); http_response_code(404); echo json_encode(["success"=>false,"error"=>"Transaksi tidak ditemukan."]); break; }
                if (!empty($tx['lockedAt'])) { $pdo->rollBack(); http_response_code(423); echo json_encode(["success"=>false,"error"=>"Transaksi sudah dikunci oleh tutup shift. Gunakan transaksi penyesuaian."]); break; }
                if (transactionIsProtected($tx)) { $pdo->rollBack(); http_response_code(409); echo json_encode(["success"=>false,"error"=>"Transaksi otomatis harus dibatalkan dari modul sumbernya."]); break; }

                $deleteReason=trim((string)($input['deleteReason']??$input['reason']??''));
                $deleteConfirmation=trim((string)($input['confirmation']??''));
                if(tamasyaStringLength($deleteReason)<8 || tamasyaStringLength($deleteReason)>255){
                    $pdo->rollBack();http_response_code(422);echo json_encode(['success'=>false,'error'=>'Alasan penghapusan transaksi manual wajib 8–255 karakter.']);break;
                }
                if($deleteConfirmation!=='HAPUS DATA MANUAL'){
                    $pdo->rollBack();http_response_code(422);echo json_encode(['success'=>false,'error'=>'Konfirmasi penghapusan tidak cocok.']);break;
                }

                $isHistorical=tamasyaTransactionIsHistorical($tx);
                $rowsToDelete=[$tx];
                $isHistoricalChild=(string)($tx['sourceEntity']??'')==='historical_transaction' && trim((string)($tx['sourceEntityId']??''))!=='';
                if($isHistorical && !$isHistoricalChild){
                    $childrenStmt=$pdo->prepare("SELECT * FROM transactions WHERE sourceEntity='historical_transaction' AND sourceEntityId=? ORDER BY `date`,id FOR UPDATE");
                    $childrenStmt->execute([$id]);
                    foreach($childrenStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $childRow)$rowsToDelete[]=$childRow;
                }
                $deleteIds=[];$totalDeletedAmount=0.0;$periodKeys=[];
                foreach($rowsToDelete as $candidate){
                    $candidateId=trim((string)($candidate['id']??''));
                    if($candidateId==='')continue;
                    if(!empty($candidate['lockedAt'])){$pdo->rollBack();http_response_code(423);echo json_encode(['success'=>false,'error'=>'Salah satu rincian transaksi sudah dikunci oleh tutup shift. Gunakan koreksi berjejak.']);break 2;}
                    if(transactionIsProtected($candidate) || !empty($candidate['isSystemGenerated']) || normalizeTransactionKind((string)($candidate['transactionKind']??'manual'))!=='manual'){
                        $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Penghapusan langsung hanya berlaku untuk transaksi manual. Transaksi otomatis wajib dibatalkan dari modul sumbernya.']);break 2;
                    }
                    if(trim((string)($candidate['bookingId']??''))!=='' || trim((string)($candidate['shiftSessionId']??''))!==''){
                        $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Transaksi sudah terhubung ke booking atau shift. Gunakan koreksi/Pemeliharaan Data agar relasi keuangan tetap konsisten.']);break 2;
                    }
                    $candidateSource=trim((string)($candidate['sourceEntity']??''));
                    if($candidateSource!=='' && $candidateSource!=='historical_transaction'){
                        $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Transaksi mempunyai sumber operasional lain dan tidak boleh dihapus dari Log Kas.']);break 2;
                    }
                    $reconciliationStatus=strtolower(trim((string)($candidate['reconciliationStatus']??'')));
                    if($reconciliationStatus!=='' && !in_array($reconciliationStatus,['unmatched','pending'],true)){
                        $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Transaksi sudah masuk rekonsiliasi. Gunakan koreksi/Pemeliharaan Data.']);break 2;
                    }
                    if(!empty($candidate['requiresTaxAmendment']) || in_array(strtolower((string)($candidate['periodImpactStatus']??'')),['reported_period_adjustment','closed_period_amendment'],true)){
                        $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Transaksi memengaruhi periode yang sudah dilaporkan/ditutup. Gunakan koreksi berjejak, bukan hapus langsung.']);break 2;
                    }
                    $deleteIds[]=$candidateId;$totalDeletedAmount+=(float)($candidate['amount']??0);
                    $periodKey=trim((string)($candidate['reportingPeriod']??''));
                    if($periodKey==='' && validIsoDate((string)($candidate['date']??'')))$periodKey=substr((string)$candidate['date'],0,7);
                    if($periodKey!=='')$periodKeys[$periodKey]=true;
                }
                $deleteIds=array_values(array_unique($deleteIds));
                if(!$deleteIds){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Tidak ada transaksi manual yang dapat dihapus.']);break;}

                if($periodKeys){
                    $periodList=array_keys($periodKeys);$periodPh=implode(',',array_fill(0,count($periodList),'?'));
                    $periodStmt=$pdo->prepare("SELECT period_key,cash_status,tax_status FROM financial_reporting_periods WHERE period_key IN ($periodPh) FOR UPDATE");
                    $periodStmt->execute($periodList);
                    foreach($periodStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $periodRow){
                        $cashStatus=strtolower(trim((string)($periodRow['cash_status']??'open')));
                        $taxStatus=strtolower(trim((string)($periodRow['tax_status']??'open')));
                        if(in_array($cashStatus,['reported','closed'],true)||in_array($taxStatus,['reported','closed'],true)){
                            $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Periode '.($periodRow['period_key']??'').' sudah dilaporkan/ditutup. Gunakan koreksi/Pemeliharaan Data.']);break 2;
                        }
                    }
                }

                $deletePh=implode(',',array_fill(0,count($deleteIds),'?'));
                $dependencyChecks=[
                    ["SELECT COUNT(*) FROM transaction_allocations WHERE transaction_id IN ($deletePh)",'Transaksi mempunyai alokasi reservasi. Batalkan alokasi atau gunakan Pemeliharaan Data.'],
                    ["SELECT COUNT(*) FROM reconciliation_items WHERE transaction_id IN ($deletePh)",'Transaksi sudah tercatat pada rekonsiliasi. Gunakan koreksi/Pemeliharaan Data.'],
                    ["SELECT COUNT(*) FROM guest_security_deposit_ledger WHERE transaction_id IN ($deletePh)",'Transaksi terkait deposito tamu dan harus dikoreksi dari modul deposito.'],
                    ["SELECT COUNT(*) FROM maintenance_tickets WHERE transaction_id IN ($deletePh)",'Transaksi terkait tiket maintenance dan harus dikoreksi dari modul sumber.'],
                ];
                foreach($dependencyChecks as [$dependencySql,$dependencyMessage]){
                    $dependencyStmt=$pdo->prepare($dependencySql);$dependencyStmt->execute($deleteIds);
                    if((int)$dependencyStmt->fetchColumn()>0){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>$dependencyMessage]);break 2;}
                }
                $posDependencyStmt=$pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE transaction_id IN ($deletePh) OR cogs_transaction_id IN ($deletePh)");
                $posDependencyStmt->execute(array_merge($deleteIds,$deleteIds));
                if((int)$posDependencyStmt->fetchColumn()>0){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Transaksi terkait POS/Minibar dan harus dibatalkan dari Arsip POS.']);break;}

                $approvalId=null;
                if(!$isHistorical){
                    $approvalId=requireSensitiveApproval($pdo,$loggedInStaff,'delete_transaction','transaction',$id,$totalDeletedAmount,['transactions'=>$rowsToDelete,'deleteReason'=>$deleteReason]);
                    if($approvalId===false){tamasyaFinancialCommit($pdo);break;}
                }

                $beforeDeleteSnapshot=[];
                foreach($rowsToDelete as $candidate)$beforeDeleteSnapshot[]=tamasyaTransactionAuditSnapshot($candidate);
                $pdo->prepare("DELETE FROM historical_backfill_adjustments WHERE transaction_id IN ($deletePh)")->execute($deleteIds);
                $journalIdsStmt=$pdo->prepare("SELECT id FROM journal_entries WHERE transaction_id IN ($deletePh)");$journalIdsStmt->execute($deleteIds);
                $journalIds=array_values(array_filter(array_map('strval',$journalIdsStmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
                if($journalIds){
                    $journalPh=implode(',',array_fill(0,count($journalIds),'?'));
                    $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id IN ($journalPh)")->execute($journalIds);
                }
                $pdo->prepare("DELETE FROM journal_entries WHERE transaction_id IN ($deletePh)")->execute($deleteIds);
                tamasyaDeleteFinancialTransactions($pdo,$deleteIds,$loggedInStaff,'finance_correction','manual_delete',['lockRows'=>true,'requireAll'=>true]);
                $purgeBatchId='direct_manual_transaction_delete_'.substr(hash('sha256',$id.'|'.implode('|',$deleteIds).'|'.$deleteReason),0,40);
                $tombstones=[];foreach($deleteIds as $deleteId)$tombstones[]=['type'=>'transactions','id'=>$deleteId];
                insertSyncTombstones($pdo,$tombstones,$purgeBatchId,(string)($loggedInStaff['id']??''));
                syncJournalProjections($pdo,true);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus transaksi manual','transaction',(string)$id,
                    ['transactions'=>$beforeDeleteSnapshot],
                    ['deleted'=>true,'deletedIds'=>$deleteIds,'deletedCount'=>count($deleteIds),'reason'=>$deleteReason,'purgeBatchId'=>$purgeBatchId],
                    'web');
                markApprovalUsed($pdo,$approvalId,$loggedInStaff);
                $notifId=generateServerId('n');
                $stmt=$pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'finance')");
                $stmt->execute([$notifId,count($deleteIds)." transaksi manual dihapus oleh ".currentStaffLabel($loggedInStaff).". Alasan: {$deleteReason}",date('Y-m-d H:i:s')]);
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);
                broadcastTelegramNotification($pdo,"🗑️ *DATA MANUAL DIHAPUS*\n\n📝 {$tx['description']}\n📦 Jumlah baris: ".count($deleteIds)."\n💰 Total nominal: Rp ".number_format($totalDeletedAmount,0,',','.')."\n📌 Alasan: {$deleteReason}\n👤 ".currentStaffLabel($loggedInStaff),true);
                echo json_encode(["success"=>true,"message"=>count($deleteIds)." transaksi manual berhasil dihapus.","deletedIds"=>$deleteIds,"deletedCount"=>count($deleteIds),"db"=>getRoleScopedHotelData($pdo, $loggedInStaff)]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500); echo json_encode(["success"=>false,"error"=>clientExceptionMessage("Gagal menghapus transaksi", $e)]);
            }
        } else {
            http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]);
        }
        break;

    case 'reporting-periods':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            try {
                $periods=$pdo->query("SELECT p.*,
                    (SELECT COUNT(*) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status IN ('pending_review','pending_approval','evidence_required')) AS pending_adjustments,
                    (SELECT COALESCE(SUM(a.cash_delta),0) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status<>'rejected') AS adjustment_cash_delta,
                    (SELECT COALESCE(SUM(a.tax_delta),0) FROM historical_backfill_adjustments a WHERE a.period_key=p.period_key AND a.review_status<>'rejected') AS adjustment_tax_delta
                    FROM financial_reporting_periods p ORDER BY p.period_key DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                echo json_encode(['success'=>true,'periods'=>$periods]);
            } catch(Throwable $e) {
                tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal membaca periode laporan',$e)]);
            }
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireRoles($loggedInStaff, ['admin','manager']);
            $periodKey=trim((string)($input['periodKey']??''));
            $cashStatus=strtolower(trim((string)($input['cashStatus']??'open')));
            $taxStatus=strtolower(trim((string)($input['taxStatus']??'open')));
            $reportReference=trim((string)($input['reportReference']??''));
            $notes=trim((string)($input['notes']??''));
            if(!preg_match('/^\d{4}-\d{2}$/',$periodKey)||!validIsoDate($periodKey.'-01')||!in_array($cashStatus,['open','reported','closed'],true)||!in_array($taxStatus,['open','reported','closed'],true)){
                http_response_code(422);echo json_encode(['success'=>false,'error'=>'Periode atau status laporan tidak valid.']);break;
            }
            try{
                $pdo->beginTransaction();
                $beforeStmt=$pdo->prepare("SELECT * FROM financial_reporting_periods WHERE period_key=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$periodKey]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $statusRank=['open'=>0,'reported'=>1,'closed'=>2];
                $isReopen=$before && (
                    ($statusRank[$cashStatus]??0)<($statusRank[strtolower((string)($before['cash_status']??'open'))]??0)
                    || ($statusRank[$taxStatus]??0)<($statusRank[strtolower((string)($before['tax_status']??'open'))]??0)
                );
                if($isReopen && tamasyaStringLength($notes)<10){
                    throw new RuntimeException('Membuka kembali periode laporan wajib menyertakan alasan minimal 10 karakter agar jejak audit koreksi tetap jelas.',422);
                }
                if($cashStatus==='closed' || $taxStatus==='closed'){
                    $pendingStmt=$pdo->prepare("SELECT
                        COALESCE(SUM(CASE WHEN ABS(COALESCE(cash_delta,0))>0.000001 THEN 1 ELSE 0 END),0) AS pending_cash,
                        COALESCE(SUM(CASE WHEN ABS(COALESCE(tax_delta,0))>0.000001 OR ABS(COALESCE(tax_base_delta,0))>0.000001 OR COALESCE(requires_tax_amendment,0)=1 THEN 1 ELSE 0 END),0) AS pending_tax
                        FROM historical_backfill_adjustments
                        WHERE period_key=? AND review_status IN ('pending_review','pending_approval','evidence_required')");
                    $pendingStmt->execute([$periodKey]);
                    $pending=$pendingStmt->fetch(PDO::FETCH_ASSOC)?:['pending_cash'=>0,'pending_tax'=>0];
                    if($cashStatus==='closed' && (int)($pending['pending_cash']??0)>0){
                        throw new DomainException('Periode kas belum dapat ditutup karena masih ada koreksi data historis yang memengaruhi kas dan belum selesai direview.');
                    }
                    if($taxStatus==='closed' && (int)($pending['pending_tax']??0)>0){
                        throw new DomainException('Periode pajak belum dapat ditutup karena masih ada koreksi data historis yang memengaruhi pajak dan belum selesai direview.');
                    }
                }
                $pdo->prepare("INSERT INTO financial_reporting_periods(period_key,cash_status,tax_status,report_reference,notes,status_source,closed_at,closed_by)
                    VALUES (?,?,?,?,?,'application',CASE WHEN ?='closed' OR ?='closed' THEN CURRENT_TIMESTAMP ELSE NULL END,CASE WHEN ?='closed' OR ?='closed' THEN ? ELSE NULL END)
                    ON DUPLICATE KEY UPDATE cash_status=VALUES(cash_status),tax_status=VALUES(tax_status),report_reference=VALUES(report_reference),notes=VALUES(notes),status_source='application',closed_at=VALUES(closed_at),closed_by=VALUES(closed_by)")
                    ->execute([$periodKey,$cashStatus,$taxStatus,$reportReference?:null,$notes?:null,$cashStatus,$taxStatus,$cashStatus,$taxStatus,$loggedInStaff['id']??null]);
                $afterStmt=$pdo->prepare("SELECT * FROM financial_reporting_periods WHERE period_key=? LIMIT 1");$afterStmt->execute([$periodKey]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$isReopen?'Membuka kembali periode laporan':'Memperbarui status periode laporan','financial_reporting_period',$periodKey,$before,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'message'=>'Status periode laporan diperbarui.','period'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memperbarui periode laporan',$e)]);}
            break;
        }
        http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
        break;

    case 'historical-backfill-review':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $transactionId=trim((string)($input['transactionId']??''));
        $decision=strtolower(trim((string)($input['decision']??'')));
        $reviewNote=trim((string)($input['reviewNote']??''));
        if($transactionId===''||!in_array($decision,['verify','approve','reject'],true)||tamasyaStringLength($reviewNote)<5){http_response_code(422);echo json_encode(['success'=>false,'error'=>'Transaksi, keputusan, dan catatan review minimal 5 karakter wajib diisi.']);break;}
        if(in_array($decision,['approve','reject'],true)&&!in_array(strtolower((string)($loggedInStaff['role']??'')),['admin','manager'],true)){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Persetujuan atau penolakan hanya dapat dilakukan Admin/Manager.']);break;}
        $newStatus=$decision==='verify'?'verified':($decision==='approve'?'approved':'rejected');
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$transactionId]);$before=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$before||!tamasyaTransactionIsHistorical($before)){http_response_code(404);throw new RuntimeException('Transaksi historis tidak ditemukan.',404);}
            $currentReviewStatus=strtolower(trim((string)($before['historicalReviewStatus']??'')));
            if($currentReviewStatus==='rejected' && $newStatus!=='rejected'){
                throw new RuntimeException('Transaksi historis yang sudah ditolak bersifat terminal. Buat koreksi/backfill baru agar adjustment periode dan jejak audit tidak berbeda status.',409);
            }
            tamasyaMutateFinancialTransaction($pdo,$transactionId,[
                'historicalReviewStatus'=>$newStatus,
                'historicalReviewedBy'=>$loggedInStaff['id']??null,
                'historicalReviewedAt'=>null,
                'periodCorrectionReason'=>$reviewNote,
            ],$loggedInStaff,'historical_review','historical_review',[
                'source'=>'web','lockRows'=>true,'requireAll'=>true,
                'currentTimestampFields'=>['historicalReviewedAt'],'coalesceNonEmpty'=>['periodCorrectionReason']
            ]);
            $pdo->prepare("UPDATE historical_backfill_adjustments SET review_status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,review_note=? WHERE transaction_id=? AND review_status<>'rejected'")
                ->execute([$newStatus,$loggedInStaff['id']??null,$reviewNote,$transactionId]);
            $afterStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$afterStmt->execute([$transactionId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Review backfill transaksi historis','transaction',$transactionId,tamasyaTransactionAuditSnapshot($before),array_merge(tamasyaTransactionAuditSnapshot($after)??[],['reviewNote'=>$reviewNote]),'web');
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Status review transaksi historis diperbarui.','status'=>$newStatus,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if((int)$e->getCode()===404)http_response_code(404);elseif((int)$e->getCode()===409)http_response_code(409);else tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal mereview transaksi historis',$e)]);
        }
        break;

    // ----------------------------------------------------------------
    // GET /api/transaction-proof ATAU api.php?action=transaction-proof
    // Ambil file bukti transaksi secara on-demand untuk optimasi performa
    // ----------------------------------------------------------------
    case 'transaction-proof':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }
        $id = $_GET['id'] ?? $input['id'] ?? '';
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "ID Transaksi harus disertakan!"]);
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT proofUrl FROM transactions WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Transaksi tidak ditemukan."]);
                break;
            }
            $proofUrl = $stmt->fetchColumn() ?: null;
            $resolvedProofUrl = $proofUrl;
            $proofError = null;
            if (is_string($proofUrl) && str_starts_with($proofUrl, 'telegram_file_id:')) {
                $fileId = trim(substr($proofUrl, strlen('telegram_file_id:')));
                $resolvedProofUrl = tamasyaTelegramFileDataUrl($pdo, $fileId);
                if ($resolvedProofUrl === null) $proofError = 'Foto Telegram belum dapat diambil ulang.';
            }
            echo json_encode(["success" => true, "proofUrl" => $resolvedProofUrl, "proofError" => $proofError]);
        } catch (Exception $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal mengambil foto bukti", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/sync ATAU api.php?action=sync
    // Sinkronisasi data offline dari local storage ke database MySQL cloud
    // ----------------------------------------------------------------
    case 'sync':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $clientRooms = is_array($input['rooms'] ?? null) ? $input['rooms'] : [];
        $clientBookings = is_array($input['bookings'] ?? null) ? $input['bookings'] : [];
        $clientTransactions = is_array($input['transactions'] ?? null) ? $input['transactions'] : [];
        $clientBookingActions = is_array($input['bookingActions'] ?? null) ? $input['bookingActions'] : [];
        // OFFLINE OPERATIONAL MODE: queued lifecycle operations (check-in / fully-paid
        // checkout) captured on devices while the server was unreachable. Each entry
        // carries its own operationId and is replayed through the canonical workflow
        // below; the server stays the single source of truth.
        $clientLifecycleOps = is_array($input['lifecycleOps'] ?? null) ? $input['lifecycleOps'] : [];
        $clientInventory = is_array($input['inventory'] ?? null) ? $input['inventory'] : [];
        $clientInventoryMaintenance = is_array($input['inventoryMaintenance'] ?? null) ? $input['inventoryMaintenance'] : [];
        $deletedTransactionIds = is_array($input['deletedTransactionIds'] ?? null) ? $input['deletedTransactionIds'] : [];
        $deletedRoomIds = is_array($input['deletedRoomIds'] ?? null) ? $input['deletedRoomIds'] : [];
        $deletedBookingIds = is_array($input['deletedBookingIds'] ?? null) ? $input['deletedBookingIds'] : [];
        $deletedInventoryIds = is_array($input['deletedInventoryIds'] ?? null) ? $input['deletedInventoryIds'] : [];
        $deletedMaintenanceIds = is_array($input['deletedMaintenanceIds'] ?? null) ? $input['deletedMaintenanceIds'] : [];
        $lastPulledAt = (string)($input['lastPulledAt'] ?? '1970-01-01T00:00:00Z');
        $tombstonesSince=[];
        if(tamasyaTableExists($pdo,'sync_tombstones')){
            $sinceTs=strtotime($lastPulledAt);$sinceSql=$sinceTs===false?'1970-01-01 00:00:00':date('Y-m-d H:i:s',$sinceTs);
            $stmtTomb=$pdo->prepare("SELECT entity_type AS entityType,entity_id AS entityId,purge_batch_id AS purgeBatchId,deleted_at AS deletedAt FROM sync_tombstones WHERE deleted_at>=? AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY deleted_at,id LIMIT 1000");
            $stmtTomb->execute([$sinceSql]);$tombstonesSince=$stmtTomb->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        $resolvedConflicts = is_array($input['resolvedConflicts'] ?? null) ? $input['resolvedConflicts'] : [];
        $resolutionMap = [];
        foreach ($resolvedConflicts as $resolution) {
            if (!empty($resolution['id']) && in_array($resolution['choice'] ?? '', ['local','server'], true)) {
                $resolutionMap[(string)$resolution['id']] = $resolution['choice'];
            }
        }

        $syncedRoomIds=[]; $syncedBookingIds=[]; $syncedTransactionIds=[];
        $syncedBookingActionOperationIds=[]; $syncedLifecycleOperationIds=[];
        $syncedInventoryIds=[]; $syncedMaintenanceIds=[]; $syncedDeletionIds=[];
        $syncedRoomDeletionIds=[]; $syncedBookingDeletionIds=[];
        $syncedInventoryDeletionIds=[]; $syncedMaintenanceDeletionIds=[];
        $conflicts=[]; $syncCount=0;

        $parseTs = function($value) {
            if (!$value) return 0;
            $ts = strtotime((string)$value);
            return $ts === false ? 0 : $ts;
        };

        // Jurnal idempotensi sinkronisasi. Operation ID eksplisit dari client dipakai
        // bila tersedia; selain itu dibuat fingerprint deterministik dari payload.
        $makeSyncOperationId = function($entityType, $entityId, $action, $payload = null) use ($loggedInStaff) {
            $explicit = is_array($payload)
                ? trim((string)($payload['_operationId'] ?? $payload['operationId'] ?? ''))
                : '';
            $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? 'unknown-device'));
            $sessionId = trim((string)($loggedInStaff['session_id'] ?? ''));
            $hotelScopeId = tamasyaHotelScopeId();
            $offlineSessionScopeId = trim((string)($_SERVER['HTTP_X_TAMASYA_OFFLINE_SESSION_SCOPE'] ?? ''));
            if ($explicit !== '') {
                return 'client_' . substr(hash('sha256', implode('|', [
                    (string)($loggedInStaff['id'] ?? ''), $deviceId, $sessionId, $hotelScopeId,
                    $offlineSessionScopeId, (string)$entityType, (string)$entityId, (string)$action, $explicit
                ])), 0, 64);
            }
            $stablePayload = is_array($payload) ? $payload : ['value' => $payload];
            unset($stablePayload['_baseUpdatedAt']);
            return 'sync_' . substr(hash('sha256', json_encode([
                'staff' => (string)($loggedInStaff['id'] ?? ''),
                'device' => $deviceId,
                'session' => $sessionId,
                'hotelScopeId' => $hotelScopeId,
                'offlineSessionScopeId' => $offlineSessionScopeId,
                'entityType' => (string)$entityType,
                'entityId' => (string)$entityId,
                'action' => (string)$action,
                'payload' => $stablePayload
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 64);
        };
        $claimSyncOperation = function($operationId, $entityType, $entityId, $action, $payload = null) use ($pdo, $loggedInStaff) {
            $deviceId = currentDeviceId();
            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt = $pdo->prepare("INSERT IGNORE INTO sync_operations
                (operation_id,staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at,processed_at)
                VALUES (?,?,?,?,?,?,?,'processing',NULL,CURRENT_TIMESTAMP,NULL)");
            $stmt->execute([
                $operationId,
                (string)($loggedInStaff['id'] ?? ''),
                $deviceId,
                (string)$entityType,
                (string)$entityId,
                (string)$action,
                $payloadHash
            ]);
            if ($stmt->rowCount() === 1) return ['claimed'=>true,'payloadHash'=>$payloadHash];

            $existingStmt = $pdo->prepare("SELECT payload_hash,status,result_json FROM sync_operations WHERE operation_id = ? LIMIT 1 FOR UPDATE");
            $existingStmt->execute([$operationId]);
            $existing = $existingStmt->fetch();
            if (!$existing) throw new RuntimeException('Jurnal sinkronisasi tidak dapat diklaim.');
            $existingHash = (string)($existing['payload_hash'] ?? '');
            if ($existingHash !== '' && !hash_equals($existingHash, $payloadHash)) {
                return ['claimed'=>false,'mismatch'=>true,'payloadHash'=>$payloadHash,'existing'=>$existing];
            }
            if ((string)($existing['status'] ?? '') === 'processed') {
                return ['claimed'=>false,'duplicate'=>true,'payloadHash'=>$payloadHash,'existing'=>$existing];
            }
            // Jangan menganggap row "processing" sebagai sukses. Row tersebut dapat berasal
            // dari proses lain yang belum selesai atau sisa proses lama yang terputus.
            return ['claimed'=>false,'inProgress'=>true,'payloadHash'=>$payloadHash,'existing'=>$existing];
        };
        $releaseSyncOperation = function($operationId) use ($pdo) {
            $stmt = $pdo->prepare("DELETE FROM sync_operations WHERE operation_id = ? AND status = 'processing'");
            $stmt->execute([$operationId]);
        };
        $recordSyncOperation = function($operationId, $entityType, $entityId, $action, $payload = null, $result = null) use ($pdo, $loggedInStaff) {
            $resultJson = $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $pdo->prepare("UPDATE sync_operations SET status='processed', result_json=?, processed_at=CURRENT_TIMESTAMP WHERE operation_id=?");
            $stmt->execute([$resultJson,$operationId]);
            if ($stmt->rowCount() === 0) {
                $deviceId = currentDeviceId();
                $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $fallback = $pdo->prepare("INSERT IGNORE INTO sync_operations
                    (operation_id,staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at,processed_at)
                    VALUES (?,?,?,?,?,?,?,'processed',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                $fallback->execute([$operationId,(string)($loggedInStaff['id'] ?? ''),$deviceId,(string)$entityType,(string)$entityId,(string)$action,$payloadHash,$resultJson]);
            }
        };

        $syncEntityAuditSnapshot = static function(string $table, ?array $row): ?array {
            if (!$row) return null;
            if ($table === 'bookings') return tamasyaBookingAuditSnapshot($row);
            if ($table === 'transactions') return tamasyaTransactionAuditSnapshot($row);
            if ($table === 'rooms') {
                return [
                    'id'=>(string)($row['id']??''),
                    'roomNumber'=>(string)($row['number']??''),
                    'type'=>(string)($row['type']??''),
                    'price'=>(float)($row['price']??0),
                    'status'=>(string)($row['status']??''),
                    'floor'=>(int)($row['floor']??0),
                    'version'=>(int)($row['version']??0),
                ];
            }
            if ($table === 'inventory') {
                return [
                    'id'=>(string)($row['id']??''),
                    'code'=>(string)($row['code']??''),
                    'itemName'=>(string)($row['name']??''),
                    'category'=>(string)($row['category']??''),
                    'location'=>(string)($row['location']??''),
                    'quantity'=>(int)($row['quantity']??0),
                    'unit'=>(string)($row['unit']??''),
                    'condition'=>(string)($row['condition_status']??''),
                    'purchaseDate'=>$row['purchase_date']??null,
                    'price'=>(float)($row['price']??0),
                    'notesHash'=>hash('sha256',(string)($row['notes']??'')),
                    'version'=>(int)($row['version']??0),
                ];
            }
            if ($table === 'inventory_maintenance') {
                return [
                    'id'=>(string)($row['id']??''),
                    'inventoryId'=>(string)($row['inventory_id']??''),
                    'maintenanceDate'=>$row['maintenance_date']??null,
                    'actionHash'=>hash('sha256',(string)($row['action_taken']??'')),
                    'cost'=>(float)($row['cost']??0),
                    'staffFingerprint'=>hash('sha256',(string)($row['staff_name']??'')),
                    'notesHash'=>hash('sha256',(string)($row['notes']??'')),
                    'version'=>(int)($row['version']??0),
                ];
            }
            return sanitizeAuditValue($row);
        };

        $syncEntity = function($table, $idColumn, $item, $whitelist, $updatedColumn, $source, &$syncedIds) use (
            $pdo, $loggedInStaff, $resolutionMap, &$conflicts, &$syncCount, $parseTs,
            $makeSyncOperationId, $claimSyncOperation, $releaseSyncOperation, $recordSyncOperation,
            $syncEntityAuditSnapshot
        ) {
            if (!is_array($item) || empty($item[$idColumn])) return;
            $id=(string)$item[$idColumn];
            // Tombstone adalah sumber kebenaran lintas perangkat. Queue lama tidak
            // boleh menghidupkan kembali entity yang sudah dihapus di server.
            if (syncEntityHasTombstone($pdo,(string)$table,$id)) {
                $syncedIds[]=$id;
                return;
            }
            $syncOperationId=$makeSyncOperationId($table,$id,'upsert',$item);
            $claim=$claimSyncOperation($syncOperationId,$table,$id,'upsert',$item);
            if (!empty($claim['mismatch'])) {
                $conflicts[]=[
                    'id'=>$table.':'.$id,'entityType'=>$table,'entityId'=>$id,
                    'localData'=>$item,'serverData'=>$claim['existing'] ?? null,
                    'message'=>'Operation ID yang sama dikirim dengan payload berbeda.'
                ];
                return;
            }
            if (!empty($claim['duplicate'])) {
                $syncedIds[]=$id;
                return;
            }
            if (!empty($claim['inProgress'])) {
                $conflicts[]=[
                    'id'=>$table.':'.$id,'entityType'=>$table,'entityId'=>$id,
                    'localData'=>$item,'serverData'=>$claim['existing'] ?? null,
                    'message'=>'Operasi sinkronisasi yang sama masih berstatus processing dan belum boleh dianggap berhasil.'
                ];
                return;
            }
            $stmt=$pdo->prepare("SELECT * FROM `{$table}` WHERE `{$idColumn}` = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            $server=$stmt->fetch();
            $conflictId=$table.':'.$id;
            $choice=$resolutionMap[$conflictId] ?? $resolutionMap[$id] ?? null;

            if ($server) {
                $serverTs=$parseTs($server[$updatedColumn] ?? $server['updatedAt'] ?? $server['updated_at'] ?? null);
                $clientBase=$parseTs($item['_baseUpdatedAt'] ?? $item[$updatedColumn] ?? $item['updatedAt'] ?? $item['updated_at'] ?? null);
                if ($serverTs > 0 && $clientBase > 0 && $serverTs > $clientBase + 1) {
                    if ($choice === 'server') { $recordSyncOperation($syncOperationId,$table,$id,'upsert',$item,['resolution'=>'server']); $syncedIds[]=$id; return; }
                    if ($choice !== 'local') {
                        if (!empty($claim['claimed'])) $releaseSyncOperation($syncOperationId);
                        $conflicts[]=[
                            'id'=>$conflictId,
                            'entityType'=>$table,
                            'entityId'=>$id,
                            'localData'=>$item,
                            'serverData'=>$server,
                            'message'=>"Data {$table} {$id} berubah di server setelah perangkat terakhir menarik data."
                        ];
                        return;
                    }
                }
                if ($table === 'transactions' && transactionIsProtected($server)) {
                    if (!empty($claim['claimed'])) $releaseSyncOperation($syncOperationId);
                    $conflicts[]=[
                        'id'=>$conflictId,'entityType'=>$table,'entityId'=>$id,
                        'localData'=>$item,'serverData'=>$server,
                        'message'=>'Transaksi otomatis tidak boleh diedit dari antrean offline.'
                    ];
                    return;
                }
            }

            $dbColumns=[];
            try {
                $q=$pdo->query("SHOW COLUMNS FROM `{$table}`");
                while ($col=$q->fetch()) $dbColumns[]=$col['Field'] ?? $col['field'] ?? '';
            } catch (Throwable $e) { throw new RuntimeException('Schema target sinkronisasi tidak dapat diverifikasi; sync dibatalkan untuk mencegah penulisan kolom yang salah.',0,$e); }
            if(empty($dbColumns)) throw new RuntimeException('Schema target sinkronisasi kosong/tidak terbaca.');
            $findDbCol=function($candidate) use ($dbColumns) {
                foreach ($dbColumns as $col) if (strcasecmp($col,$candidate)===0) return $col;
                return null;
            };
            $filtered=[];
            foreach ($whitelist as $key) {
                if (!array_key_exists($key,$item)) continue;
                $dbKey=$findDbCol($key); if (!$dbKey) continue;
                $value=$item[$key];
                if ($key==='proofUrl' && isPlaceholderProof($value)) continue;
                if (is_array($value)) $value=json_encode($value);
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',$value)) $value=date('Y-m-d H:i:s',strtotime($value));
                $filtered[$dbKey]=$value;
            }
            if ($table==='transactions') {
                $createdCol=$findDbCol('createdBy');
                if ($createdCol) $filtered[$createdCol]=currentStaffLabel($loggedInStaff);
                $kind=normalizeTransactionKind((string)($item['transactionKind'] ?? inferTransactionKind($item)));
                $kindCol=$findDbCol('transactionKind');
                if ($kindCol) $filtered[$kindCol]=$kind;
                $protectedKinds=['booking_payment','booking_charge','down_payment','settlement','internal_transfer','salary_payment','maintenance_cost','ota_transfer'];
                $isProtectedKind=in_array($kind,$protectedKinds,true);
                $sysCol=$findDbCol('isSystemGenerated');
                if ($sysCol) $filtered[$sysCol]=$isProtectedKind ? 1 : 0;
                if ($isProtectedKind && in_array($kind,['booking_payment','booking_charge','down_payment','settlement'],true)) {
                    $sourceEntityCol=$findDbCol('sourceEntity');
                    $sourceEntityIdCol=$findDbCol('sourceEntityId');
                    $linkedBookingId=(string)($item['bookingId'] ?? $item['sourceEntityId'] ?? '');
                    if ($sourceEntityCol) $filtered[$sourceEntityCol]='booking';
                    if ($sourceEntityIdCol) $filtered[$sourceEntityIdCol]=$linkedBookingId;

                    // Terapkan kebijakan pajak server pada transaksi reservasi yang berasal dari antrean offline.
                    if ($linkedBookingId !== '') {
                        $stmtBookingTax=$pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
                        $stmtBookingTax->execute([$linkedBookingId]);
                        $bookingForTax=$stmtBookingTax->fetch();
                        if ($bookingForTax) {
                            $amountForTax=(float)($item['amount'] ?? $server['amount'] ?? 0);
                            $tax=resolveBookingChargeTaxPolicy($bookingForTax, array_merge($item,['transactionKind'=>$kind]), $amountForTax);
                            foreach (['baseAmount'=>$tax['baseAmount'],'taxAmount'=>$tax['taxAmount'],'taxRate'=>$tax['taxRate']] as $taxKey=>$taxValue) {
                                $taxCol=$findDbCol($taxKey);
                                if ($taxCol) $filtered[$taxCol]=$taxValue;
                            }
                        }
                    }
                }
            }
            $usesSnakeMetadata=in_array($table,['inventory','inventory_maintenance'],true);
            $byCol=$findDbCol($usesSnakeMetadata ? 'updated_by_staff_id' : 'updatedBy');
            if ($byCol) $filtered[$byCol]=$loggedInStaff['id'] ?? null;
            $sourceCol=$findDbCol($usesSnakeMetadata ? 'updated_source' : 'updatedSource');
            if ($sourceCol) $filtered[$sourceCol]=$source;
            $versionCol=$findDbCol('version');
            $nextVersion=(int)($server['version'] ?? 0)+1;
            if ($versionCol) $filtered[$versionCol]=$nextVersion;
            unset($filtered[$updatedColumn]);

            if ($server) {
                if($table==='transactions'){
                    $transactionPatch=$filtered;
                    foreach(array_keys($transactionPatch) as $key)if(strcasecmp((string)$key,$idColumn)===0)unset($transactionPatch[$key]);
                    if($transactionPatch)tamasyaApplySyncedTransactionPatch($pdo,$id,$transactionPatch,$loggedInStaff,(string)$source);
                }else{
                    $sets=[]; $params=[];
                    foreach ($filtered as $key=>$value) {
                        if (strcasecmp($key,$idColumn)===0) continue;
                        $sets[]="`{$key}` = ?"; $params[]=$value;
                    }
                    if ($sets) { $params[]=$id; $stmt=$pdo->prepare("UPDATE `{$table}` SET ".implode(', ',$sets)." WHERE `{$idColumn}` = ?"); $stmt->execute($params); }
                }
            } else {
                $idDb=$findDbCol($idColumn) ?: $idColumn;
                $filtered[$idDb]=$id;
                if ($table==='transactions') {
                    // FIX23: even historical/offline Finance creation crosses the
                    // canonical posting authority.  Generic sync remains a transport
                    // writer for non-ledger tables only.
                    $kind=normalizeTransactionKind((string)($filtered['transactionKind']??'manual'));
                    $authority=tamasyaFinancialPostingOwnerForKind($kind);
                    if($authority===null) throw new DomainException('transactionKind sinkronisasi tidak mempunyai authority canonical: '.$kind);
                    tamasyaPostFinancialTransaction(
                        $pdo,
                        $filtered,
                        $loggedInStaff,
                        $authority,
                        ['source'=>$source,'requireCatalog'=>true,'lockCatalog'=>true]
                    );
                } else {
                    $cols=array_keys($filtered); $params=array_values($filtered);
                    $stmt=$pdo->prepare("INSERT INTO `{$table}` (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")");
                    $stmt->execute($params);
                }
            }
            $afterSyncStmt=$pdo->prepare("SELECT * FROM `{$table}` WHERE `{$idColumn}` = ? LIMIT 1");
            $afterSyncStmt->execute([$id]);
            $afterSync=$afterSyncStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$afterSync) throw new RuntimeException('Data hasil sinkronisasi tidak dapat diverifikasi.');
            writeRequiredEnterpriseAudit(
                $pdo,
                $loggedInStaff,
                $server ? 'Memperbarui data offline' : 'Membuat data offline',
                (string)$table,
                $id,
                $syncEntityAuditSnapshot((string)$table,$server?:null),
                $syncEntityAuditSnapshot((string)$table,$afterSync),
                (string)$source
            );
            $recordSyncOperation($syncOperationId,$table,$id,'upsert',$item,['entityId'=>$id]);
            $syncedIds[]=$id; $syncCount++;
        };

        try {
            if ($clientRooms || $clientBookings) {
                requireRoles($loggedInStaff, ['admin','manager','receptionist']);
                requireDesktopTabAccess($loggedInStaff,'rooms',['admin','manager','receptionist']);
            }
            if ($deletedRoomIds || $deletedBookingIds) {
                requireRoles($loggedInStaff, ['admin','manager']);
                requireDesktopTabAccess($loggedInStaff,'rooms',['admin','manager']);
            }
            if ($clientInventory || $deletedInventoryIds) {
                $inventorySyncRoles=$deletedInventoryIds ? ['admin','manager'] : ['admin','manager','finance'];
                requireRoles($loggedInStaff, $inventorySyncRoles);
                requireDesktopTabAccess($loggedInStaff,'inventory',$inventorySyncRoles);
            }
            if ($clientInventoryMaintenance) {
                $maintenanceSyncRoles=['admin','manager','finance','cleaning_service'];
                requireRoles($loggedInStaff, $maintenanceSyncRoles);
                requireDesktopTabAccess($loggedInStaff,'inventory',$maintenanceSyncRoles);
            }
            if ($deletedMaintenanceIds) {
                requireRoles($loggedInStaff, ['admin','manager']);
                requireDesktopTabAccess($loggedInStaff,'inventory',['admin','manager']);
            }
            if ($clientBookingActions) {
                requireRoles($loggedInStaff, ['admin','manager','finance']);
                requireDesktopTabAccess($loggedInStaff,'finance',['admin','manager','finance']);
            }
            if ($clientTransactions || $deletedTransactionIds) {
                // Generic transaction sync is reserved for historical backfill by
                // Admin/Manager/Finance.  Reception booking payments must use the
                // canonical booking payment/allocation workflow so shift, tax and
                // idempotency invariants cannot diverge.
                requireRoles($loggedInStaff, ['admin','manager','finance']);
                requireDesktopTabAccess($loggedInStaff,'finance',['admin','manager','finance']);
            }

            $pdo->beginTransaction();
            $syncActorRole = strtolower((string)($loggedInStaff['role'] ?? ''));
            $bookingManagedRooms = [];
            foreach ($clientBookings as $bookingStatusItem) {
                if (!is_array($bookingStatusItem)) continue;
                $newRoom = trim((string)($bookingStatusItem['roomNumber'] ?? ''));
                if ($newRoom !== '') $bookingManagedRooms[$newRoom] = true;
                $existingBookingId = trim((string)($bookingStatusItem['id'] ?? ''));
                if ($existingBookingId !== '') {
                    $stmtExistingBookingRoom = $pdo->prepare("SELECT roomNumber FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
                    $stmtExistingBookingRoom->execute([$existingBookingId]);
                    $oldRoom = trim((string)($stmtExistingBookingRoom->fetchColumn() ?: ''));
                    if ($oldRoom !== '') $bookingManagedRooms[$oldRoom] = true;
                }
            }
            foreach ($clientRooms as $item) {
                if (!is_array($item)) continue;
                if (empty($item['id']) && !empty($item['number'])) $item['id']='r'.$item['number'];
                $roomId=(string)($item['id'] ?? '');
                if ($roomId!=='' && syncEntityHasTombstone($pdo,'rooms',$roomId)) {
                    $syncedRoomIds[]=$roomId;
                    continue;
                }
                $stmtExistingRoom=$pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtExistingRoom->execute([$roomId]);
                $existingRoom=$stmtExistingRoom->fetch();
                $previousRoomNumber=trim((string)($existingRoom['number'] ?? ''));

                // R6-R5: rooms.status is server-derived projection only. Legacy/offline
                // clients may still carry a cached status, but generic sync must never
                // persist it. Domain records (booking/HK/maintenance/key/hold/etc.) own
                // the state; the server reconciles the projection after domain sync.
                unset($item['status']);

                if ($syncActorRole === 'receptionist') {
                    if (!$existingRoom) {
                        $conflicts[]=['id'=>'rooms:'.$roomId,'entityType'=>'rooms','entityId'=>$roomId,'localData'=>$item,'serverData'=>null,'message'=>'Resepsionis tidak boleh membuat atau mengubah master kamar lewat antrean offline. Status kamar dihitung otomatis dari lifecycle domain.'];
                        continue;
                    }
                    foreach (['number','type','price','floor'] as $masterField) {
                        if (array_key_exists($masterField,$item) && (string)$item[$masterField] !== (string)($existingRoom[$masterField] ?? '')) {
                            $conflicts[]=['id'=>'rooms:'.$roomId,'entityType'=>'rooms','entityId'=>$roomId,'localData'=>$item,'serverData'=>$existingRoom,'message'=>'Resepsionis tidak boleh mengubah master kamar lewat antrean offline. Gunakan workflow Booking/Housekeeping/Maintenance; status kamar adalah output lifecycle.'];
                            continue 2;
                        }
                    }
                    // Acknowledge legacy room-status queue without writing it back.
                    $syncedRoomIds[]=$roomId;
                    if($previousRoomNumber!=='')tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$previousRoomNumber,'offline-room-projection');
                    continue;
                }

                if ($existingRoom && array_key_exists('number',$item) && (string)$item['number'] !== (string)$existingRoom['number']) {
                    $stmtRoomReferences=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=?");
                    $stmtRoomReferences->execute([(string)$existingRoom['number']]);
                    if ((int)$stmtRoomReferences->fetchColumn()>0) {
                        $conflicts[]=['id'=>'rooms:'.$roomId,'entityType'=>'rooms','entityId'=>$roomId,'localData'=>$item,'serverData'=>$existingRoom,'message'=>'Nomor kamar tidak boleh diubah karena sudah direferensikan booking.'];
                        continue;
                    }
                }

                // Only room-master attributes are synchronizable. Status is deliberately
                // absent from the allowlist so no offline client can create a phantom
                // "Servis"/"Tersedia" state without a matching domain record.
                $item=array_intersect_key($item,array_flip(['id','number','type','price','floor','_baseUpdatedAt','_operationId','operationId']));
                if(!$existingRoom && (trim((string)($item['number']??''))==='' || trim((string)($item['type']??''))==='')){
                    $conflicts[]=['id'=>'rooms:'.$roomId,'entityType'=>'rooms','entityId'=>$roomId,'localData'=>$item,'serverData'=>null,'message'=>'Master kamar offline baru wajib memiliki nomor dan tipe; status awal tetap ditentukan server.'];
                    continue;
                }
                $syncEntity('rooms','id',$item,['id','number','type','price','floor'],'updatedAt','offline-sync',$syncedRoomIds);
                if (in_array($roomId,$syncedRoomIds,true)) {
                    $syncedRoomNumber = trim((string)($item['number'] ?? $previousRoomNumber));
                    if ($syncedRoomNumber !== '') {
                        if ($previousRoomNumber !== '' && $previousRoomNumber !== $syncedRoomNumber) {
                            $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,smart_lock_provider,smart_lock_device_id,smart_lock_enabled,last_event_at,updated_by,updated_at)
                                SELECT ?,access_mode,CASE WHEN physical_key_ref IS NULL OR physical_key_ref='' OR physical_key_ref=CONCAT('KEY-',?) THEN CONCAT('KEY-',?) ELSE physical_key_ref END,physical_key_status,current_booking_id,smart_lock_provider,smart_lock_device_id,smart_lock_enabled,last_event_at,?,CURRENT_TIMESTAMP
                                FROM room_access_control WHERE room_number=?
                                ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),physical_key_ref=VALUES(physical_key_ref),physical_key_status=VALUES(physical_key_status),current_booking_id=VALUES(current_booking_id),smart_lock_provider=VALUES(smart_lock_provider),smart_lock_device_id=VALUES(smart_lock_device_id),smart_lock_enabled=VALUES(smart_lock_enabled),last_event_at=VALUES(last_event_at),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                                ->execute([$syncedRoomNumber,$previousRoomNumber,$syncedRoomNumber,$loggedInStaff['id'] ?? null,$previousRoomNumber]);
                            $pdo->prepare("DELETE FROM room_access_control WHERE room_number=?")->execute([$previousRoomNumber]);
                        }
                        $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,last_event_at,updated_by,updated_at)
                            VALUES (?,'physical',?,'secured',NULL,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE physical_key_ref=COALESCE(NULLIF(physical_key_ref,''),VALUES(physical_key_ref)),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                            ->execute([$syncedRoomNumber,'KEY-'.$syncedRoomNumber,$loggedInStaff['id'] ?? null]);
                        tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$syncedRoomNumber,'offline-room-master-projection');
                    }
                }
            }
            $syncCheckinTime='14:00:00';
            $syncCheckoutTime='12:00:00';
            try{
                $syncSettingsStmt=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1");
                $syncSettings=$syncSettingsStmt?$syncSettingsStmt->fetch(PDO::FETCH_ASSOC):false;
                if($syncSettings){
                    $syncCheckinTime=substr((string)($syncSettings['checkin_time']??'14:00:00'),0,8);
                    $syncCheckoutTime=substr((string)($syncSettings['checkout_time']??'12:00:00'),0,8);
                }
            }catch(Throwable $ignoredSyncOperationalClock){}
            foreach ($clientBookings as $item) {
                if (!is_array($item) || empty($item['id'])) continue;
                $bookingId=(string)$item['id'];
                if(syncEntityHasTombstone($pdo,'bookings',$bookingId)){$syncedBookingIds[]=$bookingId;continue;}
                $stmtExistingBooking=$pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtExistingBooking->execute([$bookingId]);
                $existingBooking=$stmtExistingBooking->fetch();

                // completed/cancelled di server adalah terminal dan selalu menang atas
                // cache IndexedDB lama. Queue ditandai selesai tanpa menulis ulang row.
                if($existingBooking && in_array(strtolower((string)($existingBooking['status']??'')),['completed','cancelled'],true)){
                    $syncedBookingIds[]=$bookingId;
                    continue;
                }

                $syncBookingCandidate=$existingBooking ? array_merge($existingBooking,$item) : $item;
                try{
                    $incomingStayWindow=resolveHotelBookingStayWindow($syncBookingCandidate,$syncCheckoutTime,$syncCheckinTime);
                }catch(InvalidArgumentException $syncStayWindowError){
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking?:null,'message'=>$syncStayWindowError->getMessage()];
                    continue;
                }
                $incomingCheckIn=(string)$incomingStayWindow['checkIn'];
                $incomingCheckOut=(string)$incomingStayWindow['checkOut'];
                $incomingOpenEnded=(bool)$incomingStayWindow['isOpenEnded'];
                $incomingStartAt=(string)$incomingStayWindow['startAt'];
                $incomingEndAt=(string)$incomingStayWindow['endAt'];
                $item['checkIn']=$incomingCheckIn;
                $item['checkOut']=$incomingCheckOut;
                $item['isOpenEnded']=$incomingOpenEnded?1:0;
                $item['stayMode']=(string)$incomingStayWindow['stayMode'];
                $item['scheduledCheckInAt']=$incomingStayWindow['scheduledCheckInAt'];
                $item['scheduledCheckOutAt']=$incomingStayWindow['scheduledCheckOutAt'];
                $item['checkoutDueAt']=$incomingStayWindow['checkoutDueAt'];

                $incomingBookingStatus=(string)($item['status'] ?? ($existingBooking['status'] ?? 'active'));
                $incomingPaymentStatus=(string)($item['paymentStatus'] ?? ($existingBooking['paymentStatus'] ?? 'unpaid'));
                $incomingTotal=round((float)($item['totalAmount'] ?? ($existingBooking['totalAmount'] ?? 0)),2);
                $incomingDp=round((float)($item['downPaymentAmount'] ?? ($existingBooking['downPaymentAmount'] ?? 0)),2);
                if (!in_array($incomingBookingStatus,['reserved','active','completed','cancelled'],true)
                    || !in_array($incomingPaymentStatus,['paid','partial','unpaid'],true)
                    || $incomingTotal < 0 || $incomingDp < 0 || (!$incomingOpenEnded && $incomingDp > $incomingTotal + 1)) {
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking?:null,'message'=>'Nilai/status booking offline tidak valid.'];
                    continue;
                }
                if(!$existingBooking){
                    $incomingSplitCash=max(0.0,round((float)($item['splitCashAmount']??0),2));
                    $incomingSplitTransfer=max(0.0,round((float)($item['splitTransferAmount']??0),2));
                    if($incomingPaymentStatus!=='unpaid' || $incomingDp>0.01 || $incomingSplitCash>0.01 || $incomingSplitTransfer>0.01){
                        $conflicts[]=[
                            'id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>null,
                            'message'=>'Booking offline baru hanya boleh membuat reservasi tanpa penerimaan uang. Panjar/pembayaran wajib diposting online melalui ledger canonical agar kas, shift, pajak, dan audit tetap seimbang.'
                        ];
                        continue;
                    }
                    $item['paymentStatus']='unpaid';
                    $item['downPaymentAmount']=0;
                    $item['splitCashAmount']=0;
                    $item['splitTransferAmount']=0;
                }
                if($existingBooking && strtolower((string)($existingBooking['status']??''))==='active'){
                    try{$existingStayWindow=resolveHotelBookingStayWindow($existingBooking,$syncCheckoutTime,$syncCheckinTime);}
                    catch(InvalidArgumentException $existingStayError){
                        $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Jadwal booking aktif di server tidak valid dan wajib dikoreksi online sebelum sinkronisasi: '.$existingStayError->getMessage()];
                        continue;
                    }
                    $activeStayWindowChanged=(string)$existingStayWindow['startAt']!==$incomingStartAt
                        || (string)$existingStayWindow['endAt']!==$incomingEndAt
                        || (string)$existingStayWindow['stayMode']!==(string)$incomingStayWindow['stayMode']
                        || (bool)$existingStayWindow['isOpenEnded']!==$incomingOpenEnded;
                    if($activeStayWindowChanged){
                        $conflicts[]=[
                            'id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,
                            'localData'=>$item,'serverData'=>$existingBooking,
                            'message'=>'Perubahan masa inap booking aktif wajib online melalui workflow canonical agar overlap reservasi, checkoutDueAt, smart-lock, folio, dan audit tetap sinkron.'
                        ];
                        continue;
                    }
                }
                if($existingBooking){
                    $financialMutation=false;
                    if(array_key_exists('totalAmount',$item) && !moneyMatches((float)$item['totalAmount'],(float)($existingBooking['totalAmount']??0),0.01))$financialMutation=true;
                    if(array_key_exists('paymentStatus',$item) && (string)$item['paymentStatus']!==(string)($existingBooking['paymentStatus']??''))$financialMutation=true;
                    foreach(['downPaymentAmount','splitCashAmount','splitTransferAmount'] as $moneyField){
                        if(array_key_exists($moneyField,$item) && !moneyMatches((float)($item[$moneyField]??0),(float)($existingBooking[$moneyField]??0),0.01))$financialMutation=true;
                    }
                    if(array_key_exists('extras',$item)){
                        $normalizeExtras=static function($value): string {
                            $rows=tamasyaDecodeBookingExtras($value);$normalized=[];
                            foreach($rows as $row){
                                $normalized[]=[
                                    'id'=>(string)($row['id']??''),'name'=>(string)($row['name']??''),'price'=>round((float)($row['price']??0),2),
                                    'qty'=>max(1,(int)($row['qty']??1)),'category'=>(string)($row['category']??''),'subcategory'=>(string)($row['subcategory']??''),
                                    'paymentStatus'=>(string)($row['paymentStatus']??''),'sourceTransactionId'=>(string)($row['sourceTransactionId']??''),
                                    'sourceAllocationId'=>(string)($row['sourceAllocationId']??'')
                                ];
                            }
                            usort($normalized,static fn($a,$b)=>strcmp($a['id'],$b['id']));
                            return json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]';
                        };
                        if($normalizeExtras($item['extras'])!==$normalizeExtras($existingBooking['extras']??null))$financialMutation=true;
                    }
                    if($financialMutation){
                        $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Perubahan total, pembayaran, atau layanan booking wajib online melalui workflow transaksi atomik; generic offline sync tidak diizinkan.'];
                        continue;
                    }
                }
                $targetRoomNumber=trim((string)($item['roomNumber'] ?? ($existingBooking['roomNumber'] ?? '')));
                $existingRoomNumber=trim((string)($existingBooking['roomNumber']??''));
                if($existingBooking
                    && strtolower((string)($existingBooking['status']??''))==='active'
                    && $existingRoomNumber!==''
                    && $targetRoomNumber!==''
                    && $targetRoomNumber!==$existingRoomNumber){
                    $conflicts[]=[
                        'id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,
                        'localData'=>$item,'serverData'=>$existingBooking,
                        'message'=>'Pindah kamar untuk tamu aktif wajib online agar kunci, smart-lock, housekeeping, kamar asal, kamar tujuan, dan audit diproses atomik.'
                    ];
                    continue;
                }
                // R3 invariant: generic offline sync tidak pernah menciptakan status
                // privileged ACTIVE. Booking baru dari perangkat selalu masuk RESERVED;
                // check-in aktual wajib replay ke workflow online canonical.
                $derivedNewBookingStatus = 'reserved';
                // Status booking existing tidak boleh diubah oleh generic offline sync.
                // Semua transisi lifecycle wajib melalui workflow online yang atomik.
                $finalBookingStatus=$existingBooking
                    ? (string)($existingBooking['status'] ?? 'reserved')
                    : $derivedNewBookingStatus;
                $item['status']=$finalBookingStatus;
                // State operasional/akses/penutupan keuangan adalah server-authoritative.
                // Snapshot offline boleh membawanya untuk display, tetapi generic sync tidak
                // pernah menulis ulang state tersebut. Workflow khusus tetap menjadi satu-satunya
                // pemilik check-in/out, late checkout, kunci, smart-lock, dan financial closure.
                if($existingBooking){
                    foreach([
                        'actualCheckInAt','actualCheckOutAt','lateCheckoutStatus','lateCheckoutReason','lateCheckoutFee','lateCheckoutApprovedBy',
                        'keyControlStatus','accessMode','keyIssuedAt','keyIssuedBy','keyReturnedAt','keyReturnedBy',
                        'financialClosureStatus','financialClosureBalance','financialClosureReason','financialClosureAt','financialClosureBy','financialClosureSource','financialClosureOperationId'
                    ] as $serverOwnedBookingField){
                        if(array_key_exists($serverOwnedBookingField,$existingBooking))$item[$serverOwnedBookingField]=$existingBooking[$serverOwnedBookingField];
                        else unset($item[$serverOwnedBookingField]);
                    }
                }
                $statusTransition=!$existingBooking || (string)($existingBooking['status']??'')!==$finalBookingStatus;
                if(!$existingBooking && $finalBookingStatus!=='reserved'){
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>null,'message'=>'Booking offline baru selalu disimpan sebagai reserved. Check-in aktual wajib dilakukan online melalui workflow canonical.'];
                    continue;
                }
                if($existingBooking && (string)($existingBooking['status']??'')==='reserved' && $finalBookingStatus==='active'){
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Check-in reservasi wajib online agar kondisi kamar, housekeeping, akses, dan konflik multi-user divalidasi atomik.'];
                    continue;
                }
                if($existingBooking && $statusTransition && in_array($finalBookingStatus,['completed','cancelled'],true)){
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Checkout dan pembatalan final wajib online melalui endpoint status khusus agar ledger, kunci, housekeeping, audit, dan sinkronisasi diproses atomik.'];
                    continue;
                }
                if ($targetRoomNumber==='') {
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking ?: null,'message'=>'Booking offline tidak memiliki nomor kamar yang valid.'];
                    continue;
                }
                $stmtTargetRoom=$pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
                $stmtTargetRoom->execute([$targetRoomNumber]);
                $targetRoom=$stmtTargetRoom->fetch();
                if (!$targetRoom) {
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking ?: null,'message'=>'Kamar tujuan booking offline tidak ditemukan di server.'];
                    continue;
                }
                if (in_array($finalBookingStatus,['reserved','active'],true)) {
                    $domainBlockers=getRoomOperationalBlockers($pdo,$targetRoomNumber,true);
                    if($finalBookingStatus==='reserved'){
                        $reservationBlockers=tamasyaReservationInventoryBlockers($domainBlockers);
                        if($reservationBlockers){
                            $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>['room'=>$targetRoom,'operationalBlockers'=>$reservationBlockers],'message'=>roomOperationalBlockerMessage($targetRoomNumber,$reservationBlockers)];
                            continue;
                        }
                    }elseif(!($existingBooking && (string)($existingBooking['status']??'')==='active')){
                        if(tamasyaDeriveRoomOperationalStatus($domainBlockers)!=='available'){
                            $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>['room'=>$targetRoom,'operationalBlockers'=>$domainBlockers],'message'=>roomOperationalBlockerMessage($targetRoomNumber,$domainBlockers) ?: 'Kamar tujuan belum siap untuk check-in aktual.'];
                            continue;
                        }
                    }
                    try{
                        tamasyaR3AssertStayWindowNoOverlap($pdo,$bookingId,$targetRoomNumber,$incomingStartAt,$incomingEndAt,true);
                    }catch(Throwable $overlapError){
                        $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking?:$targetRoom,'message'=>$overlapError->getMessage()];
                        continue;
                    }
                    if ($finalBookingStatus==='active') {
                        if(!$existingBooking && ($incomingDp > 0 || $incomingPaymentStatus === 'paid')) enforceOpenShiftForRoomSale($pdo,$loggedInStaff);
                        $roomBlockers=array_values(array_filter(
                            getRoomOperationalBlockers($pdo,$targetRoomNumber,true),
                            static fn(array $blocker): bool => !((string)($blocker['type']??'')==='active_booking' && (string)($blocker['id']??'')===$bookingId)
                        ));
                        if($roomBlockers){$conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>['room'=>$targetRoom,'operationalBlockers'=>$roomBlockers],'message'=>roomOperationalBlockerMessage($targetRoomNumber,$roomBlockers)];continue;}
                    }
                    $item['roomType']=(string)($targetRoom['type'] ?? ($item['roomType'] ?? ''));
                }
                $oldRoomNumber=$existingRoomNumber;
                if($existingBooking && (string)($existingBooking['status']??'')==='active' && $oldRoomNumber!=='' && $oldRoomNumber!==$targetRoomNumber){
                    $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Pindah kamar untuk booking aktif wajib online melalui workflow transfer agar kamar asal, kamar tujuan, biaya, housekeeping, akses, dan audit diproses atomik.'];
                    continue;
                }
                $accessStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");$accessStmt->execute([$targetRoomNumber]);$syncAccess=$accessStmt->fetch()?:['access_mode'=>'physical','physical_key_status'=>'secured','physical_key_ref'=>'KEY-'.$targetRoomNumber];
                if(!$existingBooking && $finalBookingStatus==='reserved'){
                    $item['actualCheckInAt']=null;
                    $item['actualCheckOutAt']=null;
                    $item['keyControlStatus']='not_issued';
                    $item['accessMode']=in_array((string)($syncAccess['access_mode']??''),['physical','smart','hybrid'],true)?(string)$syncAccess['access_mode']:'physical';
                    foreach(['keyIssuedAt','keyIssuedBy','keyReturnedAt','keyReturnedBy','financialClosureReason','financialClosureAt','financialClosureBy','financialClosureSource','financialClosureOperationId'] as $safeNullField)$item[$safeNullField]=null;
                    $item['financialClosureStatus']='not_required';
                    $item['financialClosureBalance']=0;
                }
                if(!$existingBooking && $finalBookingStatus==='active'){
                    $item['actualCheckInAt']=$item['actualCheckInAt']??date('Y-m-d H:i:s');
                    $item['checkoutDueAt']=$incomingStayWindow['checkoutDueAt'];
                    $item['accessMode']=$syncAccess['access_mode']??'physical';$item['keyControlStatus']=$item['keyControlStatus']??'not_issued';
                }
                if($existingBooking && $statusTransition && $finalBookingStatus==='completed'){
                    $mode=$syncAccess['access_mode']??($existingBooking['accessMode']??'physical');
                    if(in_array($mode,['smart','hybrid'],true) && !empty($existingBooking['smartLockCodeHash'])){$conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Check-out smart lock harus dilakukan saat online agar PIN dapat dicabut.'];continue;}
                    $outstanding=in_array($mode,['physical','hybrid'],true) && (($existingBooking['keyControlStatus']??'not_issued')==='issued' || ($syncAccess['physical_key_status']??'secured')==='issued');
                    if($outstanding && empty($item['keyReturnConfirmed'])){$conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>$item,'serverData'=>$existingBooking,'message'=>'Konfirmasi pengembalian kunci wajib sebelum checkout offline disinkronkan.'];continue;}
                    $item['actualCheckOutAt']=$item['actualCheckOutAt']??date('Y-m-d H:i:s');$item['keyControlStatus']='returned';$item['keyReturnedAt']=$item['keyReturnedAt']??date('Y-m-d H:i:s');$item['keyReturnedBy']=$loggedInStaff['id']??null;
                }
                // KTP dari antrean offline hanya diterima bila benar-benar berupa
                // gambar/referensi yang tervalidasi. hasKtpPhoto hanyalah indikator
                // UI dan tidak pernah menggantikan referensi server yang sudah ada.
                if (array_key_exists('ktpPhoto',$item)) {
                    try {
                        $item['ktpPhoto'] = normalizeBookingIdentityReferenceForStorage($pdo,$item['ktpPhoto']);
                        if ($item['ktpPhoto'] === null) unset($item['ktpPhoto']);
                    } catch (Throwable $identitySyncError) {
                        $conflicts[]=['id'=>'bookings:'.$bookingId,'entityType'=>'bookings','entityId'=>$bookingId,'localData'=>sanitizeAuditValue($item),'serverData'=>$existingBooking ?: null,'message'=>'Foto KTP offline ditolak: '.clientExceptionMessage('format tidak valid',$identitySyncError)];
                        continue;
                    }
                }

                // Server adalah sumber kebenaran pajak untuk booking offline.
                // Metadata historis booking selesai/batal yang sudah valid tidak
                // ditulis ulang ketika aturan pajak aktif berubah.
                $syncBookingSource=trim((string)($item['bookingSource']??($existingBooking['bookingSource']??'Direct')))?:'Direct';
                $syncBookingTotal=(float)($item['totalAmount']??($existingBooking['totalAmount']??0));
                $item['bookingSource']=$syncBookingSource;
                $incomingTax=tamasyaValidBookingTaxSnapshot($item,$syncBookingTotal);
                $existingTax=$existingBooking?tamasyaValidBookingTaxSnapshot($existingBooking,(float)($existingBooking['totalAmount']??0)):null;
                $sameTaxBasis=$existingBooking
                    && moneyMatches((float)($existingBooking['totalAmount']??0),$syncBookingTotal,0.01)
                    && strcasecmp(trim((string)($existingBooking['bookingSource']??'Direct')),$syncBookingSource)===0
                    && $normalizeExtras($item['extras']??($existingBooking['extras']??null))===$normalizeExtras($existingBooking['extras']??null);
                $existingHistoricalTaxValid=$existingTax!==null
                    && in_array((string)($existingBooking['status']??''),['completed','cancelled'],true);
                if($incomingTax!==null){
                    // Perangkat offline membawa snapshot sah yang dihitung saat mutasi.
                    $item['vatRate']=$incomingTax['taxRate'];
                    $item['vatAmount']=$incomingTax['taxAmount'];
                }elseif($existingHistoricalTaxValid||($existingTax!==null&&$sameTaxBasis)){
                    // Snapshot lama tetap dipertahankan bila dasar tagihan tidak berubah.
                    $item['vatRate']=$existingTax['taxRate'];
                    $item['vatAmount']=$existingTax['taxAmount'];
                }else{
                    $syncBookingTax=resolveBookingAggregateTaxSnapshot($pdo,array_merge($existingBooking?:[],$item,[
                        'bookingSource'=>$syncBookingSource,
                        'extras'=>$item['extras']??($existingBooking['extras']??null),
                        'checkIn'=>$item['checkIn']??($existingBooking['checkIn']??date('Y-m-d')),
                    ]),$syncBookingTotal,$item['checkIn']??($existingBooking['checkIn']??date('Y-m-d')));
                    $item['vatRate']=$syncBookingTax['taxRate'];
                    $item['vatAmount']=$syncBookingTax['taxAmount'];
                    $item['extras']=$syncBookingTax['extras'];
                }
                // Mirror the canonical booking guard (030_booking_finance.php):
                // downPaymentDate must be a real ISO date. The offline queue
                // never hard-fails the whole batch for a bad date; invalid
                // values are blanked so the payment amount stays while the
                // unreliable timestamp is dropped.
                if(array_key_exists('downPaymentDate',$item)){
                    $syncDownPaymentDate=trim((string)($item['downPaymentDate']??''));
                    $item['downPaymentDate']=validIsoDate($syncDownPaymentDate)?$syncDownPaymentDate:'';
                }
                $syncEntity('bookings','id',$item,['id','guestName','guestEmail','guestPhone','roomNumber','roomType','checkIn','checkOut','stayMode','scheduledCheckInAt','scheduledCheckOutAt','totalAmount','status','paymentStatus','paymentMethod','bankAccountId','ktpPhoto','extras','bookingSource','isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId','downPaymentAmount','downPaymentMethod','downPaymentBankAccountId','downPaymentDate','isOpenEnded','vatRate','vatAmount','createdAt','actualCheckInAt','actualCheckOutAt','checkoutDueAt','lateCheckoutStatus','lateCheckoutReason','lateCheckoutFee','lateCheckoutApprovedBy','keyControlStatus','accessMode','keyIssuedAt','keyIssuedBy','keyReturnedAt','keyReturnedBy','financialClosureStatus','financialClosureBalance','financialClosureReason','financialClosureAt','financialClosureBy','financialClosureSource','financialClosureOperationId'],'updatedAt','offline-sync',$syncedBookingIds);
                if (!in_array($bookingId,$syncedBookingIds,true)) continue;

                if ($finalBookingStatus==='active') {
                    finalizeActiveBookingOperationalState($pdo,$bookingId,$targetRoomNumber,$loggedInStaff,'offline-sync');
                } elseif ($statusTransition && in_array($finalBookingStatus,['completed','cancelled'],true)) {
                    $stmtRemaining=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=? AND status='active' AND id<>?");
                    $stmtRemaining->execute([$targetRoomNumber,$bookingId]);
                    if ((int)$stmtRemaining->fetchColumn()===0) {
                        if($finalBookingStatus==='completed'){
                            ensureCheckoutHousekeepingTask($pdo,$targetRoomNumber,$loggedInStaff,'offline-sync','Otomatis dari checkout offline');
                            tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$targetRoomNumber,'offline-sync-checkout');
                            $pdo->prepare("UPDATE room_access_control SET physical_key_status='secured',current_booking_id=NULL,last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")->execute([$loggedInStaff['id']??null,$targetRoomNumber]);
                            insertRoomKeyEvent($pdo,$loggedInStaff,$targetRoomNumber,$bookingId,'returned_offline_sync',$syncAccess['access_mode']??'physical',$syncAccess['physical_key_ref']??null,null,'returned','Kunci dikembalikan dan disinkronkan dari antrean offline.');
                        }else{
                            tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$targetRoomNumber,'offline-sync-cancel');
                        }
                    }
                }
                if ($oldRoomNumber!=='' && $oldRoomNumber!==$targetRoomNumber) {
                    $stmtOldRemaining=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=? AND status='active'");
                    $stmtOldRemaining->execute([$oldRoomNumber]);
                    if ((int)$stmtOldRemaining->fetchColumn()===0) {
                        ensureCheckoutHousekeepingTask($pdo,$oldRoomNumber,$loggedInStaff,'offline-sync','Otomatis: booking offline berpindah dari kamar asal; periksa kamar asal.');
                        tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$oldRoomNumber,'offline-sync-old-room');
                    }
                }
            }

            // OFFLINE OPERATIONAL MODE: replay queued lifecycle operations (check-in /
            // fully-paid checkout) through the canonical workflow. Each op is claimed in
            // the same sync_operations idempotency journal; duplicates are acknowledged,
            // canonical-gate failures surface as operator-visible conflicts.
            $lifecycleOpIndex = 0;
            foreach ($clientLifecycleOps as $lifecycleOpItem) {
                if (!is_array($lifecycleOpItem)) continue;
                $lifecycleOperationId = trim((string)($lifecycleOpItem['operationId'] ?? ''));
                if ($lifecycleOperationId === '') continue;
                $lifecycleOpIndex++;
                $savepoint = 'lifecycle_op_' . $lifecycleOpIndex;
                $pdo->exec('SAVEPOINT ' . $savepoint);

                $lifecycleConflictId = 'lifecycle_op:' . $lifecycleOperationId;
                $lifecycleSyncId = $makeSyncOperationId('lifecycle_op', $lifecycleOperationId, 'apply', $lifecycleOpItem);
                $lifecycleClaim = $claimSyncOperation($lifecycleSyncId, 'lifecycle_op', $lifecycleOperationId, 'apply', $lifecycleOpItem);
                if (!empty($lifecycleClaim['mismatch'])) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[] = ['id'=>$lifecycleConflictId,'entityType'=>'lifecycle_op','entityId'=>$lifecycleOperationId,'localData'=>$lifecycleOpItem,'serverData'=>$lifecycleClaim['existing'] ?? null,'message'=>'Operation ID operasi offline digunakan ulang dengan payload berbeda.'];
                    continue;
                }
                if (!empty($lifecycleClaim['duplicate'])) {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $syncedLifecycleOperationIds[] = $lifecycleOperationId;
                    $duplicateBookingId = trim((string)($lifecycleOpItem['bookingId'] ?? ''));
                    if ($duplicateBookingId !== '') $syncedBookingIds[] = $duplicateBookingId;
                    continue;
                }
                if (!empty($lifecycleClaim['inProgress'])) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[] = ['id'=>$lifecycleConflictId,'entityType'=>'lifecycle_op','entityId'=>$lifecycleOperationId,'localData'=>$lifecycleOpItem,'serverData'=>$lifecycleClaim['existing'] ?? null,'message'=>'Operasi offline masih diproses dan belum boleh dianggap selesai.'];
                    continue;
                }
                try {
                    $lifecycleResult = tamasyaApplyQueuedLifecycleOp($pdo, $loggedInStaff, $lifecycleOpItem);
                } catch (Throwable $lifecycleError) {
                    $lifecycleResult = ['status' => 'error', 'error' => clientExceptionMessage('Replay operasi offline gagal', $lifecycleError)];
                }
                $lifecycleStatus = $lifecycleResult['status'] ?? 'error';
                if ($lifecycleStatus === 'conflict') {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[] = $lifecycleResult['conflict'];
                    continue;
                }
                if ($lifecycleStatus === 'error') {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    // Keep the queue entry on the device: the operator reviews and retries.
                    $conflicts[] = [
                        'id' => $lifecycleConflictId,
                        'entityType' => 'lifecycle_op',
                        'entityId' => $lifecycleOperationId,
                        'localData' => $lifecycleOpItem,
                        'serverData' => null,
                        'message' => $lifecycleResult['error'] ?? 'Operasi offline gagal diterapkan.'
                    ];
                    continue;
                }
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $recordSyncOperation($lifecycleSyncId, 'lifecycle_op', $lifecycleOperationId, 'apply', $lifecycleOpItem, $lifecycleResult);
                $syncedLifecycleOperationIds[] = $lifecycleOperationId;
                $resolvedLifecycleBookingId = trim((string)($lifecycleResult['bookingId'] ?? ($lifecycleOpItem['bookingId'] ?? '')));
                if ($resolvedLifecycleBookingId !== '') $syncedBookingIds[] = $resolvedLifecycleBookingId;
                if ($lifecycleStatus === 'applied') $syncCount++;
            }

            // Booking action diproses sebagai satu unit atomik: booking + transaksi kas.
            $bookingActionIndex = 0;
            foreach ($clientBookingActions as $bookingActionItem) {
                if (!is_array($bookingActionItem)) continue;
                $operationId = trim((string)($bookingActionItem['operationId'] ?? ''));
                if ($operationId === '') continue;
                $bookingActionIndex++;
                $savepoint = 'booking_action_' . $bookingActionIndex;
                $pdo->exec('SAVEPOINT ' . $savepoint);

                $conflictId = 'booking_action:' . $operationId;
                $bookingActionSyncId=$makeSyncOperationId('booking_action',$operationId,'apply',$bookingActionItem);
                $bookingActionClaim=$claimSyncOperation($bookingActionSyncId,'booking_action',$operationId,'apply',$bookingActionItem);
                if (!empty($bookingActionClaim['mismatch'])) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[]=['id'=>$conflictId,'entityType'=>'booking_action','entityId'=>$operationId,'localData'=>$bookingActionItem,'serverData'=>$bookingActionClaim['existing'] ?? null,'message'=>'Operation ID booking action digunakan ulang dengan payload berbeda.'];
                    continue;
                }
                if (!empty($bookingActionClaim['duplicate'])) {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $syncedBookingActionOperationIds[]=$operationId;
                    continue;
                }
                if (!empty($bookingActionClaim['inProgress'])) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[]=['id'=>$conflictId,'entityType'=>'booking_action','entityId'=>$operationId,'localData'=>$bookingActionItem,'serverData'=>$bookingActionClaim['existing'] ?? null,'message'=>'Operasi booking masih berstatus processing dan belum boleh dianggap berhasil.'];
                    continue;
                }
                $choice = $resolutionMap[$conflictId] ?? $resolutionMap[$operationId] ?? null;
                $result = processManualBookingAction($pdo, $loggedInStaff, $bookingActionItem, 'offline-sync', $choice);
                $status = $result['status'] ?? 'error';

                if ($status === 'conflict') {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $conflicts[] = $result['conflict'];
                    continue;
                }
                if ($status === 'error') {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    // Jangan hilangkan queue. Tampilkan sebagai konflik operasional agar user dapat meninjau ulang.
                    $conflicts[] = [
                        'id' => $conflictId,
                        'entityType' => 'booking_action',
                        'entityId' => $operationId,
                        'localData' => $bookingActionItem,
                        'serverData' => null,
                        'message' => $result['error'] ?? 'Layanan/perpanjangan offline gagal diterapkan.'
                    ];
                    continue;
                }

                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $recordSyncOperation($bookingActionSyncId,'booking_action',$operationId,'apply',$bookingActionItem,$result);
                $syncedBookingActionOperationIds[] = $operationId;
                if (!empty($result['bookingId'])) $syncedBookingIds[] = (string)$result['bookingId'];
                if (!empty($result['transactionId'])) $syncedTransactionIds[] = (string)$result['transactionId'];
                if ($status === 'applied') $syncCount++;
            }

            $ledgerTouchedBookingIds=[];

            // Financial ledger is server-authoritative. For a transaction conflict the
            // safe user resolution is "server": keep any server row (if present) and
            // discard the local queued copy. This is also how a permanently invalid
            // local-only historical_import can be removed from the offline queue after
            // the user has reviewed the exact validation error. "local" never overwrites
            // an existing/invalid financial ledger row.
            $resolveHistoricalTransactionConflict = function(
                string $conflictId,
                string $incomingTxId,
                array $item,
                string $message,
                ?array $serverData = null
            ) use (&$resolutionMap,&$syncedTransactionIds,&$conflicts,$pdo,$loggedInStaff): bool {
                $choice=(string)($resolutionMap[$conflictId]??'');
                if($choice==='server' && $incomingTxId!==''){
                    if(!in_array($incomingTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$incomingTxId;
                    writeRequiredEnterpriseAudit(
                        $pdo,$loggedInStaff,
                        'Membuang antrean transaksi historis lokal setelah resolusi konflik',
                        'transactions',$incomingTxId,$item,
                        ['discardedLocalQueue'=>true,'serverRowPresent'=>$serverData!==null,'reason'=>$message],
                        'offline-sync-resolution'
                    );
                    return true;
                }
                $conflicts[]=[
                    'id'=>$conflictId,'entityType'=>'transactions','entityId'=>$incomingTxId,
                    'localData'=>$item,'serverData'=>$serverData,'message'=>$message,
                    'resolutionMode'=>'server_authoritative_discard_local'
                ];
                return false;
            };

            foreach ($clientTransactions as $item) {
                if (!is_array($item)) continue;
                $incomingTxId=trim((string)($item['id']??''));
                $incomingOperationId=trim((string)($item['operationId']??''));
                $conflictId='transactions:'.($incomingTxId?:'new');
                if($incomingTxId===''){
                    $conflicts[]=['id'=>$conflictId,'entityType'=>'transactions','entityId'=>'','localData'=>$item,'serverData'=>null,'message'=>'Transaksi historical_import offline wajib memiliki ID lokal stabil.','resolutionMode'=>'unresolvable_missing_id'];
                    continue;
                }
                if(syncEntityHasTombstone($pdo,'transactions',$incomingTxId)){$syncedTransactionIds[]=$incomingTxId;continue;}
                if($incomingOperationId===''){
                    if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,'Transaksi historical_import offline wajib memiliki operationId stabil.',null))continue;
                    continue;
                }
                // FINAL7: queue historical_import versi FINAL6 pernah membuat ID lokal
                // tx_pending_<operationId> lebih panjang dari VARCHAR(50). Pada MySQL
                // non-strict ID dapat terpotong saat INSERT lalu SELECT verifikasi dengan
                // ID asli gagal, memicu HTTP 500 dan retry runaway. Normalisasikan hanya
                // legacy provisional ID yang dapat dibuktikan mempunyai operationId stabil.
                $clientQueueTxId=$incomingTxId;
                $serverTxId=$incomingTxId;
                if(strlen($serverTxId)>50){
                    if(str_starts_with($serverTxId,'tx_pending_')){
                        $serverTxId='txh_'.substr(hash('sha256',$incomingOperationId),0,40);
                        $item['id']=$serverTxId;
                    }else{
                        if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,'ID transaksi offline melebihi batas 50 karakter dan bukan format provisional legacy yang aman dinormalisasi.',null))continue;
                        continue;
                    }
                }
                if(syncEntityHasTombstone($pdo,'transactions',$serverTxId)){
                    if(!in_array($serverTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$serverTxId;
                    if(!in_array($clientQueueTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$clientQueueTxId;
                    continue;
                }
                // Lock by BOTH stable row ID and operationId. A client may have a
                // legacy provisional row ID while the server already accepted the same
                // operation under its normalized ID. Replay is decided only after all
                // catalog/account/source/split fields have been canonicalized below.
                $existingOfflineTxStmt=$pdo->prepare('SELECT * FROM transactions WHERE id=? OR operationId=? ORDER BY (id=?) DESC LIMIT 1 FOR UPDATE');
                $existingOfflineTxStmt->execute([$serverTxId,$incomingOperationId,$serverTxId]);
                $existingOfflineTx=$existingOfflineTxStmt->fetch(PDO::FETCH_ASSOC)?:null;
                $recordOrigin=strtolower(trim((string)($item['recordOrigin']??'')));
                $txKind=normalizeTransactionKind((string)($item['transactionKind']??'manual'));
                $txAmount=round((float)($item['amount']??0),2);
                $txType=strtolower(trim((string)($item['type']??'')));
                $txCategory=trim((string)($item['category']??''));
                $txDate=trim((string)($item['date']??''));
                $txDescription=trim((string)($item['description']??''));
                $reason=trim((string)($item['shiftExemptionReason']??''));
                if($recordOrigin!=='historical_import' || $txKind!=='manual'){
                    if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,'Generic offline transaction sync hanya menerima historical_import manual baru. Transaksi live/booking wajib memakai workflow server canonical.',null))continue;
                    continue;
                }
                if($txAmount<=0 || !in_array($txType,['income','expense'],true) || $txCategory==='' || $txDescription==='' || !validIsoDate($txDate) || $txDate>date('Y-m-d') || strlen($reason)<10){
                    if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,'Data historical_import offline tidak valid: nominal, tipe, kategori, tanggal hari ini/masa lalu, deskripsi, dan alasan minimal 10 karakter wajib diisi.',null))continue;
                    continue;
                }
                if(trim((string)($item['bookingId']??''))!=='' || strtolower(trim((string)($item['sourceEntity']??'')))==='booking'){
                    if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,'Pengaitan historical_import ke booking wajib memakai workflow alokasi booking agar ledger dan total tagihan tetap atomik.',null))continue;
                    continue;
                }
                try{
                    // Offline historical/backfill must obey the same canonical master-data
                    // and bank-account rules as online manual finance.  Never trust stale
                    // category labels or bank IDs captured while the device was offline.
                    $offlineCatalog=tamasyaResolveActiveFinanceCatalogSelection(
                        $pdo,
                        $txType,
                        $txCategory,
                        array_key_exists('subcategory',$item) ? (trim((string)$item['subcategory']) ?: null) : null,
                        true,
                        trim((string)($item['categoryId']??$item['category_id']??'')) ?: null,
                        trim((string)($item['subcategoryId']??$item['subcategory_id']??'')) ?: null
                    );
                    $txCategory=$offlineCatalog['categoryName'];
                    $item['category']=$txCategory;
                    $item['subcategory']=$offlineCatalog['subcategoryName'];
                    // FIX44: inject the locked canonical catalog identity before tax
                    // resolution, same contract as the online Log Kas POST path.
                    // Without it a backfilled extra-service item carried no
                    // categorySystemKey, the tax kind degraded to 'manual'/'*' and
                    // the generic PBJT rule won over the kind-specific no-tax rule.
                    $item['categoryId']=$offlineCatalog['categoryId'];
                    $item['categorySystemKey']=$offlineCatalog['categorySystemKey'];
                    $item['subcategoryId']=$offlineCatalog['subcategoryId'];
                    $item['subcategorySystemKey']=$offlineCatalog['subcategorySystemKey'];
                    tamasyaManualFinanceEntryPolicy(
                        $offlineCatalog['category'],
                        'historical_import',
                        array_merge($item,['type'=>$txType,'category'=>$txCategory,'description'=>$txDescription])
                    );
                    $item['bankAccountId']=tamasyaValidateFinanceBankAccount($pdo,$item['bankAccountId']??null,true,true);

                    // FIX49: offline backfill may record a split cash+transfer receipt.
                    // Normalize and validate it with the same canonical rule as the online
                    // Log Kas POST path, then let syncEntity persist the snapshot columns.
                    // Without this the queued row silently landed in the ledger as a
                    // non-split transaction (splitCashAmount/splitTransferAmount = 0).
                    $offlineSplitCash=max(0.0,round((float)($item['splitCashAmount']??0),2));
                    $offlineSplitTransfer=max(0.0,round((float)($item['splitTransferAmount']??0),2));
                    $offlineSplitActive=!empty($item['isSplitPayment'])
                        || strtolower(trim((string)($item['paymentMethod']??'')))==='split'
                        || ($offlineSplitCash>0 && $offlineSplitTransfer>0);
                    $offlineSplitBank=trim((string)($item['splitTransferBankAccountId']??''));
                    if($offlineSplitActive && $offlineSplitBank===''){
                        // Same contract as online POST: legacy/current clients may keep
                        // the selected transfer/QRIS account in bankAccountId while the
                        // split-specific account field is absent.
                        $offlineSplitBank=trim((string)($item['bankAccountId']??''));
                    }
                    if($offlineSplitActive){
                        if($txType!=='income'||$offlineSplitCash<=0||$offlineSplitTransfer<=0||$offlineSplitBank===''||!moneyMatches($offlineSplitCash+$offlineSplitTransfer,$txAmount,0.01)){
                            throw new InvalidArgumentException('Data split historis tidak valid: tunai dan transfer harus keduanya lebih dari 0 dan jumlahnya sama dengan nominal transaksi.');
                        }
                        tamasyaInferPaymentMethodFromAccount($pdo,$offlineSplitBank,true,['transfer','qris']);
                        $item['isSplitPayment']=1;
                        $item['splitCashAmount']=$offlineSplitCash;
                        $item['splitTransferAmount']=$offlineSplitTransfer;
                        $item['splitTransferBankAccountId']=tamasyaValidateFinanceBankAccount($pdo,$offlineSplitBank,true,true);
                        // A split row has two explicit settlement legs; keeping a top-level
                        // bank account would make generic readers double-interpret it.
                        $item['bankAccountId']=null;
                    }else{
                        $item['isSplitPayment']=0;
                        $item['splitCashAmount']=0;
                        $item['splitTransferAmount']=0;
                        $item['splitTransferBankAccountId']=null;
                    }

                    // bookingSource is provenance only. Settlement/accounting is owned
                    // by bankAccountId/split evidence; a Traveloka-labelled backfill paid
                    // in cash must remain cash, while Piutang OTA requires ota_receivable.
                    $historicalBookingSource=trim((string)($item['bookingSource']??''));
                    if(strlen($historicalBookingSource)>255) throw new InvalidArgumentException('Sumber booking historis maksimal 255 karakter.');
                    $item['bookingSource']=$historicalBookingSource!==''?$historicalBookingSource:null;
                    tamasyaValidateManualOtaReceivableContext([
                        'type'=>$txType,'bankAccountId'=>$item['bankAccountId']??null,
                        'bookingSource'=>$item['bookingSource']??null
                    ],'historical_import');

                    $historicalMeta=tamasyaValidateHistoricalBackfillMetadata($item,$txDate);
                    // Normalize the server-owned invariant fields BEFORE replay comparison.
                    $item['recordOrigin']='historical_import';
                    $item['shiftExempt']=1;
                    $item['shiftExemptionReason']=$reason;
                    $item['transactionKind']='manual';
                    $item['bookingId']=null;
                    $item['sourceEntity']=null;
                    $item['sourceEntityId']=null;
                    $item['serviceDate']=$historicalMeta['serviceDate'];
                    $item['historicalSourceType']=$historicalMeta['historicalSourceType'];
                    $item['historicalSourceReference']=$historicalMeta['historicalSourceReference'];
                    $item['sourceReportedBy']=$historicalMeta['sourceReportedBy'];
                    $item['periodCorrectionReason']=$historicalMeta['periodCorrectionReason']?:$reason;
                    if($existingOfflineTx){
                        try{
                            if(!hash_equals((string)($existingOfflineTx['operationId']??''),$incomingOperationId)){
                                throw new RuntimeException('ID transaksi sudah ada dengan operationId berbeda.');
                            }
                            tamasyaAssertManualTransactionReplayMatches($existingOfflineTx,$item);
                            $acceptedServerId=(string)($existingOfflineTx['id']??$serverTxId);
                            if(!in_array($acceptedServerId,$syncedTransactionIds,true))$syncedTransactionIds[]=$acceptedServerId;
                            if(!in_array($serverTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$serverTxId;
                            if(!in_array($clientQueueTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$clientQueueTxId;
                            continue;
                        }catch(Throwable $replayMismatch){
                            $msg='Ledger server bersifat otoritatif. operationId/ID yang sama membawa detail finansial berbeda: '.$replayMismatch->getMessage();
                            if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,$msg,$existingOfflineTx))continue;
                            continue;
                        }
                    }

                    // Historical rows get the same canonical document-number discipline as
                    // online finance. A supplied legacy reference stays in provenance;
                    // documentNumber identifies this new ledger row.
                    $requestedDocumentNumber=trim((string)($item['documentNumber']??''));
                    if(strlen($requestedDocumentNumber)>80) throw new InvalidArgumentException('Nomor dokumen transaksi maksimal 80 karakter.');
                    $item['documentNumber']=$requestedDocumentNumber!==''
                        ? $requestedDocumentNumber
                        : nextDocumentNumber($pdo,$txType==='expense'?'EXP':'PAY',$txDate);

                    $historicalTax=tamasyaResolveTransactionTaxSnapshot($pdo,$item,$txAmount,true);
                    $periodImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$txDate,$txType,true,true);
                }catch(Throwable $historicalTaxError){
                    $historicalConflictMessage=tamasyaClientValidationExceptionMessage('Validasi transaksi historis gagal',$historicalTaxError);
                    if($resolveHistoricalTransactionConflict($conflictId,$incomingTxId,$item,$historicalConflictMessage,null))continue;
                    continue;
                }
                $item['baseAmount']=$historicalTax['baseAmount'];
                $item['taxAmount']=$historicalTax['taxAmount'];
                $item['taxRate']=$historicalTax['taxRate'];
                $item['taxSnapshotStatus']=$historicalTax['taxSnapshotStatus'];
                $item['taxSource']=$historicalTax['taxSource'];
                $item['taxRuleId']=$historicalTax['taxRuleId'];
                $item['reportingPeriod']=$periodImpact['periodKey'];
                $item['periodStatusAtEntry']=$periodImpact['periodStatusAtEntry'];
                $item['periodImpactStatus']=$periodImpact['periodImpactStatus'];
                $item['requiresTaxAmendment']=$periodImpact['requiresTaxAmendment']?1:0;
                $item['historicalReviewStatus']=$periodImpact['historicalReviewStatus'];
                $item['inputDelayDays']=tamasyaBackfillInputDelayDays($txDate,$item['createdAt']??null);
                $syncEntity('transactions','id',$item,[
                    'id','type','category','subcategory','roomNumber','amount','date','description','proofUrl','bankAccountId',
                    'isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId',
                    'bookingSource','documentNumber','baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','taxNote',
                    'transactionKind','sourceEntity','sourceEntityId','operationId','recordOrigin',
                    'shiftExempt','shiftExemptionReason','importBatchId','createdAt','serviceDate','historicalSourceType',
                    'historicalSourceReference','sourceReportedBy','reportingPeriod','periodStatusAtEntry','periodImpactStatus',
                    'requiresTaxAmendment','historicalReviewStatus','periodCorrectionReason','inputDelayDays'
                ],'updatedAt','offline-historical-import',$syncedTransactionIds);
                if(in_array($serverTxId,$syncedTransactionIds,true)){
                    if(!in_array($clientQueueTxId,$syncedTransactionIds,true))$syncedTransactionIds[]=$clientQueueTxId;
                    $savedHistoricalStmt=$pdo->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
                    $savedHistoricalStmt->execute([$serverTxId]);
                    $savedHistorical=$savedHistoricalStmt->fetch(PDO::FETCH_ASSOC)?:null;
                    if($savedHistorical){
                        tamasyaRegisterHistoricalBackfillAdjustment($pdo,$savedHistorical,$periodImpact,$loggedInStaff,$item['periodCorrectionReason'],$incomingOperationId);
                    }
                }
            }
            // Sinkronisasi offline tetap menghasilkan jurnal pada commit yang sama.
            syncJournalProjections($pdo,true);
            foreach(array_keys($ledgerTouchedBookingIds) as $ledgerTouchedBookingId){
                recalculateBookingFinancials($pdo,$ledgerTouchedBookingId,true);
                assertBookingLedgerInvariant($pdo,$ledgerTouchedBookingId,$loggedInStaff,'offline-sync',false);
            }
            foreach ($clientBookings as $bookingInvariantItem) {
                if (!is_array($bookingInvariantItem)) continue;
                $bookingInvariantId=trim((string)($bookingInvariantItem['id']??''));
                if ($bookingInvariantId==='' || !in_array($bookingInvariantId,$syncedBookingIds,true)) continue;
                try {
                    recalculateBookingFinancials($pdo,$bookingInvariantId,true);
                    assertBookingLedgerInvariant($pdo,$bookingInvariantId,$loggedInStaff,'offline-sync',true);
                } catch (Throwable $financeInvariantError) {
                    throw new RuntimeException(clientExceptionMessage('Konflik ledger booking; seluruh batch dibatalkan',$financeInvariantError),0,$financeInvariantError);
                }
            }

            foreach ($clientInventory as $item) {
                $inventoryId=trim((string)($item['id']??''));
                if($inventoryId!=='' && syncEntityHasTombstone($pdo,'inventory',$inventoryId)){$syncedInventoryIds[]=$inventoryId;continue;}
                $syncEntity('inventory','id',$item,['id','code','name','category','location','quantity','unit','condition_status','purchase_date','price','notes','created_at'],'updated_at','offline-sync',$syncedInventoryIds);
            }
            foreach ($clientInventoryMaintenance as $item) {
                $maintenanceId=trim((string)($item['id']??''));
                if($maintenanceId!=='' && syncEntityHasTombstone($pdo,'inventory_maintenance',$maintenanceId)){$syncedMaintenanceIds[]=$maintenanceId;continue;}
                $inventoryId=trim((string)($item['inventory_id']??''));
                if($inventoryId!=='' && syncEntityHasTombstone($pdo,'inventory',$inventoryId)){$syncedMaintenanceIds[]=$maintenanceId;continue;}
                $syncEntity('inventory_maintenance','id',$item,['id','inventory_id','maintenance_date','action_taken','cost','staff_name','notes','created_at'],'updated_at','offline-sync',$syncedMaintenanceIds);
                $assetConditionAfter=trim((string)($item['asset_condition_after']??''));
                if($assetConditionAfter!==''){
                    if(strlen($assetConditionAfter)>50)throw new InvalidArgumentException('Status kondisi aset dari antrean offline tidak valid.');
                    $assetLock=$pdo->prepare("SELECT id FROM inventory WHERE id=? LIMIT 1 FOR UPDATE");
                    $assetLock->execute([$inventoryId]);
                    if(!$assetLock->fetchColumn())throw new RuntimeException('Aset sasaran perawatan offline tidak ditemukan.');
                    $pdo->prepare("UPDATE inventory SET condition_status=?,updated_at=CURRENT_TIMESTAMP,updated_by_staff_id=?,updated_source='offline-maintenance' WHERE id=?")
                        ->execute([$assetConditionAfter,(string)($loggedInStaff['id']??''),$inventoryId]);
                }

                if (!empty($item['_recordAsExpense']) && (float)($item['cost'] ?? 0) > 0 && !empty($item['id'])) {
                    $maintId=(string)$item['id'];
                    $cost=(float)$item['cost'];
                    $bankAccountId=trim((string)($item['_bankAccountId'] ?? ''));
                    $paymentMethod=tamasyaInventoryMaintenancePaymentMethod($pdo,$item['_paymentMethod']??'', $bankAccountId);
                    $shiftSessionId=isset($item['_shiftSessionId'])?trim((string)$item['_shiftSessionId']):null;
                    if (in_array($loggedInStaff['role'] ?? '', ['admin','manager','finance'], true)) {
                        $txId=createInventoryMaintenanceExpense($pdo,$maintId,$cost,$loggedInStaff,$paymentMethod,$bankAccountId,$shiftSessionId);
                        if($txId)$syncedTransactionIds[]=$txId;
                    } else {
                        // Cleaning service mengirim usulan biaya, bukan transaksi kas langsung.
                        // Kas tidak boleh dianggap sudah dibayar tanpa shift operator yang sah.
                        if($paymentMethod==='cash')throw new InvalidArgumentException('Biaya maintenance offline dari Cleaning Service tidak boleh ditandai Tunai; pilih Hutang/Payable sebelum sinkronisasi.');
                        // ID deterministik mencakup payload finansial agar approval lama tidak dipakai untuk sumber dana berbeda.
                        $approvalFingerprint=$maintId.'|'.number_format($cost,2,'.','').'|'.$paymentMethod.'|'.$bankAccountId.'|'.(string)$shiftSessionId;
                        $approvalId='approval_invmaint_'.substr(hash('sha256',$approvalFingerprint),0,40);
                        $payload=json_encode(['actualCost'=>$cost,'paymentMethod'=>$paymentMethod,'bankAccountId'=>$bankAccountId,'shiftSessionId'=>$shiftSessionId],JSON_UNESCAPED_UNICODE);
                        $pdo->prepare("INSERT IGNORE INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,'maintenance_expense','inventory_maintenance',?,?,?, ?,?,?,'pending',CURRENT_TIMESTAMP)")
                            ->execute([$approvalId,$maintId,$cost,'Biaya perawatan offline memerlukan persetujuan finance/manager',$payload,$loggedInStaff['id'],$loggedInStaff['name']]);
                    }
                }
            }

            if ($deletedTransactionIds) requireRoles($loggedInStaff,['admin','manager','finance']);
            foreach ($deletedTransactionIds as $deleteItem) {
                $id=is_array($deleteItem) ? trim((string)($deleteItem['id'] ?? '')) : trim((string)$deleteItem);
                if($id==='')continue;
                $stmt=$pdo->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
                $stmt->execute([$id]);
                $server=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
                // Purge the obsolete local deletion marker but never delete money
                // ledger data through generic offline sync.  The authoritative row
                // is returned in the normal server snapshot on this response.
                $syncedDeletionIds[]=$id;
                if($server){
                    $conflicts[]=[
                        'id'=>'transactions:delete:'.$id,
                        'entityType'=>'transactions','entityId'=>$id,
                        'localData'=>['delete'=>true], 'serverData'=>$server,
                        'message'=>tamasyaTransactionIsHistorical($server)
                            ? 'Historical_import adalah ledger penerimaan asli dan tidak boleh dihapus. Marker hapus lokal dikarantina.'
                            : 'Penghapusan transaksi offline dinonaktifkan. Gunakan koreksi online ber-audit atau transaksi pembalik.'
                    ];
                }
            }

            $deletionConflict = function($table, $id, $server, $updatedColumn, $localData = ['delete'=>true]) use (&$conflicts, $resolutionMap, $lastPulledAt, $parseTs) {
                $conflictId=$table.':delete:'.$id;
                $choice=$resolutionMap[$conflictId] ?? $resolutionMap[$id] ?? null;
                $serverTs=$parseTs($server[$updatedColumn] ?? $server['updatedAt'] ?? $server['updated_at'] ?? null);
                $baseTs=$parseTs(is_array($localData) ? ($localData['_baseUpdatedAt'] ?? $lastPulledAt) : $lastPulledAt);
                if ($serverTs>0 && $baseTs>0 && $serverTs>$baseTs+1 && $choice!=='local') {
                    if ($choice==='server') return 'server';
                    $conflicts[]=['id'=>$conflictId,'entityType'=>$table,'entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>"Data {$table} berubah di server sebelum penghapusan offline diterapkan."];
                    return 'conflict';
                }
                return 'local';
            };

            foreach ($deletedRoomIds as $deleteItem) {
                $id=is_array($deleteItem) ? (string)($deleteItem['id'] ?? '') : (string)$deleteItem;
                if ($id==='') continue;
                $stmt=$pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $server=$stmt->fetch();
                if (!$server) { $syncedRoomDeletionIds[]=$id; continue; }
                $decision=$deletionConflict('rooms',$id,$server,'updatedAt',is_array($deleteItem)?$deleteItem:['delete'=>true]);
                if ($decision==='server') { $syncedRoomDeletionIds[]=$id; continue; }
                if ($decision==='conflict') continue;
                $stmt=$pdo->prepare("SELECT id,status,checkIn,checkOut FROM bookings WHERE roomNumber = ? AND status IN ('reserved','active') LIMIT 1 FOR UPDATE"); $stmt->execute([$server['number']]);
                $blockingRoomBooking=$stmt->fetch(PDO::FETCH_ASSOC);
                if ($blockingRoomBooking) {
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$blockingRoomBooking,'message'=>'Kamar tidak dapat dihapus karena masih memiliki reservasi menunggu atau booking aktif.'];
                    continue;
                }
                $accessDeleteStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
                $accessDeleteStmt->execute([(string)$server['number']]);
                $accessDeleteRow=$accessDeleteStmt->fetch(PDO::FETCH_ASSOC)?:null;
                if($accessDeleteRow && (!empty($accessDeleteRow['current_booking_id']) || in_array((string)($accessDeleteRow['physical_key_status']??'secured'),['issued','missing','override'],true))){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$accessDeleteRow,'message'=>'Kamar masih mempunyai penugasan booking atau status kunci yang belum aman.'];
                    continue;
                }
                $pendingHkDelete=$pdo->prepare("SELECT 1 FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') LIMIT 1 FOR UPDATE");
                $pendingHkDelete->execute([(string)$server['number']]);
                if($pendingHkDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai tugas housekeeping terbuka.'];
                    continue;
                }
                $pendingMaintenanceDelete=$pdo->prepare("SELECT 1 FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled') LIMIT 1 FOR UPDATE");
                $pendingMaintenanceDelete->execute([(string)$server['number'],'room:'.(string)$server['number']]);
                if($pendingMaintenanceDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai tiket maintenance aktif.'];
                    continue;
                }
                $pendingCustodyDelete=$pdo->prepare("SELECT 1 FROM lost_found_items WHERE room_number=? AND custody_status IN ('found','secured','guest_notified') LIMIT 1 FOR UPDATE");
                $pendingCustodyDelete->execute([(string)$server['number']]);
                if($pendingCustodyDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai custody Lost & Found terbuka.'];
                    continue;
                }
                $pendingReviewDelete=$pdo->prepare("SELECT 1 FROM maintenance_cancellation_reviews WHERE room_number=? AND status='open' LIMIT 1 FOR UPDATE");
                $pendingReviewDelete->execute([(string)$server['number']]);
                if($pendingReviewDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai review pembatalan maintenance terbuka.'];
                    continue;
                }
                $pendingIncidentDelete=$pdo->prepare("SELECT 1 FROM operational_incidents WHERE room_number=? AND status IN ('open','in_progress') LIMIT 1 FOR UPDATE");
                $pendingIncidentDelete->execute([(string)$server['number']]);
                if($pendingIncidentDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai security/safety/occupancy incident terbuka.'];
                    continue;
                }
                $pendingHoldDelete=$pdo->prepare("SELECT 1 FROM room_operational_holds WHERE room_number=? AND status='open' LIMIT 1 FOR UPDATE");
                $pendingHoldDelete->execute([(string)$server['number']]);
                if($pendingHoldDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai operational hold terbuka.'];
                    continue;
                }
                $pendingAlertDelete=$pdo->prepare("SELECT 1 FROM system_alerts WHERE acknowledged_at IS NULL AND source IN (?,?,?,?,?) LIMIT 1 FOR UPDATE");
                $pendingAlertDelete->execute(['lost-found:'.(string)$server['number'],'lost-found-custody:'.(string)$server['number'],'room-damage:'.(string)$server['number'],'maintenance-cancelled:'.(string)$server['number'],'key-missing:'.(string)$server['number']]);
                if($pendingAlertDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai alert operasional yang belum diselesaikan.'];
                    continue;
                }
                $pendingSmartDelete=$pdo->prepare("SELECT 1 FROM smart_lock_jobs WHERE room_number=? AND status NOT IN ('completed','cancelled') LIMIT 1 FOR UPDATE");
                $pendingSmartDelete->execute([(string)$server['number']]);
                if($pendingSmartDelete->fetchColumn()){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai pekerjaan smart-lock yang belum selesai.'];
                    continue;
                }
                $canonicalDeleteBlockers=getRoomOperationalBlockers($pdo,(string)$server['number'],true);
                if($canonicalDeleteBlockers){
                    $conflicts[]=['id'=>'rooms:delete:'.$id,'entityType'=>'rooms','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Kamar masih mempunyai blocker operasional canonical: '.roomOperationalBlockerMessage((string)$server['number'],$canonicalDeleteBlockers).'.'];
                    continue;
                }
                $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM room_access_control WHERE room_number=?")->execute([(string)$server['number']]);
                insertSyncTombstones($pdo,[['type'=>'rooms','id'=>$id]],'offline_room_delete_'.substr(hash('sha256',$id.'|'.(string)$server['number']),0,40),(string)$loggedInStaff['id']);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus kamar dari antrean offline','room',$id,$server,['deleted'=>true],'offline-sync-delete');
                $syncedRoomDeletionIds[]=$id; $syncCount++;
            }

            foreach ($deletedBookingIds as $deleteItem) {
                $id=is_array($deleteItem) ? (string)($deleteItem['id'] ?? '') : (string)$deleteItem;
                if ($id==='') continue;
                $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $server=$stmt->fetch();
                if (!$server) { $syncedBookingDeletionIds[]=$id; continue; }
                $decision=$deletionConflict('bookings',$id,$server,'updatedAt',is_array($deleteItem)?$deleteItem:['delete'=>true]);
                if ($decision==='server') { $syncedBookingDeletionIds[]=$id; continue; }
                if ($decision==='conflict') continue;
                $stmtLinkedTx=$pdo->prepare("SELECT COUNT(*) FROM transactions WHERE bookingId = ? OR (sourceEntity = 'booking' AND sourceEntityId = ?)");
                $stmtLinkedTx->execute([$id,$id]);
                if ((int)$stmtLinkedTx->fetchColumn()>0) {
                    $conflicts[]=['id'=>'bookings:delete:'.$id,'entityType'=>'bookings','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Booking memiliki transaksi keuangan dan tidak boleh dihapus permanen. Gunakan pembatalan atau checkout.'];
                    continue;
                }
                $relatedBlockers=bookingOperationalDeletionBlockers($pdo,$id);
                if($relatedBlockers){
                    $conflicts[]=['id'=>'bookings:delete:'.$id,'entityType'=>'bookings','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Booking mempunyai '.implode(', ',$relatedBlockers).'. Hapus hanya melalui Pemeliharaan Data agar relasi dan tombstone diproses aman.'];
                    continue;
                }
                $roomNumber=(string)($server['roomNumber'] ?? '');
                $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$id]);
                if ($roomNumber!=='') {
                    $stmt=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber = ? AND status = 'active'"); $stmt->execute([$roomNumber]);
                    if ((int)$stmt->fetchColumn()===0) {
                        tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'offline-sync-delete');
                    }
                }
                insertSyncTombstones($pdo,[['type'=>'bookings','id'=>$id]],'offline_delete_'.substr(hash('sha256',$id),0,40),(string)$loggedInStaff['id']);
                $syncedBookingDeletionIds[]=$id; $syncCount++;
            }

            foreach ($deletedMaintenanceIds as $deleteItem) {
                $id=is_array($deleteItem) ? (string)($deleteItem['id'] ?? '') : (string)$deleteItem;
                if ($id==='') continue;
                $stmt=$pdo->prepare("SELECT * FROM inventory_maintenance WHERE id = ? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $server=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$server) {
                    insertSyncTombstones($pdo,[['type'=>'inventory_maintenance','id'=>$id]],'offline_maintenance_delete_'.substr(hash('sha256',$id),0,40),(string)$loggedInStaff['id']);
                    $syncedMaintenanceDeletionIds[]=$id; continue;
                }
                $decision=$deletionConflict('inventory_maintenance',$id,$server,'updated_at',is_array($deleteItem)?$deleteItem:['delete'=>true]);
                if ($decision==='server') { $syncedMaintenanceDeletionIds[]=$id; continue; }
                if ($decision==='conflict') continue;
                $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId=? FOR UPDATE");$txStmt->execute([$id]);$transactionRows=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                if($transactionRows){
                    $conflicts[]=['id'=>'inventory_maintenance:delete:'.$id,'entityType'=>'inventory_maintenance','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Pemeliharaan sudah menghasilkan transaksi keuangan. Jangan hapus transaksi lewat sync; koreksi/batalkan melalui workflow keuangan agar jurnal tetap utuh.'];
                    continue;
                }
                $pdo->prepare("DELETE FROM inventory_maintenance WHERE id = ?")->execute([$id]);
                $tombstones=[['type'=>'inventory_maintenance','id'=>$id]];
                insertSyncTombstones($pdo,$tombstones,'offline_maintenance_delete_'.substr(hash('sha256',$id),0,40),(string)$loggedInStaff['id']);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus draft pemeliharaan dari antrean offline','inventory_maintenance',$id,['maintenance'=>$server],['deleted'=>true,'tombstones'=>1],'offline-sync-delete');
                $syncedMaintenanceDeletionIds[]=$id; $syncCount++;
            }

            foreach ($deletedInventoryIds as $deleteItem) {
                $id=is_array($deleteItem) ? (string)($deleteItem['id'] ?? '') : (string)$deleteItem;
                if ($id==='') continue;
                $stmt=$pdo->prepare("SELECT * FROM inventory WHERE id = ? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $server=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$server) {
                    insertSyncTombstones($pdo,[['type'=>'inventory','id'=>$id]],'offline_inventory_delete_'.substr(hash('sha256',$id),0,40),(string)$loggedInStaff['id']);
                    $syncedInventoryDeletionIds[]=$id; continue;
                }
                $decision=$deletionConflict('inventory',$id,$server,'updated_at',is_array($deleteItem)?$deleteItem:['delete'=>true]);
                if ($decision==='server') { $syncedInventoryDeletionIds[]=$id; continue; }
                if ($decision==='conflict') continue;
                $maintenanceStmt=$pdo->prepare("SELECT * FROM inventory_maintenance WHERE inventory_id=? FOR UPDATE");$maintenanceStmt->execute([$id]);$maintenanceRows=$maintenanceStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                $maintenanceIds=array_values(array_filter(array_map(static fn($row)=>(string)($row['id']??''),$maintenanceRows)));
                $transactionRows=[];
                if($maintenanceIds){
                    $ph=implode(',',array_fill(0,count($maintenanceIds),'?'));
                    $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId IN ($ph) FOR UPDATE");$txStmt->execute($maintenanceIds);$transactionRows=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                }
                if($transactionRows){
                    $conflicts[]=['id'=>'inventory:delete:'.$id,'entityType'=>'inventory','entityId'=>$id,'localData'=>['delete'=>true],'serverData'=>$server,'message'=>'Inventaris mempunyai pemeliharaan yang sudah menghasilkan transaksi keuangan. Jangan hapus lewat sync; gunakan koreksi workflow agar jurnal dan histori aset tetap utuh.'];
                    continue;
                }
                $pdo->prepare("DELETE FROM inventory_maintenance WHERE inventory_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM inventory WHERE id = ?")->execute([$id]);
                $tombstones=[['type'=>'inventory','id'=>$id]];
                foreach($maintenanceIds as $maintenanceId)$tombstones[]=['type'=>'inventory_maintenance','id'=>$maintenanceId];
                insertSyncTombstones($pdo,$tombstones,'offline_inventory_delete_'.substr(hash('sha256',$id),0,40),(string)$loggedInStaff['id']);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus inventaris tanpa transaksi keuangan dari antrean offline','inventory',$id,['asset'=>$server,'maintenance'=>$maintenanceRows],['deleted'=>true,'tombstones'=>count($tombstones)],'offline-sync-delete');
                $syncedInventoryDeletionIds[]=$id; $syncCount++;
            }

            // A sync payload may contain related room, booking and financial changes.
            // Never commit only a subset when one entity conflicts; otherwise the ledger
            // and booking state can diverge. Resolve conflicts, then retry the whole batch.
            if (count($conflicts) > 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode([
                    'success'=>false,
                    'message'=>'Sinkronisasi dibatalkan tanpa perubahan karena terdapat konflik.',
                    'conflicts'=>$conflicts,
                    'syncedRoomIds'=>[], 'syncedBookingIds'=>[], 'syncedTransactionIds'=>[],
                    'syncedBookingActionOperationIds'=>[], 'syncedLifecycleOperationIds'=>[], 'syncedInventoryIds'=>[], 'syncedMaintenanceIds'=>[],
                    'syncedDeletionIds'=>[], 'syncedRoomDeletionIds'=>[], 'syncedBookingDeletionIds'=>[],
                    'syncedInventoryDeletionIds'=>[], 'syncedMaintenanceDeletionIds'=>[],
                    'tombstones'=>$tombstonesSince,
                    'db'=>getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
                break;
            }

            if ($syncCount>0) {
                $notifId=generateServerId('n_sync');
                $stmt=$pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'system')");
                $stmt->execute([$notifId,"Sinkronisasi offline oleh ".currentStaffLabel($loggedInStaff).": {$syncCount} perubahan diterapkan.",date('Y-m-d H:i:s')]);
                bumpServerRevision($pdo);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerapkan batch sinkronisasi offline','sync_batch',(string)($GLOBALS['tamasya_request_operation_id']??''),null,[
                    'changeCount'=>$syncCount,
                    'rooms'=>count($syncedRoomIds),'bookings'=>count($syncedBookingIds),'transactions'=>count($syncedTransactionIds),
                    'inventory'=>count($syncedInventoryIds),'maintenance'=>count($syncedMaintenanceIds),
                    'deletions'=>count($syncedDeletionIds)+count($syncedRoomDeletionIds)+count($syncedBookingDeletionIds)+count($syncedInventoryDeletionIds)+count($syncedMaintenanceDeletionIds)
                ],'offline-sync');
            }
            tamasyaFinancialCommit($pdo);
            if ($syncCount>0) broadcastTelegramNotification($pdo,"🔄 *SINKRONISASI OFFLINE*\n\n{$syncCount} perubahan dari ".currentStaffLabel($loggedInStaff)." telah diterapkan ke database server.",true);
            echo json_encode([
                'success'=>count($conflicts)===0,
                'message'=>count($conflicts) ? 'Sebagian data memerlukan penyelesaian konflik.' : 'Sinkronisasi berhasil.',
                'conflicts'=>$conflicts,
                'syncedRoomIds'=>$syncedRoomIds,
                'syncedBookingIds'=>array_values(array_unique($syncedBookingIds)),
                'syncedTransactionIds'=>array_values(array_unique($syncedTransactionIds)),
                'syncedBookingActionOperationIds'=>array_values(array_unique($syncedBookingActionOperationIds)),
                'syncedLifecycleOperationIds'=>array_values(array_unique($syncedLifecycleOperationIds)),
                'syncedInventoryIds'=>$syncedInventoryIds,
                'syncedMaintenanceIds'=>$syncedMaintenanceIds,
                'syncedDeletionIds'=>$syncedDeletionIds,
                'syncedRoomDeletionIds'=>$syncedRoomDeletionIds,
                'syncedBookingDeletionIds'=>$syncedBookingDeletionIds,
                'syncedInventoryDeletionIds'=>$syncedInventoryDeletionIds,
                'syncedMaintenanceDeletionIds'=>$syncedMaintenanceDeletionIds,
                'tombstones'=>$tombstonesSince,
                'db'=>getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Sinkronisasi gagal', $e),'conflicts'=>$conflicts]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/telegram-bot ATAU api.php?action=telegram-bot
    // Simulasi Pesan Masuk Bot Telegram & Log (Hybrid Real-time)
    // ----------------------------------------------------------------
}
