<?php
/**
 * TAMASYA FIX23 — Core financial posting authority.
 *
 * One transaction table may legitimately receive events from many domains,
 * but every live write must cross one server-side contract.  This file owns
 * that contract.  Domain modules keep their business calculations; they do
 * not own the persistence grammar of `transactions`.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaFinancialPostingAuthorityVersion(): string { return 'FIX23-core-authority-2'; }

function tamasyaFinancialPostingAuthorityDefinitions(): array {
    return [
        'finance_manual'=>[
            'label'=>'Finance / Log Kas',
            'kinds'=>['manual','internal_transfer','opening_balance_cash','opening_balance_bank','pbjt_payment','pbjt_settlement','pph_payment','income_tax_payment','corporate_income_tax_payment'],
        ],
        'booking'=>[
            'label'=>'Booking / Front Office',
            'kinds'=>['booking_payment','booking_charge','down_payment','settlement','refund'],
            'sourceEntities'=>['booking','booking_refund'],
        ],
        'pos'=>[
            'label'=>'POS / Minibar',
            'kinds'=>['pos_sale','pos_refund','pos_cogs','pos_cogs_reversal','pos_room_charge'],
            'sourceEntities'=>['pos_sale'],
        ],
        'payroll'=>[
            'label'=>'Payroll',
            'kinds'=>['salary_payment','salary_reversal'],
            'sourceEntities'=>['salary_slip'],
        ],
        'maintenance'=>[
            'label'=>'Maintenance',
            'kinds'=>['maintenance_cost'],
            'sourceEntities'=>['maintenance_ticket','inventory_maintenance'],
        ],
        'ota'=>[
            'label'=>'OTA Receivable / Settlement',
            // ota_commission settles the channel deduction against the receivable. It is
            // not cash movement, so it is booked separately from the transfer pair.
            'kinds'=>['ota_transfer','ota_commission'],
            'sourceEntities'=>['ota_disbursement'],
        ],
        'guest_deposit'=>[
            'label'=>'Guest Security Deposit',
            'kinds'=>['security_deposit_received','security_deposit_refund','security_deposit_forfeit'],
            'sourceEntities'=>['guest_security_deposit'],
        ],
        // Growth remains feature-gated.  These contracts exist only so a future
        // reopen cannot invent a second posting grammar.
        'growth_procurement'=>[
            'label'=>'Growth Procurement (locked)',
            'kinds'=>['supplier_invoice_accrual','supplier_inventory_accrual','supplier_asset_accrual','supplier_ap_payment'],
            'sourceEntities'=>['growth_supplier_invoice'],
        ],
    ];
}

function tamasyaFinancialPostingOwnerForKind(string $kind): ?string {
    $kind=strtolower(trim($kind));
    foreach(tamasyaFinancialPostingAuthorityDefinitions() as $owner=>$definition){
        if(in_array($kind,$definition['kinds'],true))return $owner;
    }
    return null;
}

function tamasyaFinancialPostingAuthorityProjection(): array {
    $out=[];
    foreach(tamasyaFinancialPostingAuthorityDefinitions() as $owner=>$definition){
        foreach($definition['kinds'] as $kind)$out[$kind]=['owner'=>$owner,'label'=>$definition['label']];
    }
    ksort($out);
    return ['version'=>tamasyaFinancialPostingAuthorityVersion(),'kinds'=>$out];
}

function tamasyaAssertFinancialPostingAuthority(array $tx,string $declaredAuthority,array $options=[]): void {
    $kind=strtolower(trim((string)($tx['transactionKind']??'')));
    if($kind==='')throw new InvalidArgumentException('transactionKind wajib pada canonical financial posting.');
    $owner=tamasyaFinancialPostingOwnerForKind($kind);
    if($owner===null)throw new DomainException('transactionKind tidak terdaftar pada financial posting authority: '.$kind);
    if($owner!==$declaredAuthority){
        throw new DomainException('Domain '.$declaredAuthority.' tidak berhak mem-posting transactionKind '.$kind.'; authority adalah '.$owner.'.');
    }
    if($owner==='growth_procurement'){
        if(!function_exists('tamasyaGrowthModuleEnabled') || !tamasyaGrowthModuleEnabled('procurement')){
            throw new DomainException('Growth Procurement tetap terkunci; financial posting ditolak sampai gate Growth dibuka secara eksplisit.');
        }
    }
    $definition=tamasyaFinancialPostingAuthorityDefinitions()[$owner]??[];
    $sourceEntity=trim((string)($tx['sourceEntity']??''));
    $sourceEntityId=trim((string)($tx['sourceEntityId']??''));
    if(($sourceEntity==='')!==($sourceEntityId==='')){
        throw new InvalidArgumentException('sourceEntity dan sourceEntityId canonical wajib diisi bersama-sama.');
    }
    $allowedSourceEntities=$definition['sourceEntities']??[];
    if($allowedSourceEntities){
        if($sourceEntity==='' || !in_array($sourceEntity,$allowedSourceEntities,true)){
            throw new DomainException('Sumber transaksi '.$kind.' tidak sesuai authority '.$owner.'.');
        }
    }

    $type=strtolower(trim((string)($tx['type']??'')));
    if(!in_array($type,['income','expense'],true))throw new InvalidArgumentException('Tipe transaksi canonical harus income atau expense.');
    $amount=round((float)($tx['amount']??0),2);
    if(!is_finite($amount)||$amount<=0||$amount>999999999999.99)throw new InvalidArgumentException('Nominal canonical financial posting tidak valid.');
    $date=trim((string)($tx['date']??''));
    if($date===''||!validIsoDate($date))throw new InvalidArgumentException('Tanggal canonical financial posting tidak valid.');
    if(trim((string)($tx['description']??''))==='')throw new InvalidArgumentException('Deskripsi canonical financial posting wajib diisi.');

    $origin=strtolower(trim((string)($tx['recordOrigin']??'live_operation')));
    if(!in_array($origin,['live_operation','historical_import'],true))throw new InvalidArgumentException('recordOrigin canonical financial posting tidak valid.');
    if($origin==='live_operation' && trim((string)($tx['operationId']??''))===''){
        throw new InvalidArgumentException('Live financial posting wajib mempunyai operationId untuk idempotency.');
    }

    // System-owned semantic roles are immutable contracts.  The display name
    // may change, but a caller is not allowed to combine a known system key
    // with a transaction type that contradicts its definition.
    $systemKey=tamasyaNormalizeCatalogKey($tx['categorySystemKey']??'');
    if($systemKey!==''){
        $definition=tamasyaFinanceSemanticRoleDefinition($systemKey);
        if($definition && (string)$definition['type']!==$type){
            if(empty($options['allowSemanticTypeMismatchForReversal'])){
                throw new DomainException('Semantic role '.$systemKey.' tidak sesuai tipe transaksi '.$type.'.');
            }
        }
    }
}

/**
 * Business-level identity for manual/backfill replay.
 *
 * operationId is the transport/idempotency key, while this snapshot proves that
 * a retry still represents the exact same financial intent.  Tax snapshots and
 * generated document numbers are intentionally excluded: they are server-owned
 * results of the first successful posting and must not make a legitimate retry
 * execute again after configuration changes.
 */
