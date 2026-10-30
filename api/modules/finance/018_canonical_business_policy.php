<?php
/**
 * TAMASYA V137 canonical business-policy registry.
 *
 * FINAL11 root hardening: business invariants that used to be repeated across
 * Booking, POS, Payroll, Security Deposit, Maintenance, and Enterprise/AP live
 * here. Legacy module helpers remain as compatibility wrappers, but they must
 * delegate to these functions instead of re-implementing the rules.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaBusinessPolicyVersion(): string { return 'FINAL12-root-policy-3'; }

function tamasyaBusinessPolicyProjection(): array {
    return [
        'version'=>tamasyaBusinessPolicyVersion(),
        'transactionKinds'=>tamasyaCanonicalTransactionKinds(),
        'taxRuleSources'=>tamasyaTaxRuleSourceCatalog(),
        'taxRuleKinds'=>tamasyaTaxRuleKindCatalog(),
        'posTaxProfileKinds'=>tamasyaPosTaxProfileKinds(),
        'paymentMethods'=>array_map(static fn($meta)=>['label'=>(string)($meta['label']??''),'accountType'=>$meta['accountType']??null,'pseudoAccount'=>$meta['pseudoAccount']??null],tamasyaPaymentMethodCatalog()),
        'physicalPaymentAccountTypes'=>tamasyaPhysicalPaymentAccountTypes(),
    ];
}

function tamasyaCanonicalTransactionKinds(): array {
    return [
        'manual','booking_payment','booking_charge','down_payment','settlement',
        'internal_transfer','salary_payment','salary_reversal','maintenance_cost',
        'refund','ota_transfer','security_deposit_received','security_deposit_refund',
        'security_deposit_forfeit','pos_sale','pos_refund','pos_cogs','pos_cogs_reversal',
        'pos_room_charge','supplier_invoice_accrual','supplier_inventory_accrual',
        'supplier_asset_accrual','supplier_ap_payment','opening_balance_cash',
        'opening_balance_bank','pbjt_payment','pbjt_settlement','pph_payment',
        'income_tax_payment','corporate_income_tax_payment'
    ];
}

function tamasyaProtectedTransactionKinds(): array {
    return array_values(array_diff(tamasyaCanonicalTransactionKinds(), ['manual']));
}

function tamasyaTaxRuleSourceCatalog(): array {
    return ['*','Direct','OTA','POS'];
}

function tamasyaTaxRuleKindCatalog(): array {
    return ['*','room','extension','extra','pos','food_beverage','retail','service','down_payment','settlement','manual'];
}

function tamasyaPosTaxProfileKinds(): array {
    return ['extra','food_beverage','retail','service'];
}

function tamasyaKnownOtaSourceAliases(): array {
    return [
        'ota','online travel agent','traveloka','booking.com','bookingcom','agoda','tiket.com','tiketcom',
        'expedia','expedia.com','hotels.com','hotelscom','trip.com','tripcom','airbnb','hostelworld',
        'klook','pegipegi','oyo','reddoorz'
    ];
}

/**
 * Classify a booking source as OTA only when the source itself is explicit.
 * Arbitrary free text (guest/staff names, notes, typos, custom labels) MUST NOT
 * silently become OTA. Custom channels remain supported with an explicit
 * "OTA: <channel>" / "OTA - <channel>" prefix.
 */
function tamasyaIsExplicitOtaSourceLabel(string $source): bool {
    $normalized=strtolower(trim($source));
    if($normalized==='')return false;
    $compact=preg_replace('/[^a-z0-9]+/','',$normalized) ?? '';
    foreach(tamasyaKnownOtaSourceAliases() as $alias){
        $aliasNormalized=strtolower(trim($alias));
        if($normalized===$aliasNormalized)return true;
        $aliasCompact=preg_replace('/[^a-z0-9]+/','',$aliasNormalized) ?? '';
        if($aliasCompact!=='' && $compact===$aliasCompact)return true;
    }
    return (bool)preg_match('/^(?:ota|online\s+travel\s+agent)\s*(?:[-:|\/]\s*.+)$/i',trim($source));
}

function tamasyaNormalizeTaxSource(string $source): string {
    $source=trim($source);
    if($source==='')return 'Direct';
    $normalized=strtolower($source);
    if(in_array($normalized,['pos','minibar','mini bar'],true))return 'POS';
    if(tamasyaIsExplicitOtaSourceLabel($source))return $source;
    if($normalized==='direct')return 'Direct';
    return $source;
}

