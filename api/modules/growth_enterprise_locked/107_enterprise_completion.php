<?php
/**
 * TAMASYA V137 - Optional Enterprise Completion.
 *
 * Adds advanced folio workflow, PR/GRN/AP subledger, CRM/loyalty/consent,
 * operational health controls and provider-adapter metadata without altering
 * the core tables. All money/booking source-of-truth remains canonical.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaEnterpriseEnabled(): bool {
    return function_exists('tamasyaGrowthSuiteEnabled') && tamasyaGrowthSuiteEnabled()
        && tamasyaGrowthFlag('TAMASYA_ENTERPRISE_COMPLETION_ENABLED', false);
}
function tamasyaEnterpriseCrmCampaignSendEnabled(): bool { return tamasyaGrowthFlag('TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED', false); }
function tamasyaEnterpriseModuleEnabled(string $module): bool {
    if(!tamasyaEnterpriseEnabled()) return false;
    $map=[
        'folio'=>'TAMASYA_ENTERPRISE_FOLIO_WORKFLOW_ENABLED',
        'ap'=>'TAMASYA_ENTERPRISE_PROCUREMENT_AP_ENABLED',
        'crm'=>'TAMASYA_ENTERPRISE_CRM_LOYALTY_ENABLED',
        'health'=>'TAMASYA_ENTERPRISE_HEALTH_MONITORING_ENABLED',
        'adapters'=>'TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED',
    ];
    if(!isset($map[$module])) return false;
    $default=$module!=='adapters';
    return tamasyaGrowthFlag($map[$module],$default);
}
function tamasyaEnterpriseSchemaTables(): array {
    return [
        'growth_folios','growth_folio_transaction_allocations','growth_folio_charge_allocations','growth_folio_routing_rules','growth_folio_invoices',
        'growth_purchase_requests','growth_purchase_request_items','growth_purchase_request_po_links','growth_goods_receipts','growth_goods_receipt_items',
        'growth_supplier_invoices','growth_supplier_invoice_lines','growth_supplier_invoice_payments',
        'growth_guest_booking_links','growth_loyalty_accounts','growth_loyalty_ledger','growth_loyalty_vouchers','growth_guest_consents',
        'growth_crm_segments','growth_crm_campaigns','growth_crm_campaign_recipients','growth_health_alert_rules','growth_provider_adapters','growth_internal_memos'
    ];
}
function tamasyaEnterpriseSchemaReady(PDO $pdo): bool {
    foreach(tamasyaEnterpriseSchemaTables() as $table) if(!tamasyaGrowthTableExists($pdo,$table)) return false;
    return true;
}
function tamasyaEnterpriseFeatureStatus(?PDO $pdo=null): array {
    $modules=[];foreach(['folio','ap','crm','health','adapters'] as $m)$modules[$m]=tamasyaEnterpriseModuleEnabled($m);
    return [
        'enabled'=>tamasyaEnterpriseEnabled(),'installMode'=>'explicit_cli_optional_modules_install',
        'schemaReady'=>$pdo instanceof PDO?tamasyaEnterpriseSchemaReady($pdo):false,'modules'=>$modules,
        'release'=>'V137-optional-enterprise-completion',
        'safety'=>[
            'coreTablesAltered'=>false,'canonicalTransactionsRemainSourceOfTruth'=>true,
            'folioAllocationsDoNotRewriteJournal'=>true,'supplierPaymentsUseCanonicalTransactions'=>true,
            'marketingRequiresExplicitConsent'=>true,'providerAdaptersDisabledByDefault'=>true,
            'externalPaymentAutoPosting'=>false,'externalChannelAutoBooking'=>false,
        ],
    ];
}
function tamasyaEnterpriseRequireReady(PDO $pdo,string $module): void {
    if(!tamasyaEnterpriseEnabled())throw new RuntimeException('Enterprise Completion belum diaktifkan. Aktifkan hanya setelah backup dan Growth Suite sehat.');
    if(!tamasyaEnterpriseModuleEnabled($module))throw new RuntimeException('Modul Enterprise '.$module.' belum diaktifkan.');
    if(!tamasyaEnterpriseSchemaReady($pdo))throw new RuntimeException('Schema Enterprise Completion belum terpasang. Setelah backup, jalankan optional_modules_install.php --apply --target=enterprise --backup-confirmed=<reference>.');
}
function tamasyaEnterpriseOp(array $input,string $prefix='ent'): string { return tamasyaGrowthOperationId($input,$prefix); }
function tamasyaEnterpriseFetch(PDO $pdo,string $sql,array $params=[]): ?array { $s=$pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null; }
function tamasyaEnterpriseFetchAll(PDO $pdo,string $sql,array $params=[]): array { $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
function tamasyaEnterpriseJson($v): string { return tamasyaJsonEncode($v); }
function tamasyaEnterpriseInvoiceNumber(string $prefix='INV'): string { $prefix=strtoupper(preg_replace('/[^A-Za-z0-9]+/','',(string)$prefix)?:'INV'); return $prefix.'-'.strtoupper(substr(hash('sha256',generateServerId('doc')),0,12)); }

function tamasyaEnterpriseGroupBookingIds(PDO $pdo,string $groupId): array {
    $s=$pdo->prepare("SELECT booking_id FROM growth_group_booking_links WHERE group_id=? ORDER BY linked_at");$s->execute([$groupId]);return array_values(array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN)?:[]));
}
function tamasyaEnterpriseFolioEligibleBookingIds(PDO $pdo,array $folio): array {
    if(trim((string)($folio['booking_id']??''))!=='')return [(string)$folio['booking_id']];
    if(trim((string)($folio['group_id']??''))!=='')return tamasyaEnterpriseGroupBookingIds($pdo,(string)$folio['group_id']);
    if(trim((string)($folio['company_id']??''))!==''){
        $s=$pdo->prepare("SELECT DISTINCT l.booking_id FROM growth_group_booking_links l JOIN growth_group_reservations g ON g.id=l.group_id WHERE g.company_id=?");$s->execute([$folio['company_id']]);return array_values(array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN)?:[]));
    }
    return [];
}
function tamasyaEnterpriseDefaultFolio(PDO $pdo,string $bookingId,array $actor): array {
    $existing=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folios WHERE booking_id=? AND folio_type='guest' AND status<>'void' ORDER BY created_at LIMIT 1",[$bookingId]);
    if($existing)return $existing;
    $booking=tamasyaEnterpriseFetch($pdo,"SELECT id,guestName,roomNumber FROM bookings WHERE id=? LIMIT 1",[$bookingId]);
    if(!$booking)throw new InvalidArgumentException('Booking tidak ditemukan.');
    $id=tamasyaGrowthId('folio');$number=tamasyaEnterpriseInvoiceNumber('FOL');
    $pdo->prepare("INSERT INTO growth_folios(id,folio_number,folio_type,booking_id,name,status,created_by) VALUES (?,?,'guest',?,?,'open',?)")
        ->execute([$id,$number,$bookingId,'Guest Folio - '.(string)$booking['guestName'].' / '.(string)$booking['roomNumber'],$actor['id']??null]);
    return tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folios WHERE id=?",[$id])??[];
}
function tamasyaEnterpriseTransactionAllocatedTotal(PDO $pdo,string $transactionId,?string $excludeAllocationId=null,bool $lock=false): float {
    $sql="SELECT COALESCE(SUM(amount),0) FROM growth_folio_transaction_allocations WHERE transaction_id=?";
    $params=[$transactionId];if($excludeAllocationId!==null){$sql.=" AND id<>?";$params[]=$excludeAllocationId;}
    if($lock)$sql.=" FOR UPDATE";
    $s=$pdo->prepare($sql);$s->execute($params);return round((float)$s->fetchColumn(),2);
}
function tamasyaEnterpriseBookingChargeSource(PDO $pdo,string $bookingId): array {
    $b=tamasyaEnterpriseFetch($pdo,"SELECT id,guestName,roomNumber,totalAmount,roomCharge,extraCharge,extras,discountAmount FROM bookings WHERE id=?",[$bookingId]);
    if(!$b)throw new InvalidArgumentException('Booking tidak ditemukan.');
    $p=tamasyaGrowthBookingCharges($b);
    return ['booking'=>$b,'charges'=>['room_charge'=>$p['roomGross'],'extra_charge'=>$p['services'],'discount'=>$p['discount']],'netTotal'=>$p['netTotal']];
}
function tamasyaEnterpriseChargeAllocatedTotal(PDO $pdo,string $bookingId,string $chargeType,?string $excludeId=null,bool $lock=false): float {
    $sql="SELECT COALESCE(SUM(amount),0) FROM growth_folio_charge_allocations WHERE booking_id=? AND charge_type=?";$params=[$bookingId,$chargeType];
    if($excludeId!==null){$sql.=" AND id<>?";$params[]=$excludeId;}if($lock)$sql.=" FOR UPDATE";
    $s=$pdo->prepare($sql);$s->execute($params);return round((float)$s->fetchColumn(),2);
}
function tamasyaEnterpriseFolioAvailableCharges(PDO $pdo,string $folioId): array {
    $folio=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folios WHERE id=?",[$folioId]);if(!$folio)throw new InvalidArgumentException('Folio tidak ditemukan.');
    $out=[];foreach(tamasyaEnterpriseFolioEligibleBookingIds($pdo,$folio) as $bookingId){$src=tamasyaEnterpriseBookingChargeSource($pdo,$bookingId);foreach($src['charges'] as $type=>$source){if($source<=0)continue;$allocated=tamasyaEnterpriseChargeAllocatedTotal($pdo,$bookingId,$type);$available=round(max(0,$source-$allocated),2);if($available>0.009)$out[]=['bookingId'=>$bookingId,'guestName'=>$src['booking']['guestName'],'roomNumber'=>$src['booking']['roomNumber'],'chargeType'=>$type,'sourceAmount'=>$source,'allocated'=>$allocated,'available'=>$available];}}
    return $out;
}
function tamasyaEnterpriseFolioDetail(PDO $pdo,string $folioId): array {
    $folio=tamasyaEnterpriseFetch($pdo,"SELECT f.*,g.name group_name,c.name company_name,b.guestName,b.roomNumber FROM growth_folios f LEFT JOIN growth_group_reservations g ON g.id=f.group_id LEFT JOIN growth_companies c ON c.id=f.company_id LEFT JOIN bookings b ON b.id=f.booking_id WHERE f.id=?",[$folioId]);
    if(!$folio)throw new InvalidArgumentException('Folio tidak ditemukan.');
    $alloc=tamasyaEnterpriseFetchAll($pdo,"SELECT a.*,t.type,t.category,t.subcategory,t.amount transaction_amount,t.date,t.description,t.bookingId,t.transactionKind,t.documentNumber FROM growth_folio_transaction_allocations a JOIN transactions t ON t.id=a.transaction_id WHERE a.folio_id=? ORDER BY t.date,a.allocated_at",[$folioId]);
    $paymentAllocated=0.0;foreach($alloc as &$a){$a['signed_amount']=round(((string)($a['type']??'')==='expense'?-1:1)*(float)$a['amount'],2);$paymentAllocated=round($paymentAllocated+(float)$a['signed_amount'],2);}unset($a);
    $charges=tamasyaEnterpriseFetchAll($pdo,"SELECT a.*,b.guestName,b.roomNumber FROM growth_folio_charge_allocations a JOIN bookings b ON b.id=a.booking_id WHERE a.folio_id=? ORDER BY b.checkIn,a.booking_id,a.charge_type",[$folioId]);
    $chargeTotal=0.0;foreach($charges as &$c){$sign=((string)$c['charge_type']==='discount')?-1:1;$c['signed_amount']=round($sign*(float)$c['amount'],2);$chargeTotal=round($chargeTotal+(float)$c['signed_amount'],2);}unset($c);
    $invoices=tamasyaEnterpriseFetchAll($pdo,"SELECT id,invoice_number,issue_date,due_date,status,total_amount,currency,created_at,voided_at,void_reason FROM growth_folio_invoices WHERE folio_id=? ORDER BY issue_date DESC,created_at DESC",[$folioId]);
    $rules=tamasyaEnterpriseFetchAll($pdo,"SELECT * FROM growth_folio_routing_rules WHERE target_folio_id=? ORDER BY priority,id",[$folioId]);
    return ['folio'=>$folio,'allocations'=>$alloc,'paymentAllocatedTotal'=>$paymentAllocated,'chargeAllocations'=>$charges,'chargeTotal'=>$chargeTotal,'balance'=>round($chargeTotal-$paymentAllocated,2),'invoices'=>$invoices,'routingRules'=>$rules,'eligibleBookingIds'=>tamasyaEnterpriseFolioEligibleBookingIds($pdo,$folio)];
}
function tamasyaEnterpriseFolioAvailableTransactions(PDO $pdo,string $folioId): array {
    $folio=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folios WHERE id=?",[$folioId]);if(!$folio)throw new InvalidArgumentException('Folio tidak ditemukan.');
    $ids=tamasyaEnterpriseFolioEligibleBookingIds($pdo,$folio);if(!$ids)return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT t.id,t.bookingId,t.type,t.category,t.subcategory,t.amount,t.date,t.description,t.transactionKind,t.documentNumber,t.bankAccountId,COALESCE(a.allocated,0) allocated,ROUND(t.amount-COALESCE(a.allocated,0),2) available FROM transactions t LEFT JOIN (SELECT transaction_id,SUM(amount) allocated FROM growth_folio_transaction_allocations GROUP BY transaction_id) a ON a.transaction_id=t.id WHERE t.bookingId IN ($ph) AND t.amount>COALESCE(a.allocated,0)+0.009 AND LOWER(COALESCE(t.bankAccountId,'')) NOT IN ('inventory_asset','guest_receivable','accounts_payable') AND COALESCE(t.transactionKind,'manual') NOT IN ('security_deposit_received','security_deposit_refund','security_deposit_forfeit','pos_cogs','pos_cogs_reversal','pos_room_charge','supplier_invoice_accrual','supplier_inventory_accrual','supplier_asset_accrual','supplier_ap_payment','internal_transfer','ota_transfer') AND ((t.type='income') OR (t.type='expense' AND COALESCE(t.transactionKind,'manual')='refund')) ORDER BY t.date,t.createdAt,t.id";
    return tamasyaEnterpriseFetchAll($pdo,$sql,$ids);
}
function tamasyaEnterpriseIssueFolioInvoice(PDO $pdo,string $folioId,array $actor,string $operationId,array $input): array {
    $detail=tamasyaEnterpriseFolioDetail($pdo,$folioId);$folio=$detail['folio'];
    if((string)$folio['status']==='void')throw new RuntimeException('Folio sudah void.');
    if(!$detail['chargeAllocations'])throw new RuntimeException('Tidak ada tagihan booking yang dialokasikan pada folio. Sinkronkan/alokasikan room charge terlebih dahulu.');
    $snapshot=['folio'=>['id'=>$folio['id'],'number'=>$folio['folio_number'],'type'=>$folio['folio_type'],'name'=>$folio['name'],'bookingId'=>$folio['booking_id'],'groupId'=>$folio['group_id'],'companyId'=>$folio['company_id']], 'chargeLines'=>$detail['chargeAllocations'],'payments'=>$detail['allocations']];
    $total=round((float)$detail['chargeTotal'],2);$snapshot['total']=$total;$snapshot['paymentsApplied']=round((float)$detail['paymentAllocatedTotal'],2);$snapshot['balanceAtIssue']=round($total-$snapshot['paymentsApplied'],2);$snapshot['generatedAt']=gmdate('c');$json=tamasyaEnterpriseJson($snapshot);$hash=hash('sha256',$json);
    $id=tamasyaGrowthId('finv');$number=trim((string)($input['invoiceNumber']??''))?:tamasyaEnterpriseInvoiceNumber('FOLINV');
    $issue=trim((string)($input['issueDate']??date('Y-m-d')));$due=trim((string)($input['dueDate']??''));
    if(!tamasyaGrowthValidDate($issue)||($due!==''&&!tamasyaGrowthValidDate($due)))throw new InvalidArgumentException('Tanggal invoice tidak valid.');
    $pdo->prepare("INSERT INTO growth_folio_invoices(id,invoice_number,folio_id,issue_date,due_date,status,total_amount,currency,source_hash,snapshot_json,notes,operation_id,created_by) VALUES (?,?,?,?,?,'issued',?,?,?,?,?,?,?)")
        ->execute([$id,$number,$folioId,$issue,$due?:null,$total,$folio['currency']??'IDR',$hash,$json,trim((string)($input['notes']??''))?:null,$operationId,$actor['id']??null]);
    return tamasyaEnterpriseFetch($pdo,"SELECT id,invoice_number,folio_id,issue_date,due_date,status,total_amount,currency,source_hash,created_at FROM growth_folio_invoices WHERE id=?",[$id])??[];
}

function tamasyaEnterprisePurchaseRequestDetail(PDO $pdo,string $id): array {
    $pr=tamasyaEnterpriseFetch($pdo,"SELECT r.*,s.name requested_by_name,a.name approved_by_name FROM growth_purchase_requests r LEFT JOIN staff s ON s.id=r.requested_by LEFT JOIN staff a ON a.id=r.approved_by WHERE r.id=?",[$id]);if(!$pr)throw new InvalidArgumentException('Purchase Request tidak ditemukan.');
    return [
        'request'=>$pr,
        'items'=>tamasyaEnterpriseFetchAll($pdo,"SELECT * FROM growth_purchase_request_items WHERE pr_id=? ORDER BY id",[$id]),
        'purchaseOrders'=>tamasyaEnterpriseFetchAll($pdo,"SELECT l.*,p.po_number,p.status,p.total_amount,p.order_date,v.name vendor_name FROM growth_purchase_request_po_links l JOIN growth_purchase_orders p ON p.id=l.po_id JOIN growth_vendors v ON v.id=p.vendor_id WHERE l.pr_id=? ORDER BY l.linked_at",[$id])
    ];
}
function tamasyaEnterpriseGoodsReceiptDetail(PDO $pdo,string $id): array {
    $grn=tamasyaEnterpriseFetch($pdo,"SELECT g.*,p.po_number,v.name vendor_name FROM growth_goods_receipts g JOIN growth_purchase_orders p ON p.id=g.po_id JOIN growth_vendors v ON v.id=p.vendor_id WHERE g.id=?",[$id]);if(!$grn)throw new InvalidArgumentException('GRN tidak ditemukan.');
    return ['receipt'=>$grn,'items'=>tamasyaEnterpriseFetchAll($pdo,"SELECT i.*,pi.item_name,pi.quantity po_quantity,pi.received_quantity po_received_quantity FROM growth_goods_receipt_items i JOIN growth_purchase_order_items pi ON pi.id=i.po_item_id WHERE i.grn_id=? ORDER BY i.id",[$id])];
}
function tamasyaEnterpriseSupplierInvoiceDetail(PDO $pdo,string $id): array {
    $inv=tamasyaEnterpriseFetch($pdo,"SELECT i.*,v.name vendor_name,p.po_number,g.grn_number FROM growth_supplier_invoices i JOIN growth_vendors v ON v.id=i.vendor_id LEFT JOIN growth_purchase_orders p ON p.id=i.po_id LEFT JOIN growth_goods_receipts g ON g.id=i.grn_id WHERE i.id=?",[$id]);if(!$inv)throw new InvalidArgumentException('Supplier Invoice tidak ditemukan.');
    $lines=tamasyaEnterpriseFetchAll($pdo,"SELECT * FROM growth_supplier_invoice_lines WHERE supplier_invoice_id=? ORDER BY id",[$id]);
    $payments=tamasyaEnterpriseFetchAll($pdo,"SELECT p.*,t.date,t.description,t.amount transaction_amount,t.documentNumber,t.bankAccountId,t.transactionKind FROM growth_supplier_invoice_payments p JOIN transactions t ON t.id=p.transaction_id WHERE p.supplier_invoice_id=? ORDER BY p.linked_at",[$id]);
    $paid=round(array_sum(array_map(static fn($x)=>(float)$x['amount_applied'],$payments)),2);$total=(float)$inv['total_amount'];
    return ['invoice'=>$inv,'lines'=>$lines,'payments'=>$payments,'paidAmount'=>$paid,'outstanding'=>round(max(0,$total-$paid),2)];
}
function tamasyaEnterpriseApAging(PDO $pdo,?string $asOf=null): array {
    $asOf=$asOf?:date('Y-m-d');if(!tamasyaGrowthValidDate($asOf))throw new InvalidArgumentException('Tanggal aging tidak valid.');
    $rows=tamasyaEnterpriseFetchAll($pdo,"SELECT i.id,i.supplier_invoice_number,i.vendor_id,v.name vendor_name,i.invoice_date,i.due_date,i.total_amount,i.status,COALESCE(p.paid,0) paid_amount,ROUND(i.total_amount-COALESCE(p.paid,0),2) outstanding,DATEDIFF(?,COALESCE(i.due_date,i.invoice_date)) days_past_due FROM growth_supplier_invoices i JOIN growth_vendors v ON v.id=i.vendor_id LEFT JOIN (SELECT gp.supplier_invoice_id,SUM(gp.amount_applied) paid FROM growth_supplier_invoice_payments gp JOIN transactions t ON t.id=gp.transaction_id WHERE t.`date`<=? GROUP BY gp.supplier_invoice_id) p ON p.supplier_invoice_id=i.id WHERE i.status<>'draft' AND i.status<>'cancelled' AND i.invoice_date<=? AND i.total_amount>COALESCE(p.paid,0)+0.009 ORDER BY COALESCE(i.due_date,i.invoice_date),v.name",[$asOf,$asOf,$asOf]);
    $b=['current'=>0.0,'1_30'=>0.0,'31_60'=>0.0,'61_90'=>0.0,'over_90'=>0.0];foreach($rows as &$r){$d=(int)$r['days_past_due'];$o=(float)$r['outstanding'];$key=$d<=0?'current':($d<=30?'1_30':($d<=60?'31_60':($d<=90?'61_90':'over_90')));$b[$key]=round($b[$key]+$o,2);$r['bucket']=$key;}unset($r);return ['asOf'=>$asOf,'buckets'=>$b,'rows'=>$rows,'totalOutstanding'=>round(array_sum($b),2)];
}
function tamasyaEnterpriseValidateBankAccount(PDO $pdo,string $method,string $bankAccountId): ?string {
    return tamasyaResolvePaymentAccount($pdo,$method,$bankAccountId,[
        'allowedMethods'=>['cash','transfer','qris'],
        'lock'=>true,
        'context'=>'Pembayaran supplier/AP'
    ]);
}

function tamasyaEnterpriseCreateApPaymentTransaction(PDO $pdo,array $actor,array $invoice,float $amount,string $method,string $bankAccountId,string $operationId,string $date): string {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'pembayaran hutang supplier/AP');
    if(!$pdo->inTransaction())throw new RuntimeException('Pembayaran AP wajib berada dalam transaksi database.');
    if($amount<=0)throw new InvalidArgumentException('Nominal pembayaran AP harus lebih dari nol.');
    $method=strtolower(trim($method));
    $account=tamasyaEnterpriseValidateBankAccount($pdo,$method,$bankAccountId);
    $shift=$method==='cash'?tamasyaResolveExpenseCashShift($pdo,$actor,null):null;
    $id='tx_ap_'.substr(hash('sha256',$operationId),0,32);$doc='APPAY-'.date('Ymd').'-'.strtoupper(substr(hash('sha256',$operationId),0,8));
    tamasyaPostFinancialTransaction($pdo,[
        'id'=>$id,'type'=>'expense','category'=>'Hutang Usaha','subcategory'=>'Pembayaran Supplier','amount'=>$amount,'date'=>$date,
        'description'=>'Pembayaran AP '.$invoice['supplier_invoice_number'].' - '.$invoice['vendor_name'],'createdBy'=>currentStaffLabel($actor),
        'bankAccountId'=>$account,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,'taxSnapshotStatus'=>'not_applicable','taxSource'=>'enterprise_ap',
        'transactionKind'=>'supplier_ap_payment','sourceEntity'=>'growth_supplier_invoice','sourceEntityId'=>$invoice['id'],'isSystemGenerated'=>1,
        'operationId'=>$operationId,'documentNumber'=>$doc,'shiftSessionId'=>$shift,'recordOrigin'=>'live_operation','shiftExempt'=>0,
        'updatedBy'=>$actor['id']??null,'updatedSource'=>'web','version'=>1
    ],$actor,'growth_procurement',['source'=>'web']);
    return $id;
}
function tamasyaEnterprisePostSupplierInvoiceAccrual(PDO $pdo,array $invoice,array $lines,array $actor,string $operationId): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'posting akrual supplier');
    if(!$pdo->inTransaction())throw new RuntimeException('Posting akrual supplier wajib berada dalam transaksi database.');
    $txIds=[];
    foreach($lines as $index=>$line){
        if(trim((string)($line['canonical_transaction_id']??''))!==''){$txIds[]=(string)$line['canonical_transaction_id'];continue;}
        $amount=round((float)$line['line_total'],2);if($amount<=0)continue;
        $class=strtolower((string)$line['account_class']);$kind=$class==='inventory'?'supplier_inventory_accrual':($class==='asset'?'supplier_asset_accrual':'supplier_invoice_accrual');
        $txOp=substr($operationId.':line:'.($index+1),0,100);$id='tx_api_'.substr(hash('sha256',$txOp),0,30);
        tamasyaPostFinancialTransaction($pdo,[
            'id'=>$id,'type'=>'expense','category'=>(string)$line['expense_category'],'subcategory'=>(string)$line['item_name'],'amount'=>$amount,
            'date'=>(string)$invoice['invoice_date'],'description'=>'Akrual Supplier '.$invoice['supplier_invoice_number'].' - '.(string)$line['item_name'],
            'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>'accounts_payable','baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
            'taxSnapshotStatus'=>'not_applicable','taxSource'=>'enterprise_ap','transactionKind'=>$kind,'sourceEntity'=>'growth_supplier_invoice',
            'sourceEntityId'=>$invoice['id'],'isSystemGenerated'=>1,'operationId'=>$txOp,'documentNumber'=>'APINV-'.$invoice['supplier_invoice_number'].'-'.($index+1),
            'recordOrigin'=>'live_operation','shiftExempt'=>1,'shiftExemptionReason'=>'Akrual invoice supplier non-kas','updatedBy'=>$actor['id']??null,
            'updatedSource'=>'web','version'=>1
        ],$actor,'growth_procurement',['source'=>'web']);
        $pdo->prepare("UPDATE growth_supplier_invoice_lines SET canonical_transaction_id=? WHERE id=?")->execute([$id,$line['id']]);$txIds[]=$id;
    }
    return $txIds;
}

function tamasyaEnterpriseGuestLinkCandidates(PDO $pdo,string $q): array {
    $q=trim($q);if(strlen($q)<2)return [];$like='%'.$q.'%';
    return tamasyaEnterpriseFetchAll($pdo,"SELECT id,name,phone,email,blacklisted,retention_until FROM guest_profiles WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? ORDER BY updated_at DESC LIMIT 50",[$like,$like,$like]);
}
function tamasyaEnterpriseLoyaltyAccount(PDO $pdo,string $guestProfileId,bool $create=false): ?array {
    $row=tamasyaEnterpriseFetch($pdo,"SELECT a.*,g.name,g.phone,g.email,g.blacklisted FROM growth_loyalty_accounts a JOIN guest_profiles g ON g.id=a.guest_profile_id WHERE a.guest_profile_id=?",[$guestProfileId]);
    if($row||!$create)return $row;
    $guest=tamasyaEnterpriseFetch($pdo,"SELECT * FROM guest_profiles WHERE id=?",[$guestProfileId]);if(!$guest)throw new InvalidArgumentException('Guest profile tidak ditemukan.');
    $id=tamasyaGrowthId('loyal');$member='TMY-'.strtoupper(substr(hash('sha256',$guestProfileId.'|'.microtime(true)),0,10));
    $pdo->prepare("INSERT INTO growth_loyalty_accounts(id,guest_profile_id,member_number,tier,status) VALUES (?,?,?,'member','active')")->execute([$id,$guestProfileId,$member]);
    return tamasyaEnterpriseLoyaltyAccount($pdo,$guestProfileId,false);
}
function tamasyaEnterpriseLoyaltyTier(float $spend,int $nights): string {
    if($nights>=30||$spend>=30000000)return 'platinum';if($nights>=15||$spend>=15000000)return 'gold';if($nights>=5||$spend>=5000000)return 'silver';return 'member';
}
function tamasyaEnterpriseCrmSegmentGuests(PDO $pdo,array $condition,int $limit=500): array {
    $where=['g.blacklisted=0'];$params=[];
    if(isset($condition['tier_in'])&&is_array($condition['tier_in'])&&$condition['tier_in']){$vals=array_values(array_filter(array_map('strval',$condition['tier_in'])));if($vals){$where[]='COALESCE(l.tier,\'member\') IN ('.implode(',',array_fill(0,count($vals),'?')).')';array_push($params,...$vals);}}
    if(isset($condition['min_lifetime_spend'])){$where[]='COALESCE(l.lifetime_spend,0)>=?';$params[]=(float)$condition['min_lifetime_spend'];}
    if(isset($condition['min_nights'])){$where[]='COALESCE(l.lifetime_nights,0)>=?';$params[]=(int)$condition['min_nights'];}
    if(!empty($condition['email_required']))$where[]="TRIM(g.email)<>''";
    $limit=max(1,min(2000,$limit));
    return tamasyaEnterpriseFetchAll($pdo,"SELECT g.id,g.name,g.phone,g.email,COALESCE(l.member_number,'') member_number,COALESCE(l.tier,'member') tier,COALESCE(l.points_balance,0) points_balance,COALESCE(l.lifetime_spend,0) lifetime_spend,COALESCE(l.lifetime_nights,0) lifetime_nights FROM guest_profiles g LEFT JOIN growth_loyalty_accounts l ON l.guest_profile_id=g.id WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(l.lifetime_spend,0) DESC,g.name LIMIT {$limit}",$params);
}
function tamasyaEnterpriseActiveConsent(PDO $pdo,string $guestId,string $type): ?array {
    $latest=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_guest_consents WHERE guest_profile_id=? AND consent_type=? ORDER BY captured_at DESC LIMIT 1",[$guestId,$type]);
    if(!$latest||strtolower((string)($latest['status']??''))!=='granted')return null;
    $expires=trim((string)($latest['expires_at']??''));
    if($expires!==''&&strtotime($expires)!==false&&strtotime($expires)<=time())return null;
    return $latest;
}
function tamasyaEnterpriseHealthSnapshot(PDO $pdo): array {
    $metricErrors=[];
    $scalar=static function(PDO $pdo,string $sql,$default=null) use (&$metricErrors){
        try{return $pdo->query($sql)->fetchColumn();}
        catch(Throwable $e){$metricErrors[]=clientExceptionMessage('Health metric query gagal',$e);return $default;}
    };
    $latestBackup=tamasyaEnterpriseFetch($pdo,"SELECT id,status,created_at,restore_tested_at,size_bytes,checksum FROM backup_runs ORDER BY created_at DESC LIMIT 1")?:null;
    $backupAge=$latestBackup&&strtotime((string)$latestBackup['created_at'])?round((time()-strtotime((string)$latestBackup['created_at']))/3600,1):null;
    $financialHealth=function_exists('tamasyaFinancialIntegrityMetrics')?tamasyaFinancialIntegrityMetrics($pdo):[
        'missingJournals'=>-1,'staleJournals'=>-1,'orphanJournalEntries'=>-1,'orphanJournalLines'=>-1,'unbalancedJournals'=>-1,'journalDifference'=>999999,'unresolvedLiveTax'=>-1,'healthy'=>false
    ];
    $metrics=[
        'open_sync_conflicts'=>(int)($scalar($pdo,"SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'",-1)??-1),
        'sync_pending'=>(int)($scalar($pdo,"SELECT COUNT(*) FROM node_sync_outbox WHERE status IN ('pending','sending','uncertain','retry')",-1)??-1),
        'runtime_errors_24h'=>(int)($scalar($pdo,"SELECT COUNT(*) FROM runtime_request_events WHERE created_at>=NOW()-INTERVAL 24 HOUR AND (http_status>=500 OR outcome='failed')",-1)??-1),
        'open_integrity_issues'=>(int)($scalar($pdo,"SELECT COUNT(*) FROM data_integrity_issues WHERE status='open'",-1)??-1),
        'pending_historical_reviews'=>(int)($scalar($pdo,"SELECT COUNT(*) FROM historical_backfill_adjustments WHERE review_status='pending_review'",-1)??-1),
        'journal_difference'=>round((float)$financialHealth['journalDifference'],2),
        'missing_journals'=>(int)$financialHealth['missingJournals'],
        'stale_journals'=>(int)$financialHealth['staleJournals'],
        'orphan_journal_entries'=>(int)$financialHealth['orphanJournalEntries'],
        'orphan_journal_lines'=>(int)$financialHealth['orphanJournalLines'],
        'unbalanced_journals'=>(int)$financialHealth['unbalancedJournals'],
        'journal_amount_mismatch'=>(int)($financialHealth['journalAmountMismatch']??0),
        'orphan_allocation_transactions'=>(int)($financialHealth['orphanAllocationTransactions']??0),
        'orphan_allocation_bookings'=>(int)($financialHealth['orphanAllocationBookings']??0),
        'bad_allocation_tax'=>(int)($financialHealth['badAllocationTax']??0),
        'over_allocated_transactions'=>(int)($financialHealth['overAllocatedTransactions']??0),
        'full_allocation_tax_mismatch'=>(int)($financialHealth['fullAllocationTaxMismatch']??0),
        'unresolved_live_tax'=>(int)$financialHealth['unresolvedLiveTax'],
        'backup_age_hours'=>$backupAge,
        'backup_restore_tested'=>$latestBackup&&!empty($latestBackup['restore_tested_at'])?1:0,
    ];
    $rules=tamasyaEnterpriseSchemaReady($pdo)?tamasyaEnterpriseFetchAll($pdo,"SELECT * FROM growth_health_alert_rules WHERE active=1 ORDER BY severity DESC,code"):[];$breaches=[];
    foreach($rules as $rule){$key=(string)$rule['metric_key'];if(!array_key_exists($key,$metrics)||!is_numeric($metrics[$key]))continue;$v=(float)$metrics[$key];$t=(float)$rule['threshold_value'];$cmp=(string)$rule['comparison'];$bad=match($cmp){'gte'=>$v>=$t,'lt'=>$v<$t,'lte'=>$v<=$t,'eq'=>abs($v-$t)<0.0001,'neq'=>abs($v-$t)>=0.0001,default=>$v>$t};if($bad)$breaches[]=['rule'=>$rule,'value'=>$v];}
    $metricsKnown=$metrics['open_sync_conflicts']>=0&&$metrics['sync_pending']>=0&&$metrics['runtime_errors_24h']>=0&&$metrics['open_integrity_issues']>=0&&$metrics['pending_historical_reviews']>=0;
    return ['metrics'=>$metrics,'metricErrors'=>$metricErrors,'breaches'=>$breaches,'latestBackup'=>$latestBackup,'financialIntegrity'=>$financialHealth,'healthy'=>$metricsKnown&&!$metricErrors&&!$breaches&&$metrics['open_sync_conflicts']===0&&!empty($financialHealth['healthy'])];
}

function tamasyaEnterprisePatternMatch(string $value,string $pattern): bool {
    $pattern=trim(strtolower($pattern));$value=strtolower(trim($value));
    if($pattern===''||$pattern==='*')return true;
    if(str_contains($pattern,'*')){
        $rx='/^'.str_replace('\\*','.*',preg_quote($pattern,'/')).'$/i';
        return (bool)preg_match($rx,$value);
    }
    return $value===$pattern||str_contains($value,$pattern);
}
function tamasyaEnterpriseApplyFolioRouting(PDO $pdo,string $bookingId,array $actor,string $operationId): array {
    $booking=tamasyaEnterpriseFetch($pdo,"SELECT id FROM bookings WHERE id=? FOR UPDATE",[$bookingId]);
    if(!$booking)throw new InvalidArgumentException('Booking routing folio tidak ditemukan.');
    $groups=tamasyaEnterpriseFetchAll($pdo,"SELECT group_id FROM growth_group_booking_links WHERE booking_id=?",[$bookingId]);
    $groupIds=array_values(array_map(static fn($r)=>(string)$r['group_id'],$groups));
    $params=[$bookingId];$where="(r.booking_id=?";
    if($groupIds){$ph=implode(',',array_fill(0,count($groupIds),'?'));$where.=" OR r.group_id IN ($ph)";$params=array_merge($params,$groupIds);}
    $where.=')';
    $rules=tamasyaEnterpriseFetchAll($pdo,"SELECT r.*,f.status folio_status FROM growth_folio_routing_rules r JOIN growth_folios f ON f.id=r.target_folio_id WHERE r.active=1 AND {$where} ORDER BY r.priority,r.created_at,r.id FOR UPDATE",$params);
    if(!$rules)return ['bookingId'=>$bookingId,'rulesMatched'=>0,'allocationsUpdated'=>0,'details'=>[]];
    $src=tamasyaEnterpriseBookingChargeSource($pdo,$bookingId);$updated=0;$details=[];
    $roomCategoryName=tamasyaSystemFinanceCategoryName($pdo,'room_rental','income');
    $extraCategoryName=tamasyaSystemFinanceCategoryName($pdo,'extra_service','income');
    foreach($rules as $rule){
        if((string)$rule['folio_status']!=='open')continue;
        $invoiceCount=tamasyaEnterpriseFetch($pdo,"SELECT COUNT(*) total FROM growth_folio_invoices WHERE folio_id=? AND status='issued'",[$rule['target_folio_id']]);
        if((int)($invoiceCount['total']??0)>0)continue;
        foreach($src['charges'] as $chargeType=>$source){
            if($source<=0)continue;
            $category=$chargeType==='room_charge'?$roomCategoryName:($chargeType==='extra_charge'?$extraCategoryName:'Diskon');
            if(!tamasyaEnterprisePatternMatch($chargeType,(string)$rule['transaction_kind_pattern']))continue;
            if(!tamasyaEnterprisePatternMatch($category,(string)$rule['category_pattern']))continue;
            $existing=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folio_charge_allocations WHERE folio_id=? AND booking_id=? AND charge_type=? FOR UPDATE",[$rule['target_folio_id'],$bookingId,$chargeType]);
            $other=tamasyaEnterpriseChargeAllocatedTotal($pdo,$bookingId,$chargeType,$existing['id']??null,true);
            $remaining=max(0,round($source-$other,2));
            $target=round(min($remaining,$source*max(0.0,min(100.0,(float)$rule['route_percent']))/100),2);
            if($target<=0.009)continue;
            $oid=substr($operationId,0,45).':route:'.substr(hash('sha256',$rule['id'].'|'.$bookingId.'|'.$chargeType),0,45);
            if($existing){
                $pdo->prepare("UPDATE growth_folio_charge_allocations SET amount=?,source_amount_snapshot=?,notes=?,operation_id=?,allocated_by=?,allocated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$target,$source,'Auto routing rule '.$rule['id'],$oid,$actor['id']??null,$existing['id']]);$allocationId=$existing['id'];
            }else{
                $allocationId=tamasyaGrowthId('fcharge');
                $pdo->prepare("INSERT INTO growth_folio_charge_allocations(id,folio_id,booking_id,charge_type,amount,source_amount_snapshot,notes,operation_id,allocated_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$allocationId,$rule['target_folio_id'],$bookingId,$chargeType,$target,$source,'Auto routing rule '.$rule['id'],$oid,$actor['id']??null]);
            }
            $updated++;$details[]=['ruleId'=>$rule['id'],'folioId'=>$rule['target_folio_id'],'chargeType'=>$chargeType,'amount'=>$target,'sourceAmount'=>$source,'allocationId'=>$allocationId];
        }
    }
    return ['bookingId'=>$bookingId,'rulesMatched'=>count($rules),'allocationsUpdated'=>$updated,'details'=>$details];
}

function tamasyaEnterpriseSupplierInvoiceThreeWayMatch(PDO $pdo,string $invoiceId): array {
    $detail=tamasyaEnterpriseSupplierInvoiceDetail($pdo,$invoiceId);$inv=$detail['invoice'];$lines=$detail['lines'];$issues=[];$lineResults=[];
    $po=null;$grn=null;
    if(!empty($inv['po_id'])){$po=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_purchase_orders WHERE id=?",[$inv['po_id']]);if(!$po)$issues[]='PO referensi tidak ditemukan.';elseif((string)$po['vendor_id']!==(string)$inv['vendor_id'])$issues[]='Vendor invoice berbeda dari vendor PO.';}
    if(!empty($inv['grn_id'])){$grn=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_goods_receipts WHERE id=?",[$inv['grn_id']]);if(!$grn)$issues[]='GRN referensi tidak ditemukan.';elseif((string)$grn['status']!=='posted')$issues[]='GRN belum posted.';elseif($po&&(string)$grn['po_id']!==(string)$po['id'])$issues[]='GRN bukan milik PO invoice.';}
    foreach($lines as $line){
        $class=strtolower((string)$line['account_class']);$lr=['lineId'=>$line['id'],'itemName'=>$line['item_name'],'accountClass'=>$class,'matched'=>true,'issues'=>[]];
        if($class!=='expense'&&empty($inv['po_id'])){$lr['matched']=false;$lr['issues'][]='Inventory/aset wajib memiliki PO.';}
        if(in_array($class,['inventory','asset'],true)&&empty($inv['grn_id'])){$lr['matched']=false;$lr['issues'][]='Invoice persediaan/aset fisik wajib memiliki GRN posted.';}
        if(!empty($inv['po_id'])){
            if(empty($line['po_item_id'])){$lr['matched']=false;$lr['issues'][]='Line invoice yang memakai PO wajib memiliki poItemId.';}
            else{
                $pi=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_purchase_order_items WHERE id=? AND po_id=?",[$line['po_item_id'],$inv['po_id']]);
                if(!$pi){$lr['matched']=false;$lr['issues'][]='PO item tidak ditemukan pada PO invoice.';}
                else{
                    $iq=round((float)$line['quantity'],3);$pq=round((float)$pi['quantity'],3);if($iq>$pq+0.001){$lr['matched']=false;$lr['issues'][]='Qty invoice melebihi qty PO.';}
                    $ip=round((float)$line['unit_price'],2);$pp=round((float)$pi['unit_price'],2);$tol=0.01;if(abs($ip-$pp)>$tol){$lr['matched']=false;$lr['issues'][]='Harga unit invoice berbeda dari PO (toleransi Rp0,01).';}
                    if(!empty($inv['grn_id'])){
                        $gi=tamasyaEnterpriseFetch($pdo,"SELECT quantity_received FROM growth_goods_receipt_items WHERE grn_id=? AND po_item_id=?",[$inv['grn_id'],$line['po_item_id']]);
                        if(!$gi){$lr['matched']=false;$lr['issues'][]='PO item belum ada pada GRN yang dipilih.';}
                        elseif($iq>(float)$gi['quantity_received']+0.001){$lr['matched']=false;$lr['issues'][]='Qty invoice melebihi qty diterima pada GRN.';}
                    }
                }
            }
        }
        if(!$lr['matched'])$issues=array_merge($issues,array_map(static fn($x)=>(string)$line['item_name'].': '.$x,$lr['issues']));$lineResults[]=$lr;
    }
    $mode=!empty($inv['po_id'])?'three_way': 'direct_expense';
    if($mode==='direct_expense')foreach($lines as $line)if(strtolower((string)$line['account_class'])!=='expense'){$issues[]='Direct invoice hanya boleh accountClass expense.';break;}
    return ['invoiceId'=>$invoiceId,'mode'=>$mode,'poId'=>$inv['po_id']??null,'grnId'=>$inv['grn_id']??null,'canPost'=>count($issues)===0,'issues'=>array_values(array_unique($issues)),'lines'=>$lineResults];
}

function tamasyaEnterpriseRevenueForecast(PDO $pdo,int $days=30): array {
    $days=max(7,min(90,$days));
    $today=new DateTimeImmutable('today');
    $forecastEnd=$today->modify('+'.$days.' days');
    $historyStart=$today->modify('-56 days');
    $historyEnd=$today;
    $rooms=(int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

    // Bulk-read bookings once per window. This avoids one SQL query per forecast day
    // while keeping the calculation transparent and deterministic.
    $fetchWindow=static function(DateTimeImmutable $from,DateTimeImmutable $to) use($pdo): array {
        $stmt=$pdo->prepare("SELECT id,roomNumber,checkIn,checkOut,roomCharge,extraCharge,totalAmount,extras,discountAmount,status,isOpenEnded,actualCheckOutAt,checkoutDueAt,scheduledCheckOutAt,stayMode FROM bookings WHERE status IN ('reserved','active','completed') AND DATE(checkIn)<? AND (DATE(COALESCE(checkOut,DATE_ADD(checkIn,INTERVAL 1 DAY)))>? OR status='active')");
        $stmt->execute([$to->format('Y-m-d'),$from->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    };
    $historyBookings=$fetchWindow($historyStart,$historyEnd);
    $futureBookings=$fetchWindow($today,$forecastEnd);

    $expand=static function(array $bookings,DateTimeImmutable $from,DateTimeImmutable $to): array {
        $daily=[];
        for($d=$from;$d<$to;$d=$d->modify('+1 day'))$daily[$d->format('Y-m-d')]=['rooms'=>[],'revenue'=>0.0];
        foreach($bookings as $b){
            $stay=tamasyaGrowthStayWindow($b,$to);if(!$stay)continue;
            $ci=$stay['from'];$co=$stay['to'];$nights=$stay['nights'];
            $roomRevenue=tamasyaGrowthBookingCharges($b)['roomNet'];
            $perNight=$roomRevenue/$nights;
            $start=$ci>$from?$ci:$from;$stop=$co<$to?$co:$to;
            for($d=$start;$d<$stop;$d=$d->modify('+1 day')){
                $key=$d->format('Y-m-d');if(!isset($daily[$key]))continue;
                $rn=trim((string)($b['roomNumber']??''));if($rn!=='')$daily[$key]['rooms'][$rn]=true;
                $daily[$key]['revenue']+=$perNight;
            }
        }
        return $daily;
    };
    $histDaily=$expand($historyBookings,$historyStart,$historyEnd);
    $futureDaily=$expand($futureBookings,$today,$forecastEnd);

    $weekday=[0=>['days'=>0,'sold'=>0,'revenue'=>0.0],1=>['days'=>0,'sold'=>0,'revenue'=>0.0],2=>['days'=>0,'sold'=>0,'revenue'=>0.0],3=>['days'=>0,'sold'=>0,'revenue'=>0.0],4=>['days'=>0,'sold'=>0,'revenue'=>0.0],5=>['days'=>0,'sold'=>0,'revenue'=>0.0],6=>['days'=>0,'sold'=>0,'revenue'=>0.0]];
    foreach($histDaily as $date=>$r){$w=(int)(new DateTimeImmutable($date))->format('w');$sold=count($r['rooms']);$weekday[$w]['days']++;$weekday[$w]['sold']+=$sold;$weekday[$w]['revenue']+=(float)$r['revenue'];}
    foreach($weekday as $w=>&$v){$v['avgSold']=$v['days']>0?$v['sold']/$v['days']:0.0;$v['adr']=$v['sold']>0?$v['revenue']/$v['sold']:0.0;$v['occupancyPct']=$rooms>0?($v['avgSold']/$rooms*100):0.0;}unset($v);

    $rows=[];$sumRooms=0;$sumRevenue=0.0;
    for($i=0;$i<$days;$i++){
        $date=$today->modify('+'.$i.' days')->format('Y-m-d');$w=(int)(new DateTimeImmutable($date))->format('w');$fd=$futureDaily[$date]??['rooms'=>[],'revenue'=>0.0];
        $onBooks=min($rooms,count($fd['rooms']));$onBooksRevenue=max(0.0,(float)$fd['revenue']);$baseline=min($rooms,(int)round((float)$weekday[$w]['avgSold']));$projected=min($rooms,max($onBooks,$baseline));$refAdr=max(0.0,(float)$weekday[$w]['adr']);$projectedRevenue=max($onBooksRevenue,$projected*$refAdr);
        $rows[]=['date'=>$date,'weekday'=>$w,'rooms'=>$rooms,'onBooksRooms'=>$onBooks,'onBooksOccupancyPct'=>$rooms?round($onBooks/$rooms*100,2):0,'onBooksRevenue'=>round($onBooksRevenue,2),'historicalWeekdayAvgRooms'=>round((float)$weekday[$w]['avgSold'],2),'historicalWeekdayOccupancyPct'=>round((float)$weekday[$w]['occupancyPct'],2),'referenceAdr'=>round($refAdr,2),'projectedRooms'=>$projected,'projectedOccupancyPct'=>$rooms?round($projected/$rooms*100,2):0,'projectedRevenue'=>round($projectedRevenue,2)];
        $sumRooms+=$projected;$sumRevenue+=$projectedRevenue;
    }
    $histSold=array_sum(array_column($weekday,'sold'));$histRevenue=array_sum(array_column($weekday,'revenue'));$histDays=56;
    return ['generatedAt'=>date(DATE_ATOM),'days'=>$days,'rooms'=>$rooms,'historicalReference'=>['from'=>$historyStart->format('Y-m-d'),'to'=>$historyEnd->modify('-1 day')->format('Y-m-d'),'occupancyPct'=>($rooms*$histDays)>0?round($histSold/($rooms*$histDays)*100,2):0,'adr'=>$histSold>0?round($histRevenue/$histSold,2):0,'weekday'=>$weekday],'summary'=>['projectedRoomNights'=>$sumRooms,'projectedRevenue'=>round($sumRevenue,2),'projectedAdr'=>$sumRooms?round($sumRevenue/$sumRooms,2):0,'availableRoomNights'=>$rooms*$days,'projectedOccupancyPct'=>$rooms*$days?round($sumRooms/($rooms*$days)*100,2):0],'daily'=>$rows,'method'=>'Transparent weekday baseline: max(on-books rooms, average occupied rooms for the same weekday over the previous 8 weeks), capped by inventory. Revenue uses max(on-books room revenue, projected rooms × historical same-weekday ADR). Bulk booking reads avoid per-day SQL queries.','warning'=>'Forecast operasional, bukan jaminan pendapatan dan bukan jurnal akuntansi. Durasi terbuka aktif diasumsikan terisi sampai akhir horizon; nilai kamar dialokasikan rata, tanpa menambah tagihan.'];
}

function tamasyaEnterpriseAccountingSummary(PDO $pdo,?string $asOf=null): array {
    $asOf=$asOf?:date('Y-m-d');if(!tamasyaGrowthValidDate($asOf))throw new InvalidArgumentException('Tanggal accounting summary tidak valid.');
    $rows=tamasyaEnterpriseFetchAll($pdo,"SELECT l.account_code,l.account_name,ROUND(SUM(l.debit),2) debit,ROUND(SUM(l.credit),2) credit FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id=e.id WHERE e.status='posted' AND e.entry_date<=? GROUP BY l.account_code,l.account_name ORDER BY l.account_code",[$asOf]);
    $assets=[];$liabilities=[];$equity=[];$income=[];$expenses=[];$tot=['assets'=>0.0,'liabilities'=>0.0,'equity'=>0.0,'income'=>0.0,'expenses'=>0.0];
    foreach($rows as $r){$code=(string)$r['account_code'];$lead=substr($code,0,1);$debit=(float)$r['debit'];$credit=(float)$r['credit'];$normal=$lead==='1'?$debit-$credit:$credit-$debit;$out=$r;$out['balance']=round($normal,2);
        if($lead==='1'){$assets[]=$out;$tot['assets']+=$normal;}elseif($lead==='2'){$liabilities[]=$out;$tot['liabilities']+=$normal;}elseif($lead==='3'){$equity[]=$out;$tot['equity']+=$normal;}elseif($lead==='4'){$income[]=$out;$tot['income']+=$normal;}else{$out['balance']=round($debit-$credit,2);$expenses[]=$out;$tot['expenses']+=$debit-$credit;}}
    $earnings=round($tot['income']-$tot['expenses'],2);$rhs=round($tot['liabilities']+$tot['equity']+$earnings,2);$lhs=round($tot['assets'],2);
    return ['asOf'=>$asOf,'assets'=>$assets,'liabilities'=>$liabilities,'equity'=>$equity,'income'=>$income,'expenses'=>$expenses,'totals'=>array_map(static fn($v)=>round($v,2),$tot)+['currentEarnings'=>$earnings,'assetsTotal'=>$lhs,'liabilitiesEquityAndEarnings'=>$rhs,'balanceDifference'=>round($lhs-$rhs,2)],'source'=>'posted journal_entries/journal_lines; authoritative accounting projection from canonical transactions'];
}

function tamasyaEnterpriseProviderAdapterSelfTest(PDO $pdo,string $id): array {
    $row=tamasyaEnterpriseFetch($pdo,"SELECT id,adapter_type,provider_code,mode,config_json,credential_reference,webhook_verifier,active,version FROM growth_provider_adapters WHERE id=?",[$id]);
    if(!$row)throw new InvalidArgumentException('Provider adapter tidak ditemukan.');
    $cfg=json_decode((string)($row['config_json']??'{}'),true);if(!is_array($cfg))$cfg=[];
    $credRef=trim((string)($row['credential_reference']??''));$credAvailable=false;$credName='';
    if(str_starts_with($credRef,'env:')){$credName=substr($credRef,4);$v=getenv($credName);$credAvailable=$v!==false&&strlen(trim((string)$v))>=8;}
    $verifier=trim((string)($row['webhook_verifier']??''));$supportedVerifier=in_array($verifier,['','none','hmac_sha256','sha256_hmac'],true);
    $active=(int)$row['active']===1;$mode=(string)$row['mode'];
    $readyMetadata=$active&&$mode!=='disabled'&&($credRef===''||$credAvailable)&&$supportedVerifier;
    return ['adapterId'=>$id,'providerCode'=>$row['provider_code'],'adapterType'=>$row['adapter_type'],'mode'=>$mode,'active'=>$active,'credentialReference'=>$credRef,'credentialEnvironmentName'=>$credName,'credentialAvailable'=>$credRef===''?null:$credAvailable,'webhookVerifier'=>$verifier,'supportedVerifier'=>$supportedVerifier,'metadataReady'=>$readyMetadata,'externalMutationEnabled'=>false,'note'=>'Self-test hanya memverifikasi konfigurasi lokal. Tidak menghubungi provider dan tidak menganggap payment/OTA event sah tanpa adapter provider-specific.'];
}


function tamasyaEnterpriseLoyaltyVouchers(PDO $pdo,string $guestProfileId): array {
    return tamasyaEnterpriseFetchAll($pdo,"SELECT * FROM growth_loyalty_vouchers WHERE guest_profile_id=? ORDER BY issued_at DESC LIMIT 200",[$guestProfileId]);
}
function tamasyaEnterpriseIssueVoucher(PDO $pdo,string $guestProfileId,array $actor,string $operationId,array $input): array {
    $account=tamasyaEnterpriseLoyaltyAccount($pdo,$guestProfileId,true);if(!$account)throw new RuntimeException('Loyalty account tidak tersedia.');
    $cons=tamasyaEnterpriseActiveConsent($pdo,$guestProfileId,'loyalty_program');if(!$cons)throw new RuntimeException('Consent loyalty_program aktif diperlukan untuk menerbitkan voucher.');
    $type=strtolower(trim((string)($input['valueType']??'amount')));if(!in_array($type,['amount','percent','benefit'],true))throw new InvalidArgumentException('Tipe voucher tidak valid.');
    $value=max(0,round((float)($input['valueAmount']??0),2));if($type!=='benefit'&&$value<=0)throw new InvalidArgumentException('Nilai voucher wajib lebih dari nol.');if($type==='percent'&&$value>100)throw new InvalidArgumentException('Voucher persen maksimal 100%.');
    $from=trim((string)($input['validFrom']??date('Y-m-d')));$until=trim((string)($input['validUntil']??date('Y-m-d',strtotime('+90 days'))));if(!tamasyaGrowthValidDate($from)||!tamasyaGrowthValidDate($until)||$until<$from)throw new InvalidArgumentException('Masa berlaku voucher tidak valid.');
    $dup=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_loyalty_vouchers WHERE operation_id=? FOR UPDATE",[$operationId]);if($dup)return $dup;
    $id=tamasyaGrowthId('voucher');$code=strtoupper(trim((string)($input['voucherCode']??'')));if($code==='')$code='TV-'.date('ymd').'-'.strtoupper(substr(hash('sha256',$operationId),0,8));if(!preg_match('/^[A-Z0-9_-]{5,80}$/',$code))throw new InvalidArgumentException('Kode voucher tidak valid.');
    $pdo->prepare("INSERT INTO growth_loyalty_vouchers(id,voucher_code,guest_profile_id,value_type,value_amount,min_spend,valid_from,valid_until,status,issued_by,notes,operation_id) VALUES (?,?,?,?,?,?,?,?, 'issued',?,?,?)")
        ->execute([$id,$code,$guestProfileId,$type,$value,max(0,round((float)($input['minSpend']??0),2)),$from,$until,$actor['id']??null,trim((string)($input['notes']??''))?:null,$operationId]);
    return tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_loyalty_vouchers WHERE id=?",[$id])??[];
}
function tamasyaEnterpriseRedeemVoucher(PDO $pdo,string $voucherId,string $bookingId,array $actor,string $operationId,string $reference): array {
    $v=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_loyalty_vouchers WHERE id=? FOR UPDATE",[$voucherId]);if(!$v)throw new InvalidArgumentException('Voucher tidak ditemukan.');if($v['status']==='redeemed')return $v;if($v['status']!=='issued')throw new RuntimeException('Voucher tidak aktif.');$today=date('Y-m-d');if($today<$v['valid_from']||$today>$v['valid_until'])throw new RuntimeException('Voucher di luar masa berlaku.');
    $link=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_guest_booking_links WHERE booking_id=? FOR UPDATE",[$bookingId]);if(!$link||(string)$link['guest_profile_id']!==(string)$v['guest_profile_id'])throw new RuntimeException('Voucher bukan milik guest profile booking ini.');
    $b=tamasyaEnterpriseFetch($pdo,"SELECT id,totalAmount,discountAmount,status FROM bookings WHERE id=? FOR UPDATE",[$bookingId]);if(!$b||!in_array((string)$b['status'],['reserved','active','completed'],true))throw new RuntimeException('Booking tidak eligible untuk redeem voucher.');$total=max(0,(float)$b['totalAmount']);if($total<(float)$v['min_spend'])throw new RuntimeException('Minimum spend voucher belum terpenuhi.');
    $expected=$v['value_type']==='amount'?min($total,(float)$v['value_amount']):($v['value_type']==='percent'?round($total*(float)$v['value_amount']/100,2):0.0);
    if($v['value_type']!=='benefit'&&(float)$b['discountAmount']+0.01<$expected)throw new RuntimeException('Diskon canonical booking belum mencerminkan nilai voucher. Terapkan diskon melalui workflow booking dahulu, lalu redeem voucher sebagai bukti.');
    if(trim($reference)==='')throw new InvalidArgumentException('Reference penerapan voucher wajib untuk audit.');
    $pdo->prepare("UPDATE growth_loyalty_vouchers SET status='redeemed',redeemed_booking_id=?,redemption_value=?,redemption_reference=?,redeemed_by=?,redeemed_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$bookingId,$expected,substr($reference,0,190),$actor['id']??null,$voucherId]);
    return tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_loyalty_vouchers WHERE id=?",[$voucherId])??[];
}