function tamasyaManualTransactionReplayIdentity(array $tx): array {
    $money=static fn($value): string => number_format(round((float)($value??0),2),2,'.','');
    $nullable=static function($value): ?string {
        $value=trim((string)($value??''));
        return $value===''?null:$value;
    };
    $lowerNullable=static function($value) use ($nullable): ?string {
        $value=$nullable($value);
        return $value===null?null:strtolower($value);
    };
    $split=!empty($tx['isSplitPayment']) || (round((float)($tx['splitCashAmount']??0),2)>0 && round((float)($tx['splitTransferAmount']??0),2)>0);
    $categoryId=$nullable($tx['categoryId']??null);
    $categorySystemKey=tamasyaNormalizeCatalogKey($tx['categorySystemKey']??'');
    $subcategoryId=$nullable($tx['subcategoryId']??null);
    $subcategorySystemKey=tamasyaNormalizeCatalogKey($tx['subcategorySystemKey']??'');
    return [
        'type'=>strtolower(trim((string)($tx['type']??''))),
        'categoryId'=>$categoryId,
        'categorySystemKey'=>$categorySystemKey,
        // Display labels are mutable property master-data.  Once a stable ID or
        // semantic key exists, a later rename must not turn a network retry into
        // a false financial conflict.  Label-only legacy rows remain strict.
        'category'=>$categoryId===null && $categorySystemKey===''?strtolower(trim((string)($tx['category']??''))):null,
        'subcategoryId'=>$subcategoryId,
        'subcategorySystemKey'=>$subcategorySystemKey,
        'subcategory'=>$subcategoryId===null && $subcategorySystemKey===''?$lowerNullable($tx['subcategory']??null):null,
        'amount'=>$money($tx['amount']??0),
        'date'=>trim((string)($tx['date']??'')),
        'description'=>trim((string)($tx['description']??'')),
        'proofHash'=>trim((string)($tx['proofUrl']??''))!==''?hash('sha256',(string)$tx['proofUrl']):null,
        'roomNumber'=>$nullable($tx['roomNumber']??null),
        'bankAccountId'=>$lowerNullable($tx['bankAccountId']??null),
        'bookingId'=>$nullable($tx['bookingId']??null),
        'bookingSource'=>$lowerNullable($tx['bookingSource']??null),
        'transactionKind'=>strtolower(trim((string)($tx['transactionKind']??'manual'))),
        'recordOrigin'=>strtolower(trim((string)($tx['recordOrigin']??'live_operation'))),
        'sourceEntity'=>$lowerNullable($tx['sourceEntity']??null),
        'sourceEntityId'=>$nullable($tx['sourceEntityId']??null),
        'isSplitPayment'=>$split?1:0,
        'splitCashAmount'=>$split?$money($tx['splitCashAmount']??0):'0.00',
        'splitTransferAmount'=>$split?$money($tx['splitTransferAmount']??0):'0.00',
        'splitTransferBankAccountId'=>$split?$lowerNullable($tx['splitTransferBankAccountId']??null):null,
        'serviceDate'=>$nullable($tx['serviceDate']??null),
        'historicalSourceType'=>$lowerNullable($tx['historicalSourceType']??null),
        'historicalSourceReference'=>$nullable($tx['historicalSourceReference']??null),
        'sourceReportedBy'=>$nullable($tx['sourceReportedBy']??null),
        'importBatchId'=>$nullable($tx['importBatchId']??null),
        'shiftExemptionReason'=>$nullable($tx['shiftExemptionReason']??null),
        'periodCorrectionReason'=>$nullable($tx['periodCorrectionReason']??null),
    ];
}