function tamasyaTaxSourceClass(string $source): string {
    $normalized=strtolower(tamasyaNormalizeTaxSource($source));
    if($normalized==='pos')return 'pos';
    if(tamasyaIsExplicitOtaSourceLabel($source))return 'ota';
    return 'direct';
}

function tamasyaNormalizeTaxKind(string $kind): string {
    $kind=strtolower(trim($kind));
    return in_array($kind,tamasyaTaxRuleKindCatalog(),true)?$kind:'*';
}

function tamasyaPaymentMethodCatalog(): array {
    return [
        'cash'=>['accountType'=>null,'label'=>'Tunai'],
        'transfer'=>['accountType'=>'bank','label'=>'Transfer'],
        'qris'=>['accountType'=>'edc_qris','label'=>'QRIS'],
        'card'=>['accountType'=>'edc_card','label'=>'Kartu/EDC'],
        'room_charge'=>['pseudoAccount'=>'guest_receivable','label'=>'Charge Kamar'],
        'ota'=>['pseudoAccount'=>'ota_receivable','label'=>'Piutang OTA'],
        'payable'=>['pseudoAccount'=>'accounts_payable','label'=>'Hutang'],
    ];
}

function tamasyaPhysicalPaymentAccountTypes(): array {
    $types=[];
    foreach(tamasyaPaymentMethodCatalog() as $meta){
        $type=strtolower(trim((string)($meta['accountType']??'')));
        if($type!==''&&!in_array($type,$types,true))$types[]=$type;
    }
    return $types;
}

function tamasyaPaymentMethodForAccountType(string $accountType): ?string {
    $type=strtolower(trim($accountType));
    foreach(tamasyaPaymentMethodCatalog() as $method=>$meta){
        if(strtolower(trim((string)($meta['accountType']??'')))===$type)return (string)$method;
    }
    return null;
}

function tamasyaPaymentMethodLabel(string $method): string {
    $method=tamasyaNormalizePaymentMethod($method);
    return (string)(tamasyaPaymentMethodCatalog()[$method]['label']??($method!==''?strtoupper($method):'Tidak diketahui'));
}

function tamasyaNormalizePaymentMethod(string $method): string {
    $m=strtolower(trim($method));
    $aliases=[
        'tunai'=>'cash','cash'=>'cash',
        'bank'=>'transfer','transfer'=>'transfer','bank_transfer'=>'transfer',
        'qris'=>'qris','qr'=>'qris',
        'card'=>'card','edc'=>'card','credit_card'=>'card','debit_card'=>'card',
        'room'=>'room_charge','roomcharge'=>'room_charge','room_charge'=>'room_charge',
        'ota'=>'ota','ota_receivable'=>'ota',
        'payable'=>'payable','hutang'=>'payable','accounts_payable'=>'payable',
    ];
    return $aliases[$m]??$m;
}

/**
 * Resolve the canonical liquid/pseudo account for a payment method.
 *
 * Options:
 * - allowedMethods: explicit method allow-list (default cash/transfer/qris)
 * - lock: SELECT ... FOR UPDATE for financial mutation paths
 * - allowRoomCharge / allowOta / allowPayable / allowCard: enable pseudo/special methods
 * - context: human readable label used in validation errors
 */
function tamasyaResolvePaymentAccount(PDO $pdo,string $method,?string $bankAccountId=null,array $options=[]): ?string {
    $method=tamasyaNormalizePaymentMethod($method);
    $catalog=tamasyaPaymentMethodCatalog();
    $context=trim((string)($options['context']??'Pembayaran'))?:'Pembayaran';
    $allowed=$options['allowedMethods']??['cash','transfer','qris'];
    $allowed=array_map('tamasyaNormalizePaymentMethod',array_values((array)$allowed));
    if(!in_array($method,$allowed,true)){
        throw new InvalidArgumentException($context.' memakai metode pembayaran yang tidak diizinkan: '.($method?:'(kosong)').'.');
    }
    if(!isset($catalog[$method]))throw new InvalidArgumentException($context.' memakai metode pembayaran yang tidak dikenal.');

    if($method==='cash'){
        if(trim((string)$bankAccountId)!=='' && strtolower(trim((string)$bankAccountId))!=='cash'){
            throw new InvalidArgumentException($context.' tunai tidak boleh membawa rekening bank/QRIS/EDC.');
        }
        return null;
    }
    if($method==='room_charge')return 'guest_receivable';
    if($method==='ota')return 'ota_receivable';
    if($method==='payable')return 'accounts_payable';

    $id=trim((string)$bankAccountId);
    if($id==='')throw new InvalidArgumentException($context.' '.$catalog[$method]['label'].' wajib memilih akun pembayaran aktif.');
    $sql="SELECT id,type,isActive FROM bank_accounts WHERE id=? LIMIT 1".(!empty($options['lock'])?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || (int)($row['isActive']??0)!==1)throw new InvalidArgumentException($context.' memakai akun pembayaran yang tidak aktif atau tidak ditemukan.');
    $expected=(string)($catalog[$method]['accountType']??'');
    $actual=strtolower(trim((string)($row['type']??'')));
    if($expected==='' || $actual!==$expected){
        $expectedLabel=['bank'=>'rekening bank','edc_qris'=>'akun EDC/QRIS','edc_card'=>'akun EDC kartu'][$expected]??$expected;
        throw new InvalidArgumentException($context.' metode '.$catalog[$method]['label'].' wajib memakai '.$expectedLabel.'.');
    }
    return (string)$row['id'];
}

