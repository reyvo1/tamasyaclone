<?php
/** TAMASYA R15 facility management: dynamic facility engine (types are data, not code). */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'facilities') return;
$routeHandled=true;

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action);

function tamasyaFacilityValidateModel(string $capacityModel, string $pricingModel): void {
    if(!in_array($capacityModel,['open','quota','slot','room'],true))throw new InvalidArgumentException('Model kapasitas fasilitas tidak valid (open/quota/slot/room).');
    if(!in_array($pricingModel,['visit','hour','package','member'],true))throw new InvalidArgumentException('Model tarif fasilitas tidak valid (visit/hour/package/member).');
}

/** Server-side price: the client never dictates money. Returns [unitPrice,total]. */
function tamasyaFacilityPrice(array $type, array $facility, int $pax, ?string $startTime, ?string $endTime): array {
    $base=round((float)($facility['base_price']??0),2);
    $pricing=strtolower(trim((string)($type['pricing_model']??'visit')));
    $pax=max(1,$pax);
    if($pricing==='member')return [0.0,0.0];
    if($pricing==='package')return [$base,round($base,2)];
    if($pricing==='hour'){
        if($startTime===''||$startTime===null||$endTime===''||$endTime===null)throw new InvalidArgumentException('Tarif per jam wajib jam mulai dan jam selesai.');
        $start=strtotime('2000-01-01 '.$startTime);$end=strtotime('2000-01-01 '.$endTime);
        if($start===false||$end===false||$end<=$start)throw new InvalidArgumentException('Rentang jam fasilitas tidak valid.');
        $hours=(int)ceil(($end-$start)/3600);
        if($hours<1||$hours>24)throw new InvalidArgumentException('Durasi pemakaian fasilitas maksimal 24 jam.');
        return [round($base,2),round($base*$hours,2)];
    }
    return [round($base,2),round($base*$pax,2)];
}

/** Overlapping reserved/active usage on the same facility and date. Times are
 *  compared as HH:MM:SS on both sides: the stored rows carry seconds while the
 *  request carries HH:MM, and a raw string compare would treat an adjacent
 *  slot (21:00 vs stored 21:00:00) as overlapping. */
function tamasyaFacilityClock(string $value): string {
    $value=trim($value);
    if(preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/',$value,$m))return str_pad((string)$m[1],2,'0',STR_PAD_LEFT).':'.$m[2].':'.($m[3]??'00');
    return $value;
}
function tamasyaFacilitySlotConflicts(PDO $pdo, string $facilityId, string $useDate, ?string $startTime, ?string $endTime, ?string $excludeId=null): bool {
    $rows=$pdo->prepare("SELECT id,start_time,end_time FROM facility_bookings WHERE facility_id=? AND use_date=? AND status IN ('reserved','active')");
    $rows->execute([$facilityId,$useDate]);
    $newStart=$startTime!==null&&$startTime!==''?tamasyaFacilityClock($startTime):'';
    $newEnd=$endTime!==null&&$endTime!==''?tamasyaFacilityClock($endTime):'';
    foreach(($rows->fetchAll(PDO::FETCH_ASSOC)?:[]) as $row){
        if($excludeId!==null&&(string)($row['id']??'')===$excludeId)continue;
        $existingStart=tamasyaFacilityClock((string)($row['start_time']??''));
        $existingEnd=tamasyaFacilityClock((string)($row['end_time']??''));
        if($existingStart===''||$existingEnd===''||$newStart===''||$newEnd==='')continue;
        if($newStart<$existingEnd&&$newEnd>$existingStart)return true;
    }
    return false;
}

function tamasyaFacilityQuotaRemaining(PDO $pdo, string $facilityId, string $useDate, int $capacity): int {
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(pax),0) FROM facility_bookings WHERE facility_id=? AND use_date=? AND status IN ('reserved','active')");
    $stmt->execute([$facilityId,$useDate]);
    return max(0,$capacity-(int)$stmt->fetchColumn());
}