function tamasyaManualTransactionReplayHash(array $tx): string {
    $json=json_encode(tamasyaManualTransactionReplayIdentity($tx),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($json===false)throw new RuntimeException('Identitas replay transaksi manual tidak dapat dinormalisasi.');
    return hash('sha256',$json);
}

function tamasyaAssertManualTransactionReplayMatches(array $existing,array $incoming): void {
    if(!hash_equals(tamasyaManualTransactionReplayHash($existing),tamasyaManualTransactionReplayHash($incoming))){
        throw new DomainException('Operation ID sudah dipakai untuk transaksi dengan detail finansial berbeda.');
    }
}

function tamasyaCanonicalTransactionColumns(): array {
    return [
        'id','type','category','categoryId','categorySystemKey','amount','date','description','createdBy','createdAt',
        'subcategory','subcategoryId','subcategorySystemKey','roomNumber','proofUrl','bankAccountId','bookingId','baseAmount','currency','exchangeRate','propertyAmount','taxAmount','taxRate',
        'bookingSource','version','updatedAt','updatedBy','updatedSource','transactionKind','sourceEntity','sourceEntityId','isSystemGenerated','operationId',
        'shiftSessionId','lockedAt','reconciliationStatus','reconciliationReference','documentNumber','importBatchId','recordOrigin','shiftExempt','shiftExemptionReason',
        'taxNote','taxRuleId','taxRuleName','taxSnapshotStatus','taxSource','serviceDate','historicalSourceType','historicalSourceReference','sourceReportedBy','reportingPeriod',
        'periodStatusAtEntry','periodImpactStatus','requiresTaxAmendment','historicalReviewStatus','historicalReviewedBy','historicalReviewedAt','periodCorrectionReason','inputDelayDays',
        'isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId'
    ];
}

/**
 * Physical cash can never go below zero inside an open shift.
 *
 * The close-shift workflow already refuses negative actual cash. Enforce the
 * same invariant at the canonical posting boundary so salary, maintenance,
 * refunds, and any future cash expense cannot create a shift that is impossible
 * to reconcile later. The shift row is locked so concurrent cash expenses are
 * serialized against one another and against shift-close.
 */
function tamasyaCanonicalCashLegAmount(array $tx): float {
    $amount=max(0.0,round((float)($tx['amount']??0),2));
    if((int)($tx['isSplitPayment']??0)===1){
        $cash=max(0.0,round((float)($tx['splitCashAmount']??0),2));
        $transfer=max(0.0,round((float)($tx['splitTransferAmount']??0),2));
        if(abs(($cash+$transfer)-$amount)>0.01) throw new DomainException('Nominal split payment tidak sesuai total transaksi.');
        return $cash;
    }
    $account=strtolower(trim((string)($tx['bankAccountId']??'')));
    return ($account===''||$account==='cash')?$amount:0.0;
}

function tamasyaPostingCashIsExempt(array $tx): bool {
    return !empty($tx['shiftExempt'])
        || strtolower(trim((string)($tx['recordOrigin']??'live_operation')))==='historical_import';
}

function tamasyaOpenShiftCashState(PDO $pdo,string $shiftId,?string $excludeTransactionId=null): array {
    $shiftStmt=$pdo->prepare("SELECT id,status,opening_cash FROM shift_sessions WHERE id=? LIMIT 1 FOR UPDATE");
    $shiftStmt->execute([$shiftId]);
    $shift=$shiftStmt->fetch(PDO::FETCH_ASSOC);
    if(!$shift)return ['shift'=>null,'available'=>0.0];

    $sql="SELECT
        COALESCE(SUM(CASE WHEN type='income' AND COALESCE(transactionKind,'manual')<>'security_deposit_forfeit' THEN
            CASE
                WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0
                     AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01
                    THEN COALESCE(splitCashAmount,0)
                WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount
                ELSE 0
            END ELSE 0 END),0) AS cash_income,
        COALESCE(SUM(CASE WHEN type='expense' THEN
            CASE
                WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0
                     AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01
                    THEN COALESCE(splitCashAmount,0)
                WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount
                ELSE 0
            END ELSE 0 END),0) AS cash_expense
        FROM transactions WHERE shiftSessionId=?";
    $params=[$shiftId];
    if($excludeTransactionId!==null && trim($excludeTransactionId)!==''){
        $sql.=" AND id<>?";
        $params[]=trim($excludeTransactionId);
    }
    $totalsStmt=$pdo->prepare($sql);
    $totalsStmt->execute($params);
    $totals=$totalsStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $available=round((float)($shift['opening_cash']??0)+(float)($totals['cash_income']??0)-(float)($totals['cash_expense']??0),2);
    return ['shift'=>$shift,'available'=>$available];
}

function tamasyaAssertOpenShiftCashCanCoverExpenseExcluding(PDO $pdo,array $tx,?string $excludeTransactionId=null): void {
    if(strtolower(trim((string)($tx['type']??'')))!=='expense')return;
    $cashOut=tamasyaCanonicalCashLegAmount($tx);
    if($cashOut<=0)return;
    $shiftId=trim((string)($tx['shiftSessionId']??''));
    if($shiftId===''){
        if(tamasyaPostingCashIsExempt($tx))return;
        throw new DomainException('Pengeluaran tunai wajib terhubung ke shift kas aktif. Buka shift kas atau gunakan transfer/QRIS.');
    }

    $state=tamasyaOpenShiftCashState($pdo,$shiftId,$excludeTransactionId);
    $shift=$state['shift'];
    if(!$shift||strtolower(trim((string)($shift['status']??'')))!=='open'){
        throw new DomainException('Pengeluaran tunai ditolak karena shift kas tidak ditemukan atau sudah ditutup.');
    }
    $available=(float)$state['available'];
    if($cashOut>$available+0.009){
        throw new DomainException(
            'Kas shift tidak mencukupi untuk pengeluaran tunai. Tersedia Rp '.number_format(max(0,$available),0,',','.').
            ', dibutuhkan Rp '.number_format($cashOut,0,',','.').'. Gunakan transfer/QRIS atau tambahkan kas melalui workflow resmi.'
        );
    }
}

function tamasyaAssertOpenShiftCashCanCoverExpense(PDO $pdo,array $tx): void {
    tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo,$tx,null);
}