/** Infer transfer/QRIS/card from one active physical payment account. */
function tamasyaInferPaymentMethodFromAccount(PDO $pdo,string $bankAccountId,bool $lock=false,array $allowed=['transfer','qris','card']): string {
    $id=trim($bankAccountId);
    if($id==='')throw new InvalidArgumentException('Akun pembayaran wajib dipilih untuk inferensi metode.');
    $stmt=$pdo->prepare("SELECT id,type,isActive FROM bank_accounts WHERE id=? LIMIT 1".($lock?' FOR UPDATE':''));
    $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || (int)($row['isActive']??0)!==1)throw new InvalidArgumentException('Akun pembayaran tidak aktif atau tidak ditemukan.');
    $method=tamasyaPaymentMethodForAccountType((string)($row['type']??''))??'';
    if($method===''||!in_array($method,$allowed,true))throw new InvalidArgumentException('Tipe akun pembayaran tidak sesuai metode yang diperbolehkan.');
    return $method;
}

/**
 * One active tax-rule window for the same source/kind/priority is allowed at a time.
 * This is the write-side invariant used by both Save/Edit and Reactivate so the
 * resolver never depends on button-specific validation. Existing dirty/legacy
 * ambiguity is additionally rejected by resolveConfiguredTaxRule() below.
 */