try {
    $command=trim((string)($method==='GET'?($_GET['command']??'facility-list'):($input['command']??'')));
    // Semua aksi tulis fasilitas mewajibkan POST. Tanpa penjaga ini, aksi tulis
    // bisa dipicu lewat query string pada GET, dan Owner yang memakai capability
    // Admin untuk pembacaan ikut lolos penjaga capability.
    $facilityWriteCommands=['facility-type-save','facility-save','facility-book','facility-booking-cancel','facility-booking-activate','facility-booking-complete'];
    if(in_array($command,$facilityWriteCommands,true)&&$method!=='POST'){
        http_response_code(405);
        echo tamasyaJsonEncode(['success'=>false,'error'=>'Aksi tulis fasilitas wajib memakai POST.']);
        return;
    }
    $fetchAll=static function(PDO $pdo,string $sql,array $args=[]): array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];};
    $fetchOne=static function(PDO $pdo,string $sql,array $args=[]): ?array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;};
    switch ($command) {
        case 'facility-type-list':
            requireRoles($loggedInStaff,['admin','manager','receptionist','finance']);
            echo tamasyaJsonEncode(['success'=>true,'types'=>$fetchAll($pdo,"SELECT * FROM facility_types ORDER BY active DESC,name")]);
            break;
        case 'facility-type-save':
            requireCapability($loggedInStaff,'manage_facilities',['admin','manager']);
            $code=strtolower(trim((string)($input['code']??'')));$name=trim((string)($input['name']??''));
            $capacityModel=strtolower(trim((string)($input['capacityModel']??'open')));
            $pricingModel=strtolower(trim((string)($input['pricingModel']??'visit')));
            if(!preg_match('/^[a-z0-9._-]{2,50}$/',$code))throw new InvalidArgumentException('Kode tipe fasilitas tidak valid.');
            if($name==='')throw new InvalidArgumentException('Nama tipe fasilitas wajib.');
            tamasyaFacilityValidateModel($capacityModel,$pricingModel);
            $pdo->beginTransaction();
            try{
                $id=trim((string)($input['id']??''));
                $before=null;
                if($id!==''){$before=$fetchOne($pdo,"SELECT * FROM facility_types WHERE id=? FOR UPDATE",[$id]);if(!$before)throw new InvalidArgumentException('Tipe fasilitas tidak ditemukan.');}
                else{$dup=$fetchOne($pdo,"SELECT id FROM facility_types WHERE code=? LIMIT 1 FOR UPDATE",[$code]);if($dup)throw new DomainException('Kode tipe fasilitas sudah dipakai.');$id=tamasyaGrowthId('fty');}
                $active=array_key_exists('active',$input)?(!empty($input['active'])?1:0):($before?(int)$before['active']:1);
                $requiresStaff=array_key_exists('requiresStaff',$input)?(!empty($input['requiresStaff'])?1:0):($before?(int)$before['requires_staff']:0);
                if($before)$pdo->prepare("UPDATE facility_types SET code=?,name=?,capacity_model=?,pricing_model=?,requires_staff=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$code,$name,$capacityModel,$pricingModel,$requiresStaff,$active,$id]);
                else $pdo->prepare("INSERT INTO facility_types(id,code,name,capacity_model,pricing_model,requires_staff,active,created_by) VALUES (?,?,?,?,?,?,?,?)")->execute([$id,$code,$name,$capacityModel,$pricingModel,$requiresStaff,$active,$loggedInStaff['id']]);
                $after=$fetchOne($pdo,"SELECT * FROM facility_types WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan tipe fasilitas dinamis','facility_type',$id,$before,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        case 'facility-list':
            requireRoles($loggedInStaff,['admin','manager','receptionist','finance']);
            echo tamasyaJsonEncode(['success'=>true,'facilities'=>$fetchAll($pdo,"SELECT f.*,t.code AS type_code,t.name AS type_name,t.capacity_model,t.pricing_model,t.requires_staff AS type_requires_staff FROM facilities f LEFT JOIN facility_types t ON t.id=f.facility_type_id ORDER BY f.active DESC,f.name")]);
            break;
        case 'facility-save':
            requireCapability($loggedInStaff,'manage_facilities',['admin','manager']);
            $code=strtolower(trim((string)($input['code']??'')));$name=trim((string)($input['name']??''));
            $typeId=trim((string)($input['facilityTypeId']??''));$capacity=max(0,(int)($input['capacity']??0));
            $openTime=trim((string)($input['openTime']??''));$closeTime=trim((string)($input['closeTime']??''));
            $basePrice=round((float)($input['basePrice']??0),2);
            if(!preg_match('/^[a-z0-9._-]{2,50}$/',$code))throw new InvalidArgumentException('Kode fasilitas tidak valid.');
            if($name==='')throw new InvalidArgumentException('Nama fasilitas wajib.');
            if($basePrice<0||$basePrice>1000000000000)throw new InvalidArgumentException('Tarif dasar tidak valid.');
            foreach(['openTime'=>$openTime,'closeTime'=>$closeTime] as $label=>$value){
                if($value!==''&&!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$value))throw new InvalidArgumentException('Jam operasional tidak valid (HH:MM).');
            }
            if($openTime!==''&&$closeTime!==''&&$openTime>=$closeTime)throw new InvalidArgumentException('Jam buka harus sebelum jam tutup fasilitas.');
            $pdo->beginTransaction();
            try{
                $type=$fetchOne($pdo,"SELECT * FROM facility_types WHERE id=? LIMIT 1 FOR UPDATE",[$typeId]);
                if(!$type||(int)$type['active']!==1)throw new InvalidArgumentException('Tipe fasilitas tidak aktif/tidak ditemukan.');
                $id=trim((string)($input['id']??''));
                $before=null;
                if($id!==''){$before=$fetchOne($pdo,"SELECT * FROM facilities WHERE id=? FOR UPDATE",[$id]);if(!$before)throw new InvalidArgumentException('Fasilitas tidak ditemukan.');}
                else{$dup=$fetchOne($pdo,"SELECT id FROM facilities WHERE code=? LIMIT 1 FOR UPDATE",[$code]);if($dup)throw new DomainException('Kode fasilitas sudah dipakai.');$id=tamasyaGrowthId('fac');}
                $active=array_key_exists('active',$input)?(!empty($input['active'])?1:0):($before?(int)$before['active']:1);
                if($before)$pdo->prepare("UPDATE facilities SET code=?,name=?,facility_type_id=?,capacity=?,open_time=?,close_time=?,base_price=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$code,$name,$typeId,$capacity,$openTime!==''?$openTime.':00':null,$closeTime!==''?$closeTime.':00':null,$basePrice,$active,$id]);
                else $pdo->prepare("INSERT INTO facilities(id,code,name,facility_type_id,capacity,open_time,close_time,base_price,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$id,$code,$name,$typeId,$capacity,$openTime!==''?$openTime.':00':null,$closeTime!==''?$closeTime.':00':null,$basePrice,$active,$loggedInStaff['id']]);
                $after=$fetchOne($pdo,"SELECT * FROM facilities WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan unit fasilitas','facility',$id,$before,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        case 'facility-bookings':
            requireRoles($loggedInStaff,['admin','manager','receptionist','finance']);
            $date=trim((string)($_GET['date']??''));$facilityId=trim((string)($_GET['facilityId']??''));
            if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new InvalidArgumentException('Tanggal tidak valid.');
            $sql="SELECT fb.*,f.name AS facility_name,f.code AS facility_code FROM facility_bookings fb LEFT JOIN facilities f ON f.id=fb.facility_id WHERE 1=1";
            $args=[];
            if($date!==''){$sql.=" AND fb.use_date=?";$args[]=$date;}
            if($facilityId!==''){$sql.=" AND fb.facility_id=?";$args[]=$facilityId;}
            $sql.=" ORDER BY fb.use_date DESC,fb.start_time,fb.created_at DESC LIMIT 200";
            echo tamasyaJsonEncode(['success'=>true,'bookings'=>$fetchAll($pdo,$sql,$args)]);
            break;
        case 'facility-book':
            requireRoles($loggedInStaff,['admin','manager','receptionist']);
            $facilityId=trim((string)($input['facilityId']??''));$bookingId=trim((string)($input['bookingId']??''));
            $guestName=trim((string)($input['guestName']??''));$useDate=trim((string)($input['date']??date('Y-m-d')));
            $startTime=trim((string)($input['startTime']??''));$endTime=trim((string)($input['endTime']??''));
            $pax=max(1,(int)($input['pax']??1));$notes=trim((string)($input['notes']??''));
            $rawOp=trim((string)($input['operationId']??($GLOBALS['tamasya_request_operation_id']??'')));
            $op=$rawOp!==''?normalizeTelegramMutationOperationId($rawOp):tamasyaGrowthId('fop');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$useDate))throw new InvalidArgumentException('Tanggal pemakaian tidak valid.');
            foreach(['startTime'=>$startTime,'endTime'=>$endTime] as $label=>$value){
                if($value!==''&&!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$value))throw new InvalidArgumentException('Jam pemakaian tidak valid (HH:MM).');
            }
            $pdo->beginTransaction();
            try{
                $facility=$fetchOne($pdo,"SELECT f.*,t.capacity_model,t.pricing_model,t.requires_staff FROM facilities f LEFT JOIN facility_types t ON t.id=f.facility_type_id WHERE f.id=? LIMIT 1 FOR UPDATE",[$facilityId]);
                if(!$facility||(int)$facility['active']!==1)throw new InvalidArgumentException('Fasilitas tidak aktif/tidak ditemukan.');
                $capacityModel=strtolower(trim((string)($facility['capacity_model']??'open')));
                tamasyaFacilityValidateModel($capacityModel,strtolower(trim((string)($facility['pricing_model']??'visit'))));
                $dup=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE operation_id=? LIMIT 1 FOR UPDATE",[$op]);
                if($dup){tamasyaFinancialCommit($pdo);echo tamasyaJsonEncode(['success'=>true,'duplicate'=>true,'data'=>$dup]);break;}
                // Slot/room models consume exclusive time; quota consumes headcount.
                if(in_array($capacityModel,['slot','room'],true)){
                    if($startTime===''||$endTime==='')throw new InvalidArgumentException('Model kapasitas ini wajib jam mulai dan jam selesai.');
                    if($endTime<=$startTime)throw new InvalidArgumentException('Jam selesai harus setelah jam mulai.');
                    if(!empty($facility['open_time']) && $startTime < substr((string)$facility['open_time'],0,5)) throw new InvalidArgumentException('Jam mulai sebelum jam buka fasilitas.');
                    if(!empty($facility['close_time']) && $endTime > substr((string)$facility['close_time'],0,5)) throw new InvalidArgumentException('Jam selesai setelah jam tutup fasilitas.');
                    if(tamasyaFacilitySlotConflicts($pdo,$facilityId,$useDate,$startTime,$endTime))throw new DomainException('Slot fasilitas sudah terisi pada jam tersebut.',409);
                }
                if($capacityModel==='quota'){
                    $remaining=tamasyaFacilityQuotaRemaining($pdo,$facilityId,$useDate,(int)$facility['capacity']);
                    if($pax>$remaining)throw new DomainException('Kuota fasilitas tersisa '.$remaining.' orang untuk tanggal tersebut.',409);
                }
                [$unitPrice,$total]=tamasyaFacilityPrice(
                    ['pricing_model'=>(string)($facility['pricing_model']??'visit')],
                    ['base_price'=>(float)($facility['base_price']??0)],
                    $pax,$startTime!==''?$startTime:null,$endTime!==''?$endTime:null
                );
                $booking=null;
                if($bookingId!==''){
                    $booking=$fetchOne($pdo,"SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE",[$bookingId]);
                    if(!$booking)throw new InvalidArgumentException('Booking tamu tidak ditemukan.');
                    if(!in_array((string)$booking['status'],['reserved','active'],true))throw new InvalidArgumentException('Fasilitas hanya dapat ditautkan ke booking reserved/active.');
                }
                if($guestName===''&&$booking)$guestName=(string)$booking['guestName'];
                if($guestName==='')throw new InvalidArgumentException('Nama tamu wajib.');
                $id=tamasyaGrowthId('fbk');
                $pdo->prepare("INSERT INTO facility_bookings(id,facility_id,booking_id,guest_name,use_date,start_time,end_time,pax,unit_price,total,status,payment_status,operation_id,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,'reserved','unpaid',?,?,?)")
                    ->execute([$id,$facilityId,$bookingId!==''?$bookingId:null,$guestName,$useDate,$startTime!==''?$startTime.':00':null,$endTime!==''?$endTime.':00':null,$pax,$unitPrice,$total,$op,$notes!==''?$notes:null,$loggedInStaff['id']]);
                $folioExtraId=null;
                if($booking&&$total>0){
                    // Charge posts as a folio extra through the same shape as service
                    // charges: tax snapshot from the canonical authority, unpaid until
                    // the normal payment/checkout flow collects it. No money moves here.
                    $bookingSource=(string)($booking['bookingSource']??'Direct');
                    $rate=resolveConfiguredTaxRate($pdo,$bookingSource,'extra',null,$useDate);
                    $taxAmount=round($total*((float)$rate/100),2);
                    $gross=round($total+$taxAmount,2);
                    $category=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',true);
                    $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
                    $folioExtraId='ex_fac_'.substr(hash('sha256',$op),0,20);
                    $extras[]=[
                        'id'=>$folioExtraId,'name'=>(string)$facility['name'].' - '.$useDate.($startTime!==''?' '.$startTime.'-'.$endTime:''),
                        'price'=>$total,'qty'=>1,'total'=>$gross,
                        'baseAmount'=>$total,'taxAmount'=>$taxAmount,'taxRate'=>(float)$rate,
                        'taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule',
                        'taxKind'=>'extra','allocationType'=>'extra','revenueType'=>'service',
                        'category'=>(string)$category['name'],'categoryId'=>(string)$category['id'],'categorySystemKey'=>'extra_service',
                        'subcategory'=>null,'subcategoryId'=>null,'subcategorySystemKey'=>null,'paymentStatus'=>'unpaid',
                        'paymentMethod'=>null,'bankAccountId'=>null,'paymentOperationId'=>null,
                        'facilityBookingId'=>$id,'createdAt'=>date('Y-m-d H:i:s'),
                    ];
                    $newTotal=round((float)$booking['totalAmount']+$gross,2);
                    $newVat=round((float)$booking['vatAmount']+$taxAmount,2);
                    $pdo->prepare("UPDATE bookings SET totalAmount=?,vatAmount=?,extras=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
                        ->execute([$newTotal,$newVat,json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id'],'web',$bookingId]);
                    $pdo->prepare("UPDATE facility_bookings SET folio_extra_id=? WHERE id=?")->execute([$folioExtraId,$id]);
                    recalculateBookingFinancials($pdo,$bookingId,true);
                    assertBookingLedgerInvariant($pdo,$bookingId,$loggedInStaff,'web',false);
                }
                $after=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memesan fasilitas tamu','facility_booking',$id,null,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after,'charged'=>$folioExtraId!==null,'financialMutation'=>false]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        case 'facility-booking-cancel':
            requireRoles($loggedInStaff,['admin','manager','receptionist']);
            $id=trim((string)($input['id']??''));
            $pdo->beginTransaction();
            try{
                $row=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=? FOR UPDATE",[$id]);
                if(!$row)throw new InvalidArgumentException('Reservasi fasilitas tidak ditemukan.');
                if(!in_array((string)$row['status'],['reserved','active'],true))throw new InvalidArgumentException('Hanya reservasi aktif yang dapat dibatalkan.');
                if((string)$row['payment_status']==='paid')throw new DomainException('Pemakaian yang sudah dibayar dibatalkan lewat refund, bukan pembatalan slot.',409);
                // Reverse the folio charge the same way it was added, so the ledger
                // stays balanced; the row itself remains for audit.
                if(!empty($row['booking_id'])&&!empty($row['folio_extra_id'])){
                    $booking=$fetchOne($pdo,"SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE",[(string)$row['booking_id']]);
                    if($booking){
                        $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
                        $kept=[];$reversed=['total'=>0.0,'vat'=>0.0];
                        foreach($extras as $extra){
                            if((string)($extra['id']??'')===(string)$row['folio_extra_id']){
                                $reversed['total']=round($reversed['total']+(float)($extra['total']??0),2);
                                $reversed['vat']=round($reversed['vat']+(float)($extra['taxAmount']??0),2);
                                continue;
                            }
                            $kept[]=$extra;
                        }
                        $pdo->prepare("UPDATE bookings SET totalAmount=?,vatAmount=?,extras=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
                            ->execute([round((float)$booking['totalAmount']-$reversed['total'],2),round((float)$booking['vatAmount']-$reversed['vat'],2),json_encode($kept,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$loggedInStaff['id'],'web',(string)$row['booking_id']]);
                        recalculateBookingFinancials($pdo,(string)$row['booking_id'],true);
                        assertBookingLedgerInvariant($pdo,(string)$row['booking_id'],$loggedInStaff,'web',false);
                    }
                }
                $before=$row;
                $pdo->prepare("UPDATE facility_bookings SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
                $after=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membatalkan reservasi fasilitas','facility_booking',$id,$before,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        case 'facility-booking-activate':
            requireRoles($loggedInStaff,['admin','manager','receptionist']);
            $id=trim((string)($input['id']??''));
            $pdo->beginTransaction();
            try{
                $row=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=? FOR UPDATE",[$id]);
                if(!$row)throw new InvalidArgumentException('Reservasi fasilitas tidak ditemukan.');
                if((string)$row['status']!=='reserved')throw new InvalidArgumentException('Hanya reservasi yang dapat diaktifkan.');
                $pdo->prepare("UPDATE facility_bookings SET status='active',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
                $after=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengaktifkan pemakaian fasilitas','facility_booking',$id,$row,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        case 'facility-booking-complete':
            requireRoles($loggedInStaff,['admin','manager','receptionist']);
            $id=trim((string)($input['id']??''));
            $pdo->beginTransaction();
            try{
                $row=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=? FOR UPDATE",[$id]);
                if(!$row)throw new InvalidArgumentException('Reservasi fasilitas tidak ditemukan.');
                if((string)$row['status']!=='active')throw new InvalidArgumentException('Hanya pemakaian aktif yang dapat diselesaikan.');
                $pdo->prepare("UPDATE facility_bookings SET status='completed',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
                $after=$fetchOne($pdo,"SELECT * FROM facility_bookings WHERE id=?",[$id]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyelesaikan pemakaian fasilitas','facility_booking',$id,$row,$after,'web');
                bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
                echo tamasyaJsonEncode(['success'=>true,'data'=>$after]);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            break;
        default: throw new InvalidArgumentException('Command fasilitas tidak dikenal.');
    }
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    tamasyaApplyExceptionHttpStatus($e,422);
    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Fasilitas gagal',$e)]);
}