function tamasyaAssertOpenShiftCashProjection(PDO $pdo,array $tx,?string $excludeTransactionId=null): void {
    $type=strtolower(trim((string)($tx['type']??'')));
    if(!in_array($type,['income','expense'],true))return;
    $cashLeg=tamasyaCanonicalCashLegAmount($tx);
    if($cashLeg<=0 && ($excludeTransactionId===null || trim($excludeTransactionId)===''))return;
    $shiftId=trim((string)($tx['shiftSessionId']??''));
    if($shiftId==='' && $cashLeg<=0)return;
    if($shiftId===''){
        if(tamasyaPostingCashIsExempt($tx))return;
        throw new DomainException('Transaksi tunai live wajib terhubung ke shift kas aktif. Buka shift kas atau gunakan transfer/QRIS.');
    }

    $state=tamasyaOpenShiftCashState($pdo,$shiftId,$excludeTransactionId);
    $shift=$state['shift'];
    if(!$shift||strtolower(trim((string)($shift['status']??'')))!=='open'){
        throw new DomainException('Transaksi tunai ditolak karena shift kas tidak ditemukan atau sudah ditutup.');
    }
    $available=(float)$state['available'];
    $projected=round($available+($type==='income'?$cashLeg:-$cashLeg),2);
    if($projected < -0.009){
        throw new DomainException(
            'Perubahan transaksi tunai akan membuat saldo kas shift negatif. Tersedia Rp '.number_format(max(0,$available),0,',','.').
            ', dampak kas baru Rp '.number_format($cashLeg,0,',','.').'. Gunakan transfer/QRIS atau sesuaikan nominal.'
        );
    }
}