function tamasyaAssertTaxRuleWindowUnique(PDO $pdo, array $candidate): void {
    if (!$pdo->inTransaction()) throw new RuntimeException('Validasi periode Aturan Pajak wajib berada dalam transaksi database.');
    // Serialize every tax-rule writer on one canonical singleton row before
    // checking the rule set. Locking only rows that already conflict is not
    // sufficient for two concurrent INSERTs when no conflicting row exists yet.
    // This keeps Save/Edit/Reactivate race-safe without a new table or schema.
    $taxWriterLock=$pdo->query("SELECT id FROM config WHERE id='system_default' LIMIT 1 FOR UPDATE");
    if(!$taxWriterLock || $taxWriterLock->fetchColumn()===false){
        throw new RuntimeException('Konfigurasi sistem tidak ditemukan; perubahan Aturan Pajak dihentikan agar tidak membuat rule ambigu.');
    }
    $id=trim((string)($candidate['id']??''));
    $sourcePattern=trim((string)($candidate['source_pattern']??$candidate['sourcePattern']??'*'))?:'*';
    $transactionKind=strtolower(trim((string)($candidate['transaction_kind']??$candidate['transactionKind']??'*')))?:'*';
    $priority=(int)($candidate['priority']??0);
    $from=trim((string)($candidate['effective_from']??$candidate['effectiveFrom']??''));
    $until=trim((string)($candidate['effective_until']??$candidate['effectiveUntil']??''));
    if($id==='')throw new InvalidArgumentException('ID Aturan Pajak wajib tersedia untuk validasi periode.');
    if(!in_array($transactionKind,tamasyaTaxRuleKindCatalog(),true))throw new InvalidArgumentException('Jenis transaksi Aturan Pajak tidak didukung.');
    if($from!==''&&!validIsoDate($from))throw new InvalidArgumentException('Tanggal mulai Aturan Pajak tidak valid.');
    if($until!==''&&!validIsoDate($until))throw new InvalidArgumentException('Tanggal akhir Aturan Pajak tidak valid.');
    if($from!==''&&$until!==''&&$until<$from)throw new InvalidArgumentException('Tanggal akhir Aturan Pajak tidak boleh sebelum tanggal mulai.');
    $overlap=$pdo->prepare("SELECT id,name FROM tax_rules
        WHERE id<>? AND is_active=1 AND source_pattern=? AND transaction_kind=? AND priority=?
          AND COALESCE(effective_from,'1000-01-01')<=COALESCE(NULLIF(?,''),'9999-12-31')
          AND COALESCE(effective_until,'9999-12-31')>=COALESCE(NULLIF(?,''),'1000-01-01')
        ORDER BY effective_from,id LIMIT 1 FOR UPDATE");
    $overlap->execute([$id,$sourcePattern,$transactionKind,$priority,$until,$from]);
    if($conflict=$overlap->fetch(PDO::FETCH_ASSOC)){
        throw new InvalidArgumentException('Periode aturan bertabrakan dengan “'.($conflict['name']??$conflict['id']).'” pada sumber, jenis, dan prioritas yang sama. Ubah periode atau prioritas agar hasil pajak tidak ambigu.');
    }
}

/** Resolve the complete effective tax rule for an immutable transaction snapshot.
 *
 * Missing rules are explicit so historical backfill can preserve "unknown"
 * PBJT without inventing a rate. Database/query failures are never converted
 * into 0%; callers receive the error.
 */
function resolveConfiguredTaxRule($pdo, $bookingSource, $transactionKind, $effectiveDate = null): array {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database aturan pajak tidak tersedia.');
    $source = tamasyaNormalizeTaxSource((string)$bookingSource);
    $kind = tamasyaNormalizeTaxKind((string)$transactionKind);
    $date = $effectiveDate && validIsoDate($effectiveDate) ? $effectiveDate : date('Y-m-d');

    $taxRuleSql = "SELECT * FROM tax_rules
        WHERE is_active = 1
          AND (effective_from IS NULL OR effective_from <= ?)
          AND (effective_until IS NULL OR effective_until >= ?)
        ORDER BY priority DESC, created_at DESC, id ASC";
    // Live financial mutations resolve tax inside a DB transaction. Lock the
    // applicable rule set in shared mode so a concurrent admin edit cannot
    // change the rule between snapshot resolution and the financial commit.
    if ($pdo->inTransaction()) $taxRuleSql .= " LOCK IN SHARE MODE";
    $stmt = $pdo->prepare($taxRuleSql);
    $stmt->execute([$date, $date]);
    $normalizedSource = strtolower($source);
    $sourceClass=tamasyaTaxSourceClass($source);
    $isPosSource=$sourceClass==='pos';
    $isOta=$sourceClass==='ota';
    $isDirect=$sourceClass==='direct';
    $bestPriority = null;
    $bestSpecificity = -1;
    $bestRule = null;
    $bestMatch = 'none';
    $bestRuleIds = [];
    $bestRuleNames = [];
    $applicableCandidates = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
        $pattern = strtolower(trim((string)$rule['source_pattern']));
        $ruleKind = strtolower(trim((string)$rule['transaction_kind']));
        $exactSource = $pattern !== '' && $pattern === $normalizedSource;
        $groupedSource = ($pattern === 'direct' && $isDirect) || ($pattern === 'ota' && $isOta);
        $sourceSpecificity = $exactSource ? 3 : ($groupedSource ? 2 : ($pattern === '*' ? 1 : -1));
        // A generic `pos` kind can cover all POS product profiles while exact
        // product kinds (food_beverage/retail/service/extra) remain more specific.
        $groupedPosKind = $isPosSource && $ruleKind === 'pos' && in_array($kind, array_merge(['pos'],tamasyaPosTaxProfileKinds()), true);
        $kindSpecificity = $ruleKind === $kind ? 3 : ($groupedPosKind ? 2 : ($ruleKind === '*' ? 1 : -1));
        if ($sourceSpecificity < 0 || $kindSpecificity < 0) continue;

        $priority = (int)($rule['priority'] ?? 0);
        $specificity = ($sourceSpecificity * 10) + $kindSpecificity;
        $candidateTaxable=!empty($rule['taxable']);
        $candidateRate=$candidateTaxable?max(0.0,(float)($rule['rate']??0)):0.0;
        $applicableCandidates[]=[
            'rule'=>$rule,'priority'=>$priority,'specificity'=>$specificity,
            'taxable'=>$candidateTaxable,'rate'=>$candidateRate,
            'sourceSpecificity'=>$sourceSpecificity,'kindSpecificity'=>$kindSpecificity,
        ];
        // Priority remains the intentional first-level selector.  We still scan
        // lower priorities below so a broad high-priority wildcard cannot
        // silently shadow a more-specific rule with a different tax outcome.
        if ($bestPriority === null || $priority > $bestPriority || ($priority === $bestPriority && $specificity > $bestSpecificity)) {
            $bestPriority = $priority;
            $bestSpecificity = $specificity;
            $bestRule = $rule;
            $bestMatch = $exactSource ? 'exact' : ($groupedSource ? 'group' : 'wildcard');
            $bestRuleIds = [(string)($rule['id'] ?? '')];
            $bestRuleNames = [(string)($rule['name'] ?? $rule['id'] ?? '')];
        } elseif ($priority === $bestPriority && $specificity === $bestSpecificity) {
            $bestRuleIds[] = (string)($rule['id'] ?? '');
            $bestRuleNames[] = (string)($rule['name'] ?? $rule['id'] ?? '');
        }
    }


    if ($bestRule) {
        $bestTaxable=!empty($bestRule['taxable']);
        $bestRate=$bestTaxable?max(0.0,(float)($bestRule['rate']??0)):0.0;
        foreach($applicableCandidates as $candidate){
            if((int)$candidate['priority'] >= (int)$bestPriority)continue;
            if((int)$candidate['specificity'] <= (int)$bestSpecificity)continue;
            $differentOutcome=((bool)$candidate['taxable']!==$bestTaxable)
                || abs((float)$candidate['rate']-$bestRate)>0.0001;
            if(!$differentOutcome)continue;
            $shadowed=(array)$candidate['rule'];
            throw new RuntimeException(
                'Konflik prioritas Aturan Pajak untuk sumber '.$source.', jenis '.$kind.', tanggal '.$date.'. '.
                'Rule “'.(string)($bestRule['name']??$bestRule['id']??''). '” (prioritas '.(int)$bestPriority.') lebih umum tetapi menutupi rule yang lebih spesifik “'.
                (string)($shadowed['name']??$shadowed['id']??'').'” (prioritas '.(int)$candidate['priority'].') dengan hasil pajak berbeda. '.
                'Samakan hasil atau atur prioritas agar rule yang lebih spesifik tidak tertutup. Transaksi dihentikan agar PBJT tidak salah.'
            );
        }
    }

    if (count(array_unique(array_filter($bestRuleIds,static fn($v)=>$v!==''))) > 1) {
        throw new RuntimeException(
            'Aturan Pajak aktif ambigu untuk sumber '.$source.', jenis '.$kind.', tanggal '.$date.
            ': '.implode(', ',array_values(array_unique(array_filter($bestRuleNames,static fn($v)=>$v!=='')))).
            '. Nonaktifkan atau pisahkan periode/prioritas rule sebelum transaksi baru diproses.'
        );
    }

    if ($bestRule) {
        $taxable = !empty($bestRule['taxable']);
        return [
            'matched'=>true,
            'ruleId'=>(string)$bestRule['id'],
            'ruleName'=>(string)$bestRule['name'],
            'rate'=>$taxable ? max(0.0,(float)$bestRule['rate']) : 0.0,
            'taxable'=>$taxable,
            'source'=>$source,
            'transactionKind'=>$kind,
            'effectiveDate'=>$date,
            'matchedBy'=>$bestMatch,
        ];
    }

    return [
        'matched'=>false,'ruleId'=>null,'ruleName'=>null,'rate'=>null,'taxable'=>null,
        'source'=>$source,'transactionKind'=>$kind,'effectiveDate'=>$date,'matchedBy'=>'none'
    ];
}

