<?php
/** TAMASYA V137 canonical POS/minibar route. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

$posActions = [
    'pos-bootstrap','pos-products','pos-category-save','pos-product-save','pos-stock-adjust',
    'pos-sale-create','pos-sale-void','pos-sales','pos-report','pos-sale-detail','pos-sale-print-log','pos-delivery-update','pos-deliveries'
];
if (!in_array((string)$action, $posActions, true)) return;
$routeHandled = true;
if ($action === 'pos-report') requireDesktopTabAccess($loggedInStaff, 'report', ['admin','manager','receptionist','finance']);
else requireDesktopTabAccess($loggedInStaff, 'pos', ['admin','manager','receptionist','finance']);
requireRoles($loggedInStaff, ['admin','manager','receptionist','finance']);

$posJson = static function(array $payload, int $status = 200): void {
    http_response_code($status);
    echo tamasyaJsonEncode($payload);
};
$posRole = (string)($loggedInStaff['role'] ?? '');
$posCanManage = in_array($posRole, ['admin','manager'], true);
$posCanAdjust = in_array($posRole, ['admin','manager','finance'], true);
$posCanDiscount = in_array($posRole, ['admin','manager'], true);
$posCanViewCost = in_array($posRole, ['admin','manager','finance','owner'], true);

try {
    tamasyaPosEnsureSchema($pdo);
    $posActorName=tamasyaPosActorDisplayName($pdo,$loggedInStaff);
    if ($action === 'pos-bootstrap' || $action === 'pos-products') {
        $rows=$pdo->query("SELECT p.*,c.name AS category_name FROM pos_products p LEFT JOIN pos_categories c ON c.id=p.category_id ORDER BY p.is_active DESC,c.sort_order,p.name,p.sku")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $products=array_map('tamasyaPosProductRow',$rows);
        foreach($products as &$posProductPreview){
            $previewRule=resolveConfiguredTaxRule($pdo,'POS',(string)$posProductPreview['taxKind'],date('Y-m-d'));
            $posProductPreview['taxResolved']=!empty($previewRule['matched']);
            $posProductPreview['taxRatePreview']=!empty($previewRule['matched']) ? (!empty($previewRule['taxable'])?(float)$previewRule['rate']:0.0) : null;
            $posProductPreview['taxRuleName']=$previewRule['ruleName']??null;
        }
        unset($posProductPreview);
        $products=array_map(static fn(array $product): array => tamasyaPosVisibleProductRow($product,$posCanViewCost),$products);
        if ($action === 'pos-products') {
            $posJson(['success'=>true,'products'=>$products]);
            return;
        }
        $categories=$pdo->query("SELECT id,name,sort_order AS sortOrder,is_active AS isActive,version FROM pos_categories ORDER BY sort_order,name")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $banks=$pdo->query("SELECT id,name AS bankName,accountNumber,accountHolder,type FROM bank_accounts WHERE isActive=1 AND type IN ('bank','edc_qris','edc_card') ORDER BY name,accountNumber")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $recentRows=$pdo->query("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by ORDER BY s.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $today=$pdo->query("SELECT COUNT(*) sale_count,COALESCE(SUM(CASE WHEN status='posted' THEN gross_amount ELSE 0 END),0) gross,
            COALESCE(SUM(CASE WHEN status='posted' THEN discount_amount ELSE 0 END),0) discount,
            COALESCE(SUM(CASE WHEN status='posted' THEN tax_amount ELSE 0 END),0) tax,
            COALESCE(SUM(CASE WHEN status='posted' THEN cost_amount ELSE 0 END),0) cost
            FROM pos_sales WHERE sale_date=CURRENT_DATE")->fetch(PDO::FETCH_ASSOC) ?: [];
        $lowStock=array_values(array_filter($products, static fn(array $p): bool => $p['isActive'] && $p['lowStock']));
        $openDeliveryCount=(int)$pdo->query("SELECT COUNT(*) FROM pos_sales WHERE payment_method='room_charge' AND status='posted' AND delivery_status IN ('created','preparing','out_for_delivery')")->fetchColumn();
        $posJson([
            'success'=>true,'products'=>$products,'categories'=>$categories,'bankAccounts'=>$banks,
            'businessDate'=>date('Y-m-d'),'businessMonthStart'=>date('Y-m-01'),
            'activeBookings'=>tamasyaPosActiveBookings($pdo),'recentSales'=>array_map(static fn(array $sale): array => tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost),$recentRows),
            'summary'=>[
                'saleCount'=>(int)($today['sale_count'] ?? 0),'gross'=>(float)($today['gross'] ?? 0),
                'discount'=>(float)($today['discount'] ?? 0),'tax'=>(float)($today['tax'] ?? 0),'cost'=>$posCanViewCost?(float)($today['cost'] ?? 0):null,
                'grossProfit'=>$posCanViewCost?round((float)($today['gross'] ?? 0)-(float)($today['tax'] ?? 0)-(float)($today['cost'] ?? 0),2):null,
                'lowStockCount'=>count($lowStock),'openDeliveryCount'=>$openDeliveryCount
            ],
            'permissions'=>['manageProducts'=>$posCanManage,'manageCategories'=>$posCanManage,'adjustStock'=>$posCanAdjust,'voidSales'=>$posCanManage,'canDiscount'=>$posCanDiscount,'canViewCost'=>$posCanViewCost,'updateDelivery'=>true]
        ]);
        return;
    }

    if ($action === 'pos-category-save') {
        if (!$posCanManage) throw new RuntimeException('Hanya Manager atau Admin yang dapat mengelola kategori POS.');
        tamasyaPosOperationId($input,'category-save');
        $id=trim((string)($input['id'] ?? ''));
        $name=trim((string)($input['name'] ?? ''));
        $sortOrder=(int)($input['sortOrder'] ?? 0);
        $isActive=!array_key_exists('isActive',$input) || !empty($input['isActive']);
        $nameLength=function_exists('mb_strlen')?mb_strlen($name,'UTF-8'):strlen($name);
        if($nameLength<2 || $nameLength>120) throw new InvalidArgumentException('Nama kategori harus 2 sampai 120 karakter.');
        if($sortOrder<0 || $sortOrder>9999) throw new InvalidArgumentException('Urutan kategori harus antara 0 dan 9999.');
        $pdo->beginTransaction();
        $duplicate=$pdo->prepare("SELECT id FROM pos_categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>? LIMIT 1 FOR UPDATE");
        $duplicate->execute([$name,$id]);
        if($duplicate->fetchColumn()) throw new InvalidArgumentException('Nama kategori POS sudah digunakan.');
        if($id===''){
            $id=generateServerId('poscat');
            $old=null;
            $stmt=$pdo->prepare("INSERT INTO pos_categories(id,name,sort_order,is_active,created_by,updated_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$id,$name,$sortOrder,$isActive?1:0,$loggedInStaff['id'],$loggedInStaff['id']]);
        }else{
            $q=$pdo->prepare("SELECT * FROM pos_categories WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!$old) throw new RuntimeException('Kategori POS tidak ditemukan.');
            $version=(int)($input['version'] ?? 0);
            if($version>0 && $version!==(int)$old['version']) throw new RuntimeException('Kategori telah berubah pada perangkat lain. Muat ulang sebelum menyimpan.');
            if(!$isActive){
                $q=$pdo->prepare("SELECT COUNT(*) FROM pos_products WHERE category_id=? AND is_active=1");$q->execute([$id]);
                $activeProducts=(int)$q->fetchColumn();
                if($activeProducts>0) throw new InvalidArgumentException('Kategori masih dipakai '.$activeProducts.' produk aktif. Pindahkan atau nonaktifkan produk tersebut terlebih dahulu.');
            }
            $stmt=$pdo->prepare("UPDATE pos_categories SET name=?,sort_order=?,is_active=?,version=version+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$name,$sortOrder,$isActive?1:0,$loggedInStaff['id'],$id]);
        }
        $q=$pdo->prepare("SELECT id,name,sort_order AS sortOrder,is_active AS isActive,version,created_at AS createdAt,updated_at AS updatedAt FROM pos_categories WHERE id=? LIMIT 1");$q->execute([$id]);$after=$q->fetch(PDO::FETCH_ASSOC);
        writeEnterpriseAudit($pdo,$loggedInStaff,$old?'Mengubah kategori POS':'Membuat kategori POS','pos_category',$id,$old,$after,'web',['required'=>true]);
        logActivity($pdo,'pos_category',$old?'Mengubah kategori '.$name:'Membuat kategori '.$name,$loggedInStaff['id'],$posActorName);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'category'=>$after]);
        return;
    }

    if ($action === 'pos-product-save') {
        if (!$posCanManage) throw new RuntimeException('Hanya Manager atau Admin yang dapat mengubah master produk.');
        $operationId=tamasyaPosOperationId($input,'product-save');
        $id=trim((string)($input['id'] ?? ''));
        $name=trim((string)($input['name'] ?? ''));
        $sku=strtoupper(trim((string)($input['sku'] ?? '')));
        $categoryId=trim((string)($input['categoryId'] ?? '')) ?: null;
        $unit=trim((string)($input['unit'] ?? 'pcs')) ?: 'pcs';
        $cost=max(0,tamasyaPosNumber($input['costPrice'] ?? 0));
        $price=max(0,tamasyaPosNumber($input['salePrice'] ?? 0));
        $minStock=max(0,tamasyaPosNumber($input['minStock'] ?? 0,3));
        $taxKind=strtolower(trim((string)($input['taxKind'] ?? 'extra'))) ?: 'extra';
        $allowedPosTaxKinds=tamasyaPosTaxProfileKinds();
        if(!in_array($taxKind,$allowedPosTaxKinds,true)) throw new InvalidArgumentException('Profil pajak produk POS tidak didukung. Pilih Extra, Makanan/Minuman, Retail, atau Layanan.');
        $barcode=trim((string)($input['barcode'] ?? '')) ?: null;
        $location=trim((string)($input['location'] ?? 'Front Office / Minibar')) ?: 'Front Office / Minibar';
        $notes=trim((string)($input['notes'] ?? '')) ?: null;
        $isActive=!array_key_exists('isActive',$input) || !empty($input['isActive']);
        if ($name==='' || $sku==='') throw new InvalidArgumentException('SKU dan nama produk wajib diisi.');
        if (!preg_match('/^[A-Z0-9._-]{2,80}$/',$sku)) throw new InvalidArgumentException('SKU hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda minus.');
        if ($price < 0 || $cost < 0) throw new InvalidArgumentException('Harga tidak valid.');
        if ($categoryId) {
            $q=$pdo->prepare("SELECT id FROM pos_categories WHERE id=? AND is_active=1 LIMIT 1");$q->execute([$categoryId]);
            if(!$q->fetchColumn()) throw new InvalidArgumentException('Kategori produk tidak aktif atau tidak ditemukan.');
        }
        $pdo->beginTransaction();
        if ($id==='') {
            $id=generateServerId('posprod');
            $initial=max(0,tamasyaPosNumber($input['initialStock'] ?? 0,3));
            $stmt=$pdo->prepare("INSERT INTO pos_products
                (id,sku,name,category_id,unit,cost_price,sale_price,stock_quantity,min_stock,tax_kind,barcode,location,notes,is_active,created_by,updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$id,$sku,$name,$categoryId,$unit,$cost,$price,$initial,$minStock,$taxKind,$barcode,$location,$notes,$isActive?1:0,$loggedInStaff['id'],$loggedInStaff['id']]);
            if($initial>0){
                $pdo->prepare("INSERT INTO pos_stock_movements
                    (id,product_id,sale_id,movement_type,quantity_delta,quantity_before,quantity_after,unit_cost,reference,reason,operation_id,created_by,created_by_name)
                    VALUES (?,?,NULL,'initial',?,0,?,?,?,?,?,?,?)")
                    ->execute([generateServerId('posmov'),$id,$initial,$initial,$cost,'Saldo awal produk','Stok awal saat produk dibuat',$operationId,$loggedInStaff['id'],$posActorName]);
            }
            $old=null;
        } else {
            $q=$pdo->prepare("SELECT * FROM pos_products WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!$old) throw new RuntimeException('Produk tidak ditemukan.');
            $version=(int)($input['version'] ?? 0);
            if($version>0 && $version!==(int)$old['version']) throw new DomainException('Produk telah berubah pada perangkat lain. Muat ulang sebelum menyimpan.');
            $stmt=$pdo->prepare("UPDATE pos_products SET sku=?,name=?,category_id=?,unit=?,cost_price=?,sale_price=?,min_stock=?,tax_kind=?,barcode=?,location=?,notes=?,is_active=?,version=version+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$sku,$name,$categoryId,$unit,$cost,$price,$minStock,$taxKind,$barcode,$location,$notes,$isActive?1:0,$loggedInStaff['id'],$id]);
        }
        $q=$pdo->prepare("SELECT p.*,c.name category_name FROM pos_products p LEFT JOIN pos_categories c ON c.id=p.category_id WHERE p.id=?");$q->execute([$id]);$after=$q->fetch(PDO::FETCH_ASSOC);
        writeEnterpriseAudit($pdo,$loggedInStaff,$old?'Mengubah produk POS':'Membuat produk POS','pos_product',$id,$old,$after,'web',['required'=>true]);
        logActivity($pdo,'pos_product',$old?'Mengubah produk '.$name:'Membuat produk '.$name,$loggedInStaff['id'],$posActorName);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'product'=>tamasyaPosProductRow($after)]);
        return;
    }

    if ($action === 'pos-stock-adjust') {
        if (!$posCanAdjust) throw new RuntimeException('Hanya Finance, Manager, atau Admin yang dapat menyesuaikan stok.');
        $operationId=tamasyaPosOperationId($input,'stock-adjust');
        $productId=trim((string)($input['productId'] ?? ''));
        $delta=tamasyaPosNumber($input['quantityDelta'] ?? 0,3);
        $reason=trim((string)($input['reason'] ?? ''));
        if($productId==='' || abs($delta)<0.0001 || $reason==='') throw new InvalidArgumentException('Produk, perubahan jumlah, dan alasan wajib diisi.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT * FROM pos_products WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if(!$p) throw new RuntimeException('Produk tidak ditemukan.');
        $before=(float)$p['stock_quantity'];$after=round($before+$delta,3);
        if($after<0) throw new RuntimeException('Stok tidak boleh menjadi negatif.');
        $pdo->prepare("UPDATE pos_products SET stock_quantity=?,version=version+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$after,$loggedInStaff['id'],$productId]);
        $pdo->prepare("INSERT INTO pos_stock_movements
            (id,product_id,sale_id,movement_type,quantity_delta,quantity_before,quantity_after,unit_cost,reference,reason,operation_id,created_by,created_by_name)
            VALUES (?,?,NULL,'adjustment',?,?,?,?,?,?,?, ?,?)")
            ->execute([generateServerId('posmov'),$productId,$delta,$before,$after,(float)$p['cost_price'],'Penyesuaian stok',$reason,$operationId,$loggedInStaff['id'],$posActorName]);
        writeEnterpriseAudit($pdo,$loggedInStaff,'Menyesuaikan stok POS','pos_product',$productId,['stockQuantity'=>$before],['stockQuantity'=>$after,'delta'=>$delta,'reason'=>$reason],'web',['required'=>true]);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'productId'=>$productId,'stockQuantity'=>$after]);
        return;
    }

    if ($action === 'pos-sale-create') {
        $operationId=tamasyaPosOperationId($input,'sale');
        $paymentMethod=strtolower(trim((string)($input['paymentMethod'] ?? 'cash')));
        if(!in_array($paymentMethod,['cash','qris','transfer','card','room_charge'],true)) throw new InvalidArgumentException('Metode pembayaran POS tidak valid.');
        $saleDate=(string)($input['saleDate'] ?? date('Y-m-d'));
        if(!validIsoDate($saleDate)) throw new InvalidArgumentException('Tanggal penjualan tidak valid.');
        $rawItems=is_array($input['items'] ?? null)?$input['items']:[];
        if(!$rawItems || count($rawItems)>100) throw new InvalidArgumentException('Keranjang POS wajib berisi 1 sampai 100 produk.');
        $normalized=[];
        foreach($rawItems as $item){
            if(!is_array($item))continue;
            $pid=trim((string)($item['productId'] ?? ''));$qty=tamasyaPosNumber($item['quantity'] ?? 0,3);
            if($pid===''||$qty<=0)continue;
            $normalized[$pid]=round(($normalized[$pid]??0)+$qty,3);
        }
        if(!$normalized) throw new InvalidArgumentException('Jumlah produk dalam keranjang tidak valid.');
        ksort($normalized,SORT_STRING);

        $pdo->beginTransaction();
        $dup=$pdo->prepare("SELECT * FROM pos_sales WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$operationId]);
        if($existing=$dup->fetch(PDO::FETCH_ASSOC)){
            tamasyaFinancialCommit($pdo);$posJson(['success'=>true,'duplicate'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($existing),$posCanViewCost)]);return;
        }
        $bankAccountId=tamasyaPosPaymentBank($pdo,$paymentMethod,$input['bankAccountId'] ?? null);
        $posSaleShift=$paymentMethod==='room_charge'?null:tamasyaPosRequireOpenShift($pdo,$loggedInStaff,$paymentMethod==='cash');
        $posSaleShiftSessionId=$posSaleShift?(string)$posSaleShift['id']:null;
        $booking=null;$bookingId=null;$bookingExtraId=null;$roomNumber=null;$guestName=null;
        if($paymentMethod==='room_charge'){
            $bookingId=trim((string)($input['bookingId'] ?? ''));
            if($bookingId==='') throw new InvalidArgumentException('Booking aktif wajib dipilih untuk charge ke kamar.');
            $q=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$bookingId]);$booking=$q->fetch(PDO::FETCH_ASSOC);
            if(!$booking || strtolower((string)$booking['status'])!=='active') throw new RuntimeException('Booking tidak aktif atau tidak ditemukan.');
            $roomNumber=trim((string)$booking['roomNumber']);$guestName=trim((string)$booking['guestName']);
            if($roomNumber==='') throw new RuntimeException('Booking aktif tidak memiliki nomor kamar yang valid.');
            $roomCheck=$pdo->prepare("SELECT number,status FROM rooms WHERE number=? LIMIT 1");$roomCheck->execute([$roomNumber]);$roomRow=$roomCheck->fetch(PDO::FETCH_ASSOC);
            if(!$roomRow) throw new RuntimeException('Data kamar booking tidak ditemukan pada master kamar. Sinkronkan data kamar terlebih dahulu.');
        }

        $saleId=generateServerId('possale');$receipt=nextDocumentNumber($pdo,'POS',$saleDate);
        $lines=[];$subtotal=0.0;$gross=0.0;$base=0.0;$tax=0.0;$cost=0.0;$itemCount=0;$ruleIds=[];
        foreach($normalized as $productId=>$qty){
            $q=$pdo->prepare("SELECT p.*,c.name category_name FROM pos_products p LEFT JOIN pos_categories c ON c.id=p.category_id WHERE p.id=? LIMIT 1 FOR UPDATE");$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p || !(int)$p['is_active']) throw new RuntimeException('Produk tidak aktif atau tidak ditemukan: '.$productId);
            $available=(float)$p['stock_quantity'];
            if($qty>$available+0.000001) throw new DomainException('Stok '.$p['name'].' tidak cukup. Tersedia '.$available.' '.$p['unit'].'.');
            $lineOriginalGross=round((float)$p['sale_price']*$qty,2);
            if($lineOriginalGross<=0) throw new RuntimeException('Harga jual '.$p['name'].' belum diisi.');
            $rule=resolveConfiguredTaxRule($pdo,'POS',(string)$p['tax_kind'],$saleDate);
            if(empty($rule['matched'])) throw new RuntimeException('Produk '.$p['name'].' belum mempunyai Aturan Pajak aktif untuk tax kind '.$p['tax_kind'].' pada '.$saleDate.'. Buat rule eksplisit termasuk 0%/tidak kena pajak sebelum penjualan live.');
            $rate=!empty($rule['taxable'])?(float)$rule['rate']:0.0;
            $lineCost=round((float)$p['cost_price']*$qty,2);
            $lines[]=['product'=>$p,'qty'=>$qty,'originalGross'=>$lineOriginalGross,'discount'=>0.0,'gross'=>$lineOriginalGross,'base'=>0.0,'tax'=>0.0,'rate'=>$rate,'rule'=>$rule,'cost'=>$lineCost];
            $subtotal=round($subtotal+$lineOriginalGross,2);$cost=round($cost+$lineCost,2);$itemCount++;
            if(!empty($rule['ruleId']))$ruleIds[(string)$rule['ruleId']]=true;
        }
        $promoCodeId = null;
        $promoCodeInput = trim((string)($input['promoCode'] ?? ''));
        $manualDiscountProvided = !empty($input['discountAmount']) || !empty($input['discount'])
            || !empty($input['manualDiscount']) || (isset($input['discountValue']) && (float)$input['discountValue'] > 0)
            || (isset($input['discountType']) && strtolower(trim((string)$input['discountType'])) !== '' && strtolower(trim((string)$input['discountType'])) !== 'none')
            || !empty($input['discountReason']);
        if ($promoCodeInput === '' && !$manualDiscountProvided) {
            $autoPromo = tamasyaPromotionCodeAutoApply($pdo, 'pos', $subtotal, $saleDate);
            if (is_array($autoPromo) && !empty($autoPromo)) {
                $promoCodeInput = trim((string)($autoPromo['code'] ?? $autoPromo['promotionCode']['code'] ?? ''));
            }
        }
        if ($promoCodeInput !== '') {
            if (!tamasyaPromotionCodesEnabled() || !tamasyaPromotionCodesSchemaReady($pdo)) {
                throw new RuntimeException('Fitur kode promo dinonaktifkan atau schema belum siap.');
            }
            $promoResult = tamasyaPromotionCodeValidate($pdo, $promoCodeInput, 'pos', $subtotal);
            $input['discountType'] = $promoResult['discountType'] === 'percent' ? 'percentage' : 'fixed';
            $input['discountValue'] = $promoResult['discountValue'];
            $input['discountReason'] = 'Kode promo ' . $promoResult['promotionCode']['code'];
            $promoCodeId = (string)$promoResult['promotionCode']['id'];
        }
        $discount=tamasyaPosDiscountPlan(
            $subtotal,
            (string)($input['discountType'] ?? 'none'),
            tamasyaPosNumber($input['discountValue'] ?? 0,3),
            isset($input['discountReason'])?(string)$input['discountReason']:null,
            $posCanDiscount
        );
        $discountAllocations=tamasyaPosAllocateDiscount(array_column($lines,'originalGross'),(float)$discount['amount']);
        foreach($lines as $index=>&$line){
            $allocation=$discountAllocations[$index];
            $line['discount']=$allocation;$line['gross']=round((float)$line['originalGross']-$allocation,2);
            $split=calculateInclusiveTaxBreakdown((float)$line['gross'],(float)$line['rate']);
            $line['base']=$split['baseAmount'];$line['tax']=$split['taxAmount'];$line['rate']=$split['taxRate'];
            $gross=round($gross+(float)$line['gross'],2);$base=round($base+(float)$line['base'],2);$tax=round($tax+(float)$line['tax'],2);
        }
        unset($line);
        if(abs($gross-($subtotal-(float)$discount['amount']))>0.01 || abs($gross-$base-$tax)>0.01) throw new RuntimeException('Perhitungan total/diskon POS tidak seimbang.');
        $deliveryStatus=$paymentMethod==='room_charge'?'created':'not_required';
        $deliveryNote=trim((string)($input['deliveryNote'] ?? ($input['notes'] ?? ''))) ?: null;
        $stmt=$pdo->prepare("INSERT INTO pos_sales
            (id,receipt_number,status,payment_method,bank_account_id,booking_id,room_number,guest_name,subtotal_amount,discount_type,discount_value,discount_amount,discount_reason,discount_authorized_by,discount_authorized_by_name,gross_amount,base_amount,tax_amount,cost_amount,item_count,operation_id,notes,delivery_status,delivery_note,delivery_updated_at,sale_date,created_by,created_by_name)
            VALUES (?,?,'posted',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?,?)");
        $stmt->execute([$saleId,$receipt,$paymentMethod,$bankAccountId,$bookingId,$roomNumber,$guestName,$subtotal,$discount['type'],$discount['value'],$discount['amount'],$discount['reason'],$discount['amount']>0?$loggedInStaff['id']:null,$discount['amount']>0?($posActorName):null,$gross,$base,$tax,$cost,$itemCount,$operationId,trim((string)($input['notes']??''))?:null,$deliveryStatus,$deliveryNote,$saleDate,$loggedInStaff['id'],$posActorName]);
        if($paymentMethod==='room_charge') {
            $pdo->prepare("INSERT INTO pos_delivery_events(id,sale_id,from_status,to_status,note,received_by_name,operation_id,acted_by,acted_by_name) VALUES (?,?,'not_required','created',?,NULL,?,?,?)")
                ->execute([generateServerId('posdel'),$saleId,$deliveryNote,$operationId.':delivery-created',$loggedInStaff['id'],$posActorName]);
        }

        $itemInsert=$pdo->prepare("INSERT INTO pos_sale_items
            (id,sale_id,product_id,sku,product_name,quantity,unit,unit_price,unit_cost,original_gross_amount,discount_amount,gross_amount,base_amount,tax_amount,tax_rate,tax_rule_id,tax_kind)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $productUpdate=$pdo->prepare("UPDATE pos_products SET stock_quantity=?,version=version+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $movement=$pdo->prepare("INSERT INTO pos_stock_movements
            (id,product_id,sale_id,movement_type,quantity_delta,quantity_before,quantity_after,unit_cost,reference,reason,operation_id,created_by,created_by_name)
            VALUES (?,?,?,'sale',?,?,?,?,?,?,?,?,?)");
        $summary=[];
        foreach($lines as $index=>$line){
            $p=$line['product'];$before=(float)$p['stock_quantity'];$after=round($before-$line['qty'],3);
            $itemInsert->execute([generateServerId('positem'),$saleId,$p['id'],$p['sku'],$p['name'],$line['qty'],$p['unit'],$p['sale_price'],$p['cost_price'],$line['originalGross'],$line['discount'],$line['gross'],$line['base'],$line['tax'],$line['rate'],$line['rule']['ruleId']??null,$p['tax_kind']]);
            $productUpdate->execute([$after,$loggedInStaff['id'],$p['id']]);
            $movement->execute([generateServerId('posmov'),$p['id'],$saleId,-$line['qty'],$before,$after,$p['cost_price'],$receipt,'Penjualan POS '.$receipt,$operationId.':item:'.($index+1),$loggedInStaff['id'],$posActorName]);
            $summary[]=$p['name'].' x'.rtrim(rtrim(number_format($line['qty'],3,'.',''),'0'),'.');
        }

        $effectiveRate=$base>0?round(($tax/$base)*100,3):0.0;
        // FIX17: POS accounting behavior is fixed by semantic role, while the
        // user-visible category label is property-owned master data. Never fall
        // back to a hardcoded category name when a binding is missing.
        $posRevenueCategory=tamasyaRequireSystemFinanceCategory($pdo,'pos_revenue','income',true);
        $posCogsCategory=$cost>0?tamasyaRequireSystemFinanceCategory($pdo,'pos_cogs','expense',true):null;
        $transactionId=null;$cogsTransactionId=null;
        if($paymentMethod==='room_charge'){
            $bookingExtraId=generateServerId('ex_pos');
            $extras=tamasyaDecodeBookingExtras($booking['extras'] ?? null);
            $extras[]=[
                'id'=>$bookingExtraId,'name'=>'POS / Minibar '.$receipt,'price'=>$gross,'qty'=>1,'total'=>$gross,
                'subtotalAmount'=>$subtotal,'discountType'=>$discount['type'],'discountValue'=>$discount['value'],'discountAmount'=>$discount['amount'],'discountReason'=>$discount['reason'],
                'baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>$effectiveRate,
                'category'=>(string)$posRevenueCategory['name'],'categoryId'=>(string)$posRevenueCategory['id'],'categorySystemKey'=>'pos_revenue',
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,
                'categoryLabelSnapshot'=>(string)$posRevenueCategory['name'],'itemSummary'=>implode(', ',$summary),
                'createdAt'=>date('c'),'paymentStatus'=>'unpaid','posSaleId'=>$saleId,'receiptNumber'=>$receipt
            ];
            $oldGross=max(0,(float)$booking['totalAmount']);$oldVat=max(0,(float)($booking['vatAmount']??0));
            $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
            $newRate=$oldGross<=0.0001?$effectiveRate:(($oldRate!==null&&abs($oldRate-$effectiveRate)<0.0001)?$oldRate:null);
            $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=?,vatAmount=?,vatRate=?,paymentStatus=IF(paymentStatus='paid','partial',paymentStatus),version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource='pos' WHERE id=?")
                ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),round($oldGross+$gross,2),round($oldVat+$tax,2),$newRate,$loggedInStaff['id'],$bookingId]);
            recalculateBookingFinancials($pdo,$bookingId,true);
        } else {
            $transactionId=tamasyaPosInsertTransaction($pdo,[
                'type'=>'income','category'=>(string)$posRevenueCategory['name'],'categoryId'=>(string)$posRevenueCategory['id'],'categorySystemKey'=>'pos_revenue',
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'roomNumber'=>null,
                'amount'=>$gross,'baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>$effectiveRate,
                'taxRuleId'=>count($ruleIds)===1?array_key_first($ruleIds):null,'taxSource'=>'pos_item_snapshot',
                'taxNote'=>'Snapshot per item POS; aturan pajak dipilih dari menu Pajak sesuai tax_kind produk.',
                'description'=>'Penjualan POS '.$receipt.($discount['amount']>0?' (diskon Rp '.number_format($discount['amount'],0,',','.').')':'').' - '.implode(', ',$summary),'bankAccountId'=>$bankAccountId,
                'bookingSource'=>'POS','transactionKind'=>'pos_sale','sourceEntityId'=>$saleId,
                'operationId'=>$operationId.':income','date'=>$saleDate,'documentNumber'=>$receipt,
                'shiftSessionId'=>$posSaleShiftSessionId
            ],$loggedInStaff);
            if($posSaleShiftSessionId!==null) attachTransactionToActorShift($pdo,$transactionId,$loggedInStaff,$paymentMethod==='cash');
        }
        if($cost>0){
            $cogsTransactionId=tamasyaPosInsertTransaction($pdo,[
                'type'=>'expense','category'=>(string)$posCogsCategory['name'],'categoryId'=>(string)$posCogsCategory['id'],'categorySystemKey'=>'pos_cogs',
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'amount'=>$cost,
                'baseAmount'=>$cost,'taxAmount'=>0,'taxRate'=>0,'description'=>'HPP penjualan POS '.$receipt,
                'bankAccountId'=>'inventory_asset','bookingId'=>$bookingId,'bookingSource'=>'POS','transactionKind'=>'pos_cogs',
                'sourceEntityId'=>$saleId,'operationId'=>$operationId.':cogs','date'=>$saleDate,'documentPrefix'=>'COGS',
                'shiftExempt'=>true,'shiftExemptionReason'=>'Jurnal non-kas harga pokok penjualan POS'
            ],$loggedInStaff);
        }
        $pdo->prepare("UPDATE pos_sales SET transaction_id=?,cogs_transaction_id=?,booking_extra_id=?,version=version+1 WHERE id=?")
            ->execute([$transactionId,$cogsTransactionId,$bookingExtraId,$saleId]);
        if ($promoCodeId !== null) {
            tamasyaPromotionCodeMarkUsed($pdo, $promoCodeId, $operationId . ':pos', $loggedInStaff, $subtotal, 'pos', ['discountAmount' => $discount['amount']]);
        }
        syncJournalProjections($pdo,true);
        writeEnterpriseAudit($pdo,$loggedInStaff,'Mencatat penjualan POS','pos_sale',$saleId,null,['receiptNumber'=>$receipt,'subtotalAmount'=>$subtotal,'discountType'=>$discount['type'],'discountValue'=>$discount['value'],'discountAmount'=>$discount['amount'],'discountReason'=>$discount['reason'],'grossAmount'=>$gross,'taxAmount'=>$tax,'costAmount'=>$cost,'paymentMethod'=>$paymentMethod,'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'items'=>$summary],'web',['required'=>true]);
        logActivity($pdo,'pos_sale','Penjualan POS '.$receipt.' Rp '.number_format($gross,0,',','.').($discount['amount']>0?' setelah diskon Rp '.number_format($discount['amount'],0,',','.'):''),$loggedInStaff['id'],$posActorName);
        $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=?");$q->execute([$saleId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost),'items'=>$summary]);
        return;
    }

    if ($action === 'pos-sale-void') {
        if(!$posCanManage) throw new RuntimeException('Pembatalan penjualan hanya dapat dilakukan Manager atau Admin.');
        $operationId=tamasyaPosOperationId($input,'void');
        $saleId=trim((string)($input['saleId'] ?? ''));$reason=trim((string)($input['reason'] ?? ''));
        if($saleId===''||strlen($reason)<5) throw new InvalidArgumentException('Sale ID dan alasan pembatalan minimal 5 karakter wajib diisi.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT * FROM pos_sales WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$saleId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$sale) throw new RuntimeException('Penjualan POS tidak ditemukan.');
        if($sale['status']==='voided'){tamasyaFinancialCommit($pdo);$posJson(['success'=>true,'duplicate'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost)]);return;}
        if($sale['status']!=='posted') throw new RuntimeException('Status penjualan tidak dapat dibatalkan.');
        $itemsQ=$pdo->prepare("SELECT i.*,p.location FROM pos_sale_items i LEFT JOIN pos_products p ON p.id=i.product_id WHERE i.sale_id=? ORDER BY i.id FOR UPDATE");$itemsQ->execute([$saleId]);$items=$itemsQ->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($items as $index=>$item){
            $pQ=$pdo->prepare("SELECT * FROM pos_products WHERE id=? LIMIT 1 FOR UPDATE");$pQ->execute([$item['product_id']]);$p=$pQ->fetch(PDO::FETCH_ASSOC);
            if(!$p) throw new RuntimeException('Produk penjualan tidak ditemukan untuk pemulihan stok.');
            $before=(float)$p['stock_quantity'];$after=round($before+(float)$item['quantity'],3);
            $pdo->prepare("UPDATE pos_products SET stock_quantity=?,version=version+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$after,$loggedInStaff['id'],$p['id']]);
            $pdo->prepare("INSERT INTO pos_stock_movements
                (id,product_id,sale_id,movement_type,quantity_delta,quantity_before,quantity_after,unit_cost,reference,reason,operation_id,created_by,created_by_name)
                VALUES (?,?,?,'sale_void',?,?,?,?,?,?,?,?,?)")
                ->execute([generateServerId('posmov'),$p['id'],$saleId,(float)$item['quantity'],$before,$after,(float)$item['unit_cost'],$sale['receipt_number'],$reason,$operationId.':item:'.($index+1),$loggedInStaff['id'],$posActorName]);
        }
        if($sale['payment_method']==='room_charge'){
            $bookingId=(string)$sale['booking_id'];
            $bQ=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$bQ->execute([$bookingId]);$booking=$bQ->fetch(PDO::FETCH_ASSOC);
            if(!$booking) throw new RuntimeException('Booking charge tidak ditemukan.');
            $extras=tamasyaDecodeBookingExtras($booking['extras']??null);$found=false;
            foreach($extras as $extra){
                if((string)($extra['id']??'')!==(string)$sale['booking_extra_id'])continue;
                if(!empty($extra['sourceTransactionId'])||in_array(strtolower((string)($extra['paymentStatus']??'unpaid')),['paid','partial'],true)) throw new RuntimeException('Charge POS sudah dibayar/sebagian dibayar. Gunakan prosedur refund keuangan, bukan void POS.');
                $found=true;
            }
            if(!$found) throw new RuntimeException('Item charge POS tidak ditemukan pada folio booking.');
            $extras=array_values(array_filter($extras,static fn(array $e):bool=>(string)($e['id']??'')!==(string)$sale['booking_extra_id']));
            $newTotal=max(0,round((float)$booking['totalAmount']-(float)$sale['gross_amount'],2));
            $newVat=max(0,round((float)($booking['vatAmount']??0)-(float)$sale['tax_amount'],2));
            $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=?,vatAmount=?,vatRate=NULL,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource='pos-void' WHERE id=?")
                ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$newTotal,$newVat,$loggedInStaff['id'],$bookingId]);
            recalculateBookingFinancials($pdo,$bookingId,true);
        }else{
            $refundRequiresShift=tamasyaManualTransactionRequiresOpenShift(['type'=>'expense','amount'=>(float)$sale['gross_amount'],'bankAccountId'=>$sale['bank_account_id']]);
            $posRefundShift=tamasyaPosRequireOpenShift($pdo,$loggedInStaff,$refundRequiresShift);
            $posRefundShiftSessionId=$posRefundShift?(string)$posRefundShift['id']:null;
            $posRefundCategory=tamasyaRequireSystemFinanceCategory($pdo,'pos_refund','expense',true);
            $refundId=tamasyaPosInsertTransaction($pdo,[
                'type'=>'expense','category'=>(string)$posRefundCategory['name'],'categoryId'=>(string)$posRefundCategory['id'],'categorySystemKey'=>'pos_refund',
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'amount'=>(float)$sale['gross_amount'],
                'baseAmount'=>(float)$sale['base_amount'],'taxAmount'=>(float)$sale['tax_amount'],
                'taxRate'=>(float)$sale['base_amount']>0?round(((float)$sale['tax_amount']/(float)$sale['base_amount'])*100,3):0,
                'description'=>'Pembatalan '.$sale['receipt_number'].' - '.$reason,'bankAccountId'=>$sale['bank_account_id'],
                'bookingSource'=>'POS','transactionKind'=>'pos_refund','sourceEntityId'=>$saleId,
                'operationId'=>$operationId.':refund','date'=>($sale['sale_date'] ?? date('Y-m-d')),'documentPrefix'=>'REF',
                'shiftSessionId'=>$posRefundShiftSessionId
            ],$loggedInStaff);
            if($posRefundShiftSessionId!==null) attachTransactionToActorShift($pdo,$refundId,$loggedInStaff,$refundRequiresShift);
        }
        if((float)$sale['cost_amount']>0){
            $posCogsReversalCategory=tamasyaRequireSystemFinanceCategory($pdo,'pos_cogs_reversal','income',true);
            $reversal=tamasyaPosInsertTransaction($pdo,[
                'type'=>'income','category'=>(string)$posCogsReversalCategory['name'],'categoryId'=>(string)$posCogsReversalCategory['id'],'categorySystemKey'=>'pos_cogs_reversal',
                'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'amount'=>(float)$sale['cost_amount'],
                'baseAmount'=>(float)$sale['cost_amount'],'taxAmount'=>0,'taxRate'=>0,'description'=>'Pemulihan HPP '.$sale['receipt_number'],
                'bankAccountId'=>'inventory_asset','bookingId'=>$sale['booking_id'],'bookingSource'=>'POS','transactionKind'=>'pos_cogs_reversal',
                'sourceEntityId'=>$saleId,'operationId'=>$operationId.':cogs-reversal','date'=>($sale['sale_date'] ?? date('Y-m-d')),'documentPrefix'=>'COGR',
                'shiftExempt'=>true,'shiftExemptionReason'=>'Jurnal non-kas pembalik harga pokok penjualan POS'
            ],$loggedInStaff);
        }
        $pdo->prepare("UPDATE pos_sales SET status='voided',delivery_status=IF(payment_method='room_charge','cancelled',delivery_status),delivery_updated_at=IF(payment_method='room_charge',CURRENT_TIMESTAMP,delivery_updated_at),voided_by=?,voided_by_name=?,void_reason=?,voided_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?")
            ->execute([$loggedInStaff['id'],$posActorName,$reason,$saleId]);
        if($sale['payment_method']==='room_charge') {
            $pdo->prepare("INSERT INTO pos_delivery_events(id,sale_id,from_status,to_status,note,received_by_name,operation_id,acted_by,acted_by_name) VALUES (?,?,?,'cancelled',?,NULL,?,?,?)")
                ->execute([generateServerId('posdel'),$saleId,(string)($sale['delivery_status']??'created'),$reason,$operationId.':delivery-cancelled',$loggedInStaff['id'],$posActorName]);
        }
        syncJournalProjections($pdo,true);
        writeEnterpriseAudit($pdo,$loggedInStaff,'Membatalkan penjualan POS','pos_sale',$saleId,tamasyaPosSaleRow($sale),['status'=>'voided','reason'=>$reason],'web',['required'=>true]);
        $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=?");$q->execute([$saleId]);$after=$q->fetch(PDO::FETCH_ASSOC);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($after),$posCanViewCost)]);
        return;
    }

    if ($action === 'pos-sale-print-log') {
        $operationId=tamasyaPosOperationId($input,'print');
        $saleId=trim((string)($input['saleId'] ?? ''));
        $printType=strtolower(trim((string)($input['printType'] ?? 'customer_receipt')));
        $paperWidth=(int)($input['paperWidth'] ?? 80);
        $copies=(int)($input['copies'] ?? 1);
        if($printType==='dual_copy')$copies=2;
        $allowedTypes=['customer_receipt','archive_copy','dual_copy','picking_slip','room_delivery'];
        if($saleId===''||!in_array($printType,$allowedTypes,true)) throw new InvalidArgumentException('Jenis cetak atau penjualan tidak valid.');
        if(!in_array($paperWidth,[58,80],true)) throw new InvalidArgumentException('Lebar kertas termal harus 58 mm atau 80 mm.');
        if(!in_array($copies,[1,2],true)) throw new InvalidArgumentException('Jumlah salinan harus 1 atau 2.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT * FROM pos_sales WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$saleId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$sale) throw new RuntimeException('Penjualan POS tidak ditemukan.');
        if(in_array($printType,['picking_slip','room_delivery'],true)&&$sale['payment_method']!=='room_charge') throw new InvalidArgumentException('Slip kamar hanya tersedia untuk charge ke kamar.');
        $dup=$pdo->prepare("SELECT * FROM pos_print_logs WHERE operation_id=? LIMIT 1");$dup->execute([$operationId]);
        if($existing=$dup->fetch(PDO::FETCH_ASSOC)){
            tamasyaFinancialCommit($pdo);$posJson(['success'=>true,'duplicate'=>true,'copyLabel'=>$existing['copy_label'],'printCount'=>(int)$sale['print_count'],'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost)]);return;
        }
        $priorStmt=$pdo->prepare("SELECT COALESCE(SUM(requested_copies),0) FROM pos_print_logs WHERE sale_id=? AND print_type=?");$priorStmt->execute([$saleId,$printType]);
        $prior=(int)$priorStmt->fetchColumn();$copyLabel=$prior>0?'COPY':'ORIGINAL';
        $pdo->prepare("INSERT INTO pos_print_logs(id,sale_id,print_type,paper_width,requested_copies,copy_label,operation_id,printed_by,printed_by_name) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([generateServerId('posprint'),$saleId,$printType,$paperWidth,$copies,$copyLabel,$operationId,$loggedInStaff['id'],$posActorName]);
        $pdo->prepare("UPDATE pos_sales SET print_count=print_count+?,last_printed_at=CURRENT_TIMESTAMP,last_printed_by=?,last_printed_by_name=?,version=version+1 WHERE id=?")
            ->execute([$copies,$loggedInStaff['id'],$posActorName,$saleId]);
        writeEnterpriseAudit($pdo,$loggedInStaff,'Mencetak dokumen POS','pos_sale',$saleId,['printCount'=>$prior],['printType'=>$printType,'paperWidth'=>$paperWidth,'copies'=>$copies,'copyLabel'=>$copyLabel],'web',['required'=>true]);
        $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=?");$q->execute([$saleId]);$after=$q->fetch(PDO::FETCH_ASSOC);
        tamasyaFinancialCommit($pdo);
        $posJson(['success'=>true,'copyLabel'=>$copyLabel,'isReprint'=>$prior>0,'printCount'=>(int)$after['print_count'],'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($after),$posCanViewCost)]);return;
    }

    if ($action === 'pos-delivery-update') {
        $operationId=tamasyaPosOperationId($input,'delivery');
        $saleId=trim((string)($input['saleId'] ?? ''));
        $target=strtolower(trim((string)($input['status'] ?? '')));
        $note=trim((string)($input['note'] ?? '')) ?: null;
        $receivedBy=trim((string)($input['receivedByName'] ?? '')) ?: null;
        if($saleId===''||!in_array($target,['preparing','out_for_delivery','delivered'],true)) throw new InvalidArgumentException('Status pengantaran tidak valid.');
        if($target==='delivered'&&$receivedBy===null) throw new InvalidArgumentException('Nama penerima wajib diisi saat barang diterima.');
        $pdo->beginTransaction();
        $dup=$pdo->prepare("SELECT * FROM pos_delivery_events WHERE operation_id=? LIMIT 1");$dup->execute([$operationId]);
        if($dup->fetch(PDO::FETCH_ASSOC)){
            $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=?");$q->execute([$saleId]);$sale=$q->fetch(PDO::FETCH_ASSOC);tamasyaFinancialCommit($pdo);
            if(!$sale)throw new RuntimeException('Penjualan POS tidak ditemukan.');
            $posJson(['success'=>true,'duplicate'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost)]);return;
        }
        $q=$pdo->prepare("SELECT * FROM pos_sales WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$saleId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$sale) throw new RuntimeException('Penjualan POS tidak ditemukan.');
        if($sale['payment_method']!=='room_charge') throw new InvalidArgumentException('Status pengantaran hanya berlaku untuk charge ke kamar.');
        if($sale['status']!=='posted') throw new RuntimeException('Penjualan yang sudah void tidak dapat diproses pengantarannya.');
        $from=(string)($sale['delivery_status']??'created');
        $order=['created'=>0,'preparing'=>1,'out_for_delivery'=>2,'delivered'=>3];
        if(!array_key_exists($from,$order)||$order[$target] !== $order[$from]+1) throw new RuntimeException('Status pengantaran harus mengikuti urutan Baru → Disiapkan → Dibawa → Diterima.');
        $actorId=(string)$loggedInStaff['id'];$actorName=$posActorName;
        $sets=['delivery_status=?','delivery_note=COALESCE(?,delivery_note)','delivery_updated_at=CURRENT_TIMESTAMP','version=version+1'];$params=[$target,$note];
        if($target==='preparing'){$sets[]='prepared_by=?';$sets[]='prepared_by_name=?';$sets[]='prepared_at=CURRENT_TIMESTAMP';$params[]=$actorId;$params[]=$actorName;}
        if($target==='out_for_delivery'){$sets[]='dispatched_by=?';$sets[]='dispatched_by_name=?';$sets[]='dispatched_at=CURRENT_TIMESTAMP';$params[]=$actorId;$params[]=$actorName;}
        if($target==='delivered'){$sets[]='delivered_by=?';$sets[]='delivered_by_name=?';$sets[]='delivered_at=CURRENT_TIMESTAMP';$sets[]='received_by_name=?';$params[]=$actorId;$params[]=$actorName;$params[]=$receivedBy;}
        $params[]=$saleId;
        $pdo->prepare('UPDATE pos_sales SET '.implode(',',$sets).' WHERE id=?')->execute($params);
        $pdo->prepare("INSERT INTO pos_delivery_events(id,sale_id,from_status,to_status,note,received_by_name,operation_id,acted_by,acted_by_name) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([generateServerId('posdel'),$saleId,$from,$target,$note,$receivedBy,$operationId,$actorId,$actorName]);
        writeEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui pengantaran POS','pos_sale',$saleId,['deliveryStatus'=>$from],['deliveryStatus'=>$target,'note'=>$note,'receivedByName'=>$receivedBy],'web',['required'=>true]);
        $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=?");$q->execute([$saleId]);$after=$q->fetch(PDO::FETCH_ASSOC);
        tamasyaFinancialCommit($pdo);$posJson(['success'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($after),$posCanViewCost)]);return;
    }

    if ($action === 'pos-report') {
        $from=(string)($_GET['from'] ?? date('Y-m-01'));
        $to=(string)($_GET['to'] ?? date('Y-m-d'));
        if(!validIsoDate($from)||!validIsoDate($to)||$from>$to) throw new InvalidArgumentException('Rentang tanggal laporan POS tidak valid.');
        $canViewCost=$posCanViewCost;
        $salesCap=5000;$productCap=1000;$truncatedDatasets=[];
        $stmt=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.created_at DESC LIMIT ".($salesCap+1));
        $stmt->execute([$from,$to]);
        $rawSales=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($rawSales)>$salesCap){$truncatedDatasets[]='sales';$rawSales=array_slice($rawSales,0,$salesCap);}
        $sales=[];
        foreach($rawSales as $row){
            $mapped=tamasyaPosSaleRow($row);
            $mapped['grossProfit']=$canViewCost&&$mapped['status']==='posted'?round($mapped['grossAmount']-$mapped['taxAmount']-$mapped['costAmount'],2):null;
            if(!$canViewCost){unset($mapped['costAmount']);}
            $sales[]=$mapped;
        }
        $summaryStmt=$pdo->prepare("SELECT
            COUNT(*) AS total_count,
            SUM(status='posted') AS posted_count,
            SUM(status IN ('void','voided')) AS void_count,
            COALESCE(SUM(CASE WHEN status='posted' THEN subtotal_amount ELSE 0 END),0) AS subtotal,
            COALESCE(SUM(CASE WHEN status='posted' THEN discount_amount ELSE 0 END),0) AS discount,
            COALESCE(SUM(CASE WHEN status='posted' THEN gross_amount ELSE 0 END),0) AS gross,
            COALESCE(SUM(CASE WHEN status='posted' THEN tax_amount ELSE 0 END),0) AS tax,
            COALESCE(SUM(CASE WHEN status='posted' THEN cost_amount ELSE 0 END),0) AS cost,
            COALESCE(SUM(CASE WHEN status='posted' AND payment_method='room_charge' THEN gross_amount ELSE 0 END),0) AS room_charge,
            COALESCE(SUM(CASE WHEN status='posted' AND payment_method<>'room_charge' THEN gross_amount ELSE 0 END),0) AS direct_sale,
            SUM(status='posted' AND payment_method='room_charge' AND delivery_status IN ('created','preparing','out_for_delivery')) AS open_delivery
          FROM pos_sales WHERE sale_date BETWEEN ? AND ?");
        $summaryStmt->execute([$from,$to]);$summary=$summaryStmt->fetch(PDO::FETCH_ASSOC)?:[];
        $paymentStmt=$pdo->prepare("SELECT payment_method AS paymentMethod,COUNT(*) AS saleCount,
            COALESCE(SUM(gross_amount),0) AS grossAmount,COALESCE(SUM(discount_amount),0) AS discountAmount,
            COALESCE(SUM(tax_amount),0) AS taxAmount
          FROM pos_sales WHERE sale_date BETWEEN ? AND ? AND status='posted'
          GROUP BY payment_method ORDER BY grossAmount DESC");
        $paymentStmt->execute([$from,$to]);$paymentBreakdown=$paymentStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $staffStmt=$pdo->prepare("SELECT s.created_by AS staffId,
            COALESCE(NULLIF(TRIM(st.name),''),NULLIF(TRIM(st.username),''),NULLIF(TRIM(s.created_by_name),''),s.created_by) AS staffName,
            COALESCE(st.role,'unknown') AS staffRole,COUNT(*) AS saleCount,
            COALESCE(SUM(s.gross_amount),0) AS grossAmount,COALESCE(SUM(s.discount_amount),0) AS discountAmount
          FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by
          WHERE s.sale_date BETWEEN ? AND ? AND s.status='posted'
          GROUP BY s.created_by,st.name,st.username,s.created_by_name,st.role ORDER BY grossAmount DESC");
        $staffStmt->execute([$from,$to]);$staffBreakdown=$staffStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $productStmt=$pdo->prepare("SELECT i.product_id AS productId,i.sku,i.product_name AS productName,
            COALESCE(SUM(i.quantity),0) AS quantity,COALESCE(SUM(i.original_gross_amount),0) AS originalGross,
            COALESCE(SUM(i.discount_amount),0) AS discountAmount,COALESCE(SUM(i.gross_amount),0) AS grossAmount,
            COALESCE(SUM(i.tax_amount),0) AS taxAmount,COALESCE(SUM(i.unit_cost*i.quantity),0) AS costAmount
          FROM pos_sale_items i INNER JOIN pos_sales s ON s.id=i.sale_id
          WHERE s.sale_date BETWEEN ? AND ? AND s.status='posted'
          GROUP BY i.product_id,i.sku,i.product_name ORDER BY grossAmount DESC LIMIT ".($productCap+1));
        $productStmt->execute([$from,$to]);$productBreakdown=$productStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($productBreakdown)>$productCap){$truncatedDatasets[]='productBreakdown';$productBreakdown=array_slice($productBreakdown,0,$productCap);}
        if(!$canViewCost){foreach($productBreakdown as &$productRow)unset($productRow['costAmount']);unset($productRow);}
        $cost=(float)($summary['cost']??0);$gross=(float)($summary['gross']??0);$tax=(float)($summary['tax']??0);
        $payloadSummary=[
            'totalCount'=>(int)($summary['total_count']??0),'postedCount'=>(int)($summary['posted_count']??0),'voidCount'=>(int)($summary['void_count']??0),
            'subtotal'=>(float)($summary['subtotal']??0),'discount'=>(float)($summary['discount']??0),'gross'=>$gross,'tax'=>$tax,
            'roomCharge'=>(float)($summary['room_charge']??0),'directSale'=>(float)($summary['direct_sale']??0),'openDelivery'=>(int)($summary['open_delivery']??0),
            'cost'=>$canViewCost?$cost:null,'grossProfit'=>$canViewCost?round($gross-$tax-$cost,2):null
        ];
        $posJson(['success'=>true,'from'=>$from,'to'=>$to,'role'=>$posRole,'canViewCost'=>$canViewCost,'summary'=>$payloadSummary,
            'paymentBreakdown'=>$paymentBreakdown,'staffBreakdown'=>$staffBreakdown,'productBreakdown'=>$productBreakdown,'sales'=>$sales,
            'meta'=>['complete'=>count($truncatedDatasets)===0,'truncatedDatasets'=>$truncatedDatasets,'salesCap'=>$salesCap,'productCap'=>$productCap]]);return;
    }

    if ($action === 'pos-deliveries') {
        $status=strtolower(trim((string)($_GET['status'] ?? 'open')));
        if($status==='open')$where="s.payment_method='room_charge' AND s.status='posted' AND s.delivery_status IN ('created','preparing','out_for_delivery')";
        elseif($status==='all')$where="s.payment_method='room_charge'";
        elseif(in_array($status,['created','preparing','out_for_delivery','delivered','cancelled'],true))$where="s.payment_method='room_charge' AND s.delivery_status=".$pdo->quote($status);
        else throw new InvalidArgumentException('Filter status pengantaran tidak valid.');
        $rows=$pdo->query("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE $where ORDER BY FIELD(s.delivery_status,'out_for_delivery','preparing','created','delivered','cancelled'),s.created_at ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $posJson(['success'=>true,'deliveries'=>array_map(static fn(array $sale): array => tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost),$rows)]);return;
    }

    if ($action === 'pos-sales') {
        $from=(string)($_GET['from'] ?? date('Y-m-01'));$to=(string)($_GET['to'] ?? date('Y-m-d'));
        if(!validIsoDate($from)||!validIsoDate($to)||$from>$to) throw new InvalidArgumentException('Rentang tanggal tidak valid.');
        $stmt=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.created_at DESC LIMIT 1000");$stmt->execute([$from,$to]);
        $sales=array_map(static fn(array $sale): array => tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost),$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
        $posJson(['success'=>true,'sales'=>$sales]);return;
    }

    if ($action === 'pos-sale-detail') {
        $id=trim((string)($_GET['id'] ?? ''));
        $q=$pdo->prepare("SELECT ".tamasyaPosSaleSelectSql('s')." FROM pos_sales s LEFT JOIN staff st ON st.id=s.created_by WHERE s.id=? LIMIT 1");$q->execute([$id]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$sale){$posJson(['success'=>false,'error'=>'Penjualan tidak ditemukan.'],404);return;}
        $q=$pdo->prepare("SELECT i.*,p.location FROM pos_sale_items i LEFT JOIN pos_products p ON p.id=i.product_id WHERE i.sale_id=? ORDER BY i.id");$q->execute([$id]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $items=array_map(static fn(array $item): array => tamasyaPosVisibleSaleItemRow($item,$posCanViewCost),$items);
        $q=$pdo->prepare("SELECT print_type AS printType,paper_width AS paperWidth,requested_copies AS requestedCopies,copy_label AS copyLabel,printed_by_name AS printedByName,created_at AS createdAt FROM pos_print_logs WHERE sale_id=? ORDER BY created_at DESC LIMIT 50");$q->execute([$id]);$printLogs=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $q=$pdo->prepare("SELECT from_status AS fromStatus,to_status AS toStatus,note,received_by_name AS receivedByName,acted_by_name AS actedByName,created_at AS createdAt FROM pos_delivery_events WHERE sale_id=? ORDER BY created_at,id");$q->execute([$id]);$deliveryEvents=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $posJson(['success'=>true,'sale'=>tamasyaPosVisibleSaleRow(tamasyaPosSaleRow($sale),$posCanViewCost),'items'=>$items,'printLogs'=>$printLogs,'deliveryEvents'=>$deliveryEvents]);return;
    }

    $posJson(['success'=>false,'error'=>'Action POS tidak didukung.'],404);
} catch (InvalidArgumentException $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    $posJson(['success'=>false,'error'=>$e->getMessage()],422);
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    $context='POS / Minibar action '.(string)$action.' gagal';
    $safeMessage=clientExceptionMessage($context,$e);
    $status=tamasyaExceptionHttpStatus($e,500);
    $posJson(['success'=>false,'error'=>$safeMessage,'requestId'=>tamasyaRuntimeRequestId()],$status);
}