/**
 * Canonical single insert grammar for `transactions`.
 *
 * Business modules still calculate their own amount/tax/reference semantics.
 * This helper owns persistence validation, domain ownership, semantic snapshot
 * normalization, safe column whitelisting and idempotent insert behavior.
 */
function tamasyaPostFinancialTransaction(PDO $pdo,array $tx,array $actor,string $authority,array $options=[]): string {
    if(!$pdo->inTransaction())throw new RuntimeException('Canonical financial posting wajib berada dalam transaksi database aktif.');

    $tx['id']=trim((string)($tx['id']??'')) ?: generateServerId('tx');
    $tx['type']=strtolower(trim((string)($tx['type']??'')));
    $tx['date']=trim((string)($tx['date']??date('Y-m-d')));
    $tx['recordOrigin']=strtolower(trim((string)($tx['recordOrigin']??'live_operation'))) ?: 'live_operation';
    $tx['version']=max(1,(int)($tx['version']??1));
    $tx['updatedBy']=$tx['updatedBy']??($actor['id']??null);
    $tx['updatedSource']=trim((string)($tx['updatedSource']??($options['source']??'web'))) ?: 'web';
    $tx['createdBy']=trim((string)($tx['createdBy']??''));
    if($tx['createdBy']==='')$tx['createdBy']=function_exists('currentStaffLabel')?currentStaffLabel($actor):trim((string)($actor['id']??'staff'));
    $tx['isSystemGenerated']=isset($tx['isSystemGenerated'])?(int)!empty($tx['isSystemGenerated']):($authority==='finance_manual'?0:1);
    $tx['shiftExempt']=isset($tx['shiftExempt'])?(int)!empty($tx['shiftExempt']):0;
    $tx['amount']=round((float)($tx['amount']??0),2);
    // Split-payment snapshot columns mirror the booking/checkout canonical fields.
    // Non-split rows always store a clean zero/null triple so reporting never has
    // to guess whether an old row simply predates the split feature.
    // The split parts are canonical evidence on their own: a payload that carries
    // splitCashAmount/splitTransferAmount is a split posting even when the flag
    // is missing (manual backfill), the transfer leg inherits the caller's
    // bankAccountId when no dedicated split account was sent, and the total is
    // derived from the parts so a split posting never dies on nominal 0.
    $tx['splitCashAmount']=round((float)($tx['splitCashAmount']??0),2);
    $tx['splitTransferAmount']=round((float)($tx['splitTransferAmount']??0),2);
    $tx['splitTransferBankAccountId']=trim((string)($tx['splitTransferBankAccountId']??'')) ?: null;
    $tx['isSplitPayment']=(int)!empty($tx['isSplitPayment']);
    if($tx['isSplitPayment']===0 && ($tx['splitCashAmount']>0 || $tx['splitTransferAmount']>0)){
        $tx['isSplitPayment']=1;
    }
    if($tx['isSplitPayment']===1){
        if($tx['splitTransferBankAccountId']===null){
            $declaredBankAccount=trim((string)($tx['bankAccountId']??''));
            if($declaredBankAccount!=='')$tx['splitTransferBankAccountId']=$declaredBankAccount;
        }
        $controlledSplitExpenseReversal=$tx['type']==='expense'
            && !empty($options['allowSplitExpenseReversal'])
            && (
                strtolower(trim((string)($tx['updatedSource']??'')))==='audit-correction'
                || ($authority==='booking' && strtolower(trim((string)($tx['transactionKind']??'')))==='refund')
            );
        if(($tx['type']!=='income' && !$controlledSplitExpenseReversal) || $tx['splitCashAmount']<=0 || $tx['splitTransferAmount']<=0 || $tx['splitTransferBankAccountId']===null){
            throw new InvalidArgumentException('Pembayaran split canonical wajib mempunyai bagian tunai, bagian transfer, serta akun transfer/QRIS; split pengeluaran hanya diizinkan untuk pembalik Koreksi Audit yang terkontrol.');
        }
        if(strtolower((string)$tx['splitTransferBankAccountId'])==='ota_receivable'){
            throw new InvalidArgumentException('Piutang OTA bukan rekening settlement untuk bagian transfer pembayaran split.');
        }
        // Canonical one-row split semantics: bankAccountId represents the whole
        // transaction only for non-split rows. The split transfer account lives
        // exclusively in splitTransferBankAccountId.
        $tx['bankAccountId']=null;
        if($tx['amount']<=0 && ($tx['splitCashAmount']+$tx['splitTransferAmount'])>0){
            $tx['amount']=round($tx['splitCashAmount']+$tx['splitTransferAmount'],2);
        }
        if(!moneyMatches($tx['splitCashAmount']+$tx['splitTransferAmount'],$tx['amount'],0.01)){
            throw new InvalidArgumentException('Pembayaran split wajib sama dengan nominal transaksi (tunai + transfer).');
        }
    }else{
        $tx['splitCashAmount']=0;
        $tx['splitTransferAmount']=0;
        $tx['splitTransferBankAccountId']=null;
    }

    tamasyaAssertFinancialPostingAuthority($tx,$authority,$options);

    $systemKey=tamasyaNormalizeCatalogKey($tx['categorySystemKey']??'');
    $semanticDefinition=$systemKey!==''?tamasyaFinanceSemanticRoleDefinition($systemKey):null;
    $semanticTypeMismatch=$semanticDefinition && (string)$semanticDefinition['type']!==$tx['type'];
    if($systemKey!=='' && $semanticDefinition && !($semanticTypeMismatch && !empty($options['allowSemanticTypeMismatchForReversal']))){
        $category=tamasyaRequireSystemFinanceCategory($pdo,$systemKey,$tx['type'],!empty($options['lockCatalog']));
        $tx['categoryId']=(string)$category['id'];
        $tx['category']=(string)$category['name'];
        $tx['categorySystemKey']=$systemKey;
    }elseif(!empty($options['requireCatalog']) && !$semanticTypeMismatch){
        $catalog=tamasyaResolveFinanceCatalogSelection($pdo,$tx,!empty($options['lockCatalog']),true);
        $tx['categoryId']=$catalog['categoryId'];
        $tx['category']=$catalog['categoryName'];
        $tx['categorySystemKey']=$catalog['categorySystemKey']!==''?$catalog['categorySystemKey']:null;
        $tx['subcategoryId']=$catalog['subcategoryId'];
        $tx['subcategory']=$catalog['subcategoryName'];
        $tx['subcategorySystemKey']=$catalog['subcategorySystemKey']!==''?$catalog['subcategorySystemKey']:null;
    }

    if(trim((string)($tx['category']??''))==='')throw new InvalidArgumentException('Kategori canonical financial posting wajib ter-resolve.');
    if(strlen((string)$tx['id'])>50)throw new InvalidArgumentException('ID transaksi melebihi batas schema.');
    if(strlen((string)($tx['operationId']??''))>100)throw new InvalidArgumentException('operationId transaksi melebihi batas schema.');

    if(!empty($options['idempotentByOperationId'])){
        $operationId=trim((string)($tx['operationId']??''));
        if($operationId==='')throw new InvalidArgumentException('idempotentByOperationId membutuhkan operationId.');
        $existingStmt=$pdo->prepare('SELECT * FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE');
        $existingStmt->execute([$operationId]);
        $existing=$existingStmt->fetch(PDO::FETCH_ASSOC)?:null;
        if($existing){
            // An operation ID is a financial idempotency key, not merely a duplicate
            // suppression token.  A retry is accepted only when its complete economic
            // identity still matches the row already committed by the first attempt.
            tamasyaAssertManualTransactionReplayMatches($existing,$tx);
            return (string)$existing['id'];
        }
    }

    tamasyaAssertOpenShiftCashCanCoverExpense($pdo,$tx);

    // Mata uang transaksi. Bila pemanggil tidak menyebut mata uang, dipakai
    // mata uang property. Bila pemanggil menyebut mata uang lain, kurs harus
    // diketahui: dari nilai yang dikirim pemanggil, atau dari exchange_rates
    // pada tanggal transaksi. Tanpa kurs, transaksi ditolak: nominal dalam
    // mata uang asing tidak boleh dicatat seolah-olah sudah mata uang
    // property.
    $propertyCurrency=function_exists('tamasyaPropertyCurrency')?tamasyaPropertyCurrency($pdo):'IDR';
    if (!array_key_exists('currency',$tx) || trim((string)($tx['currency']??''))==='') {
        $tx['currency']=$propertyCurrency;
    }
    $transactionCurrency=strtoupper(trim((string)$tx['currency']));
    $tx['currency']=$transactionCurrency;
    $transactionDate=(string)($tx['transactionDate']??$tx['date']??date('Y-m-d'));
    if($transactionCurrency===$propertyCurrency){
        $tx['exchangeRate']=1;
        $tx['propertyAmount']=round((float)($tx['amount']??0),2);
    }else{
        $rate=null;
        if(array_key_exists('exchangeRate',$tx)&&is_numeric($tx['exchangeRate']??null)&&(float)$tx['exchangeRate']>0){
            $rate=(float)$tx['exchangeRate'];
        }else{
            $rate=function_exists('tamasyaTransactionExchangeRate')
                ?tamasyaTransactionExchangeRate($pdo,$propertyCurrency,$transactionCurrency,$transactionDate)
                :null;
        }
        if($rate===null){
            throw new RuntimeException('Kurs '.$transactionCurrency.' ke '.$propertyCurrency.' pada '.$transactionDate.' belum tersedia. Transaksi tidak disimpan; catat kurs terlebih dahulu.');
        }
        $tx['exchangeRate']=$rate;
        $tx['propertyAmount']=round((float)($tx['amount']??0)*$rate,2);
    }
    // baseAmount SENGAJA tidak diisi di sini: nilai NULL adalah tanda pajak belum
    // diketahui (taxSnapshotStatus unresolved). Mengisinya otomatis akan
    // menghilangkan pembeda antara "pajak nol" dan "pajak belum diketahui"
    // pada laporan kanonik dan jurnal suspense.

    $allowed=array_flip(tamasyaCanonicalTransactionColumns());
    $columns=[];$values=[];
    foreach($tx as $column=>$value){
        if(!isset($allowed[$column]))continue;
        if($column==='createdAt'||$column==='updatedAt')continue; // database owns timestamps
        $columns[]=$column;
        $values[]=$value;
    }
    if(!$columns)throw new RuntimeException('Tidak ada kolom transaksi yang dapat diposting.');
    $quoted=array_map(static fn(string $column): string=>'`'.str_replace('`','',$column).'`',$columns);
    // Duplicate operation/id is never silently ignored here.  Each workflow must
    // prove idempotency against its own business reference before calling the
    // authority, otherwise the database unique key fails closed.
    $sql='INSERT INTO `transactions` ('.implode(',',$quoted).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
    $stmt=$pdo->prepare($sql);
    $stmt->execute($values);
    return (string)$tx['id'];
}