/** Resolve the display name for a stored tax rule ID without inventing one. */
function tamasyaTaxRuleNameById(PDO $pdo, ?string $ruleId): ?string {
    $ruleId=trim((string)($ruleId??''));
    if($ruleId==='')return null;
    $stmt=$pdo->prepare("SELECT name FROM tax_rules WHERE id=? LIMIT 1");
    $stmt->execute([$ruleId]);
    $name=$stmt->fetchColumn();
    return $name===false?null:(trim((string)$name)?:null);
}

/** Strict live rate-only wrapper. Historical flows use resolveConfiguredTaxRule()
 * directly so they can explicitly store unresolved historical PBJT.
 */
function resolveConfiguredTaxRate($pdo, $bookingSource, $transactionKind, $fallbackRate = null, $effectiveDate = null) {
    $resolved = resolveConfiguredTaxRule($pdo,$bookingSource,$transactionKind,$effectiveDate);
    if (empty($resolved['matched'])) {
        throw new RuntimeException(
            'Tidak ada Aturan Pajak aktif untuk sumber '.(string)$resolved['source'].
            ', jenis '.(string)$resolved['transactionKind'].
            ', tanggal '.(string)$resolved['effectiveDate'].
            '. Konfigurasikan rule pajak eksplisit (termasuk rule 0%/tidak kena pajak) sebelum transaksi live.'
        );
    }
    return (float)$resolved['rate'];
}
