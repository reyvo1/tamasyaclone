<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'hotel-data',
  1 => 'booking-identity',
  2 => 'bookings',
  3 => 'rooms',
  4 => 'booking-payments',
  5 => 'bookings-status',
  6 => 'transaction-booking-action',
  7 => 'transaction-allocation-void',
  8 => 'guest-security-deposits',
  9 => 'operation-receipt-status',
  10 => 'room-transfers',
  11 => 'booking-negotiated-price',
  12 => 'multi-room-bookings',
  13 => 'booking-receipt-email',
  14 => 'group-report-email',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'multi-room-bookings':
        try {
            $method=$_SERVER['REQUEST_METHOD'];$command=trim((string)($input['command']??$_GET['command']??($method==='GET'?'list':'create')));
            tamasyaMultiRoomRequire($pdo,$loggedInStaff,$method!=='GET');
            if($method==='GET'){
                if($command==='companies')$data=tamasyaEnterpriseFetchAll($pdo,"SELECT id,name FROM growth_companies WHERE status='active' ORDER BY name");
                elseif($command==='catalog')$data=tamasyaMultiRoomCatalog($pdo,$loggedInStaff);
                elseif($command==='availability')$data=tamasyaMultiRoomAvailability($pdo,$loggedInStaff,$_GET);
                elseif($command==='detail')$data=tamasyaMultiRoomDetail($pdo,trim((string)($_GET['id']??'')));
                elseif($command==='list'){$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*50;$data=tamasyaEnterpriseFetchAll($pdo,"SELECT g.id,g.group_code,g.name,g.arrival_date,g.departure_date,g.billing_mode,(SELECT COUNT(*) FROM growth_group_booking_links l WHERE l.group_id=g.id) booking_count FROM growth_group_reservations g ORDER BY g.created_at DESC,g.id DESC LIMIT 50 OFFSET {$offset}");}
                else throw new InvalidArgumentException('Pilihan reservasi grup tidak dikenal.');
                echo tamasyaJsonEncode(['success'=>true,'data'=>$data]+($command==='list'?['nextPage'=>count($data)===50?$page+1:null]:[]));
            }elseif($method==='POST'){
                $op=trim((string)($GLOBALS['tamasya_request_operation_id']??''));if($op==='')throw new DomainException('Operation ID wajib untuk reservasi grup.',428);
                if($command==='create')echo tamasyaJsonEncode(['success'=>true]+tamasyaMultiRoomCreate($pdo,$loggedInStaff,$input,'web',$op));
                elseif($command==='payment')echo tamasyaJsonEncode(['success'=>true]+tamasyaMultiRoomPayment($pdo,$loggedInStaff,$input,'web',$op));
                elseif($command==='detach')echo tamasyaJsonEncode(['success'=>true]+tamasyaMultiRoomDetach($pdo,$loggedInStaff,$input,$op));
                elseif($command==='edit')echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaMultiRoomEdit($pdo,$loggedInStaff,$input,'web',$op)]);
                else throw new InvalidArgumentException('Pilihan reservasi grup tidak dikenal.');
            }else{http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);}
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof InvalidArgumentException){http_response_code(422);}else{tamasyaApplyExceptionHttpStatus($e,422);}echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Reservasi grup gagal',$e)]);}
        break;
    case 'booking-receipt-email':
    case 'group-report-email':
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') { http_response_code(405); echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']); break; }
            $roles=['admin','manager','receptionist'];
            if(!hasCapability($loggedInStaff,'sell_room_access',$roles)&&!hasCapability($loggedInStaff,'manage_stay_access',$roles))throw new DomainException('Peran ini tidak berhak mengirim email resmi.',403);
            if(!tamasyaHasRoomsWorkspaceAccess($loggedInStaff))throw new DomainException('Akses workspace Kamar tidak diizinkan.',403);
            $email=trim((string)($input['email']??''));
            if($action==='booking-receipt-email'){
                $bookingId=trim((string)($input['bookingId']??''));
                if($bookingId==='')throw new InvalidArgumentException('Booking wajib dipilih.');
                $stmt=$pdo->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');$stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$booking)throw new InvalidArgumentException('Booking tidak ditemukan.');
                $result=tamasyaSendBookingReceiptEmail($pdo,$loggedInStaff,$booking,$email,'web',(string)($GLOBALS['tamasya_request_operation_id']??''));
                echo tamasyaJsonEncode(['success'=>true,'invoiceNumber'=>$result['invoiceNumber'],'masked'=>$result['masked'],'verified'=>(bool)($result['verified']??($result['method']==='SMTP')),'deliveryNote'=>$result['deliveryNote']??'','message'=>'Nota '.$result['invoiceNumber'].' terkirim ke '.$result['masked'].' ('.$result['method'].'). '.($result['deliveryNote']??'')]);
            }else{
                tamasyaGrowthRequireReady($pdo,'group');
                $groupId=trim((string)($input['groupId']??''));
                if($groupId==='')throw new InvalidArgumentException('Reservasi grup wajib dipilih.');
                if($email===''){
                    $stmt=$pdo->prepare('SELECT c.billing_email FROM growth_group_reservations g LEFT JOIN growth_companies c ON c.id=g.company_id WHERE g.id=? LIMIT 1');
                    $stmt->execute([$groupId]);$email=trim((string)($stmt->fetchColumn()?:''));
                }
                $result=tamasyaSendGroupReportEmail($pdo,$loggedInStaff,$groupId,$email,'web',(string)($GLOBALS['tamasya_request_operation_id']??''));
                echo tamasyaJsonEncode(['success'=>true,'reportReference'=>$result['reportReference'],'masked'=>$result['masked'],'verified'=>(bool)($result['verified']??($result['method']==='SMTP')),'deliveryNote'=>$result['deliveryNote']??'','message'=>'Laporan '.$result['reportReference'].' terkirim ke '.$result['masked'].' ('.$result['method'].'). '.($result['deliveryNote']??'')]);
            }
        }catch(Throwable $e){http_response_code($e instanceof DomainException&&in_array($e->getCode(),[403,409],true)?$e->getCode():422);echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Email resmi gagal dikirim',$e)]);}
        break;
    case 'booking-negotiated-price':
        requireDesktopTabAccess($loggedInStaff,'rooms',['admin','manager','receptionist']);
        $method=$_SERVER['REQUEST_METHOD'];$id=trim((string)($input['bookingId']??($_GET['id']??'')));
        try{
            if(!hasCapability($loggedInStaff,'manage_stay_access',['admin','manager','receptionist']))throw new DomainException('Peran ini tidak berhak menetapkan harga nego.',403);
            if($method==='GET'){
                $q=$pdo->prepare('SELECT * FROM bookings WHERE id=? LIMIT 1');$q->execute([$id]);$booking=$q->fetch(PDO::FETCH_ASSOC);
                if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
                $quote=tamasyaBookingNegotiationQuote($pdo,$booking);$preview=null;
                if(isset($_GET['finalTotal'])){
                    if(!is_numeric($_GET['finalTotal']))throw new InvalidArgumentException('Harga final tidak valid.');
                    // Same reading as the save path: the figure is what the guest hands
                    // over now, so cash already received is added back for the preview.
                    $previewTarget=round((float)$_GET['finalTotal']+(float)$quote['amountPaid'],2);
                    $plan=tamasyaNegotiatedPricePlan($booking,$quote['components'],$quote['paid'],$quote['amountPaid'],$previewTarget);
                    $preview=array_intersect_key($plan,array_flip(['totalAmount','vatAmount','discountAmount','taxReduction']));
                }
                unset($quote['components'],$quote['paid']);
                echo json_encode(['success'=>true,'quote'=>$quote,'preview'=>$preview]);
            }elseif($method==='POST'){
                $result=tamasyaApplyBookingNegotiatedPrice($pdo,$loggedInStaff,$id,$input,'web',(string)($GLOBALS['tamasya_request_operation_id']??''));
                $b=$result['booking'];
                $result['booking']=array_intersect_key($b,array_flip(['id','status','totalAmount','vatAmount','vatRate','extras','paymentStatus','amountPaid','balanceDue','version']));
                foreach(['totalAmount','vatAmount','amountPaid','balanceDue'] as $key)$result['booking'][$key]=(float)$b[$key];
                $result['booking']['version']=(int)$b['version'];
                echo json_encode(['success'=>true]+$result);
            }else{http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);}
        }catch(Throwable $e){http_response_code($e instanceof DomainException&&in_array($e->getCode(),[403,409],true)?$e->getCode():422);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Harga nego ditolak',$e)]);}
        break;

    case 'hotel-data':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }
        echo json_encode(getRoleScopedHotelData($pdo, $loggedInStaff));
        break;

    // GET /api/booking-identity?id=BOOKING_ID
    // Foto KTP tidak pernah ikut sinkronisasi umum. Akses online, role/capability,
    // dan audit diwajibkan agar identitas tamu tetap tersedia tanpa bocor silang.
    case 'booking-identity':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        if (!hasCapability($loggedInStaff, 'view_guest_identity', ['admin','manager','receptionist','keamanan'])) {
            writeEnterpriseAudit($pdo,$loggedInStaff,'Akses KTP ditolak','booking',trim((string)($_GET['id'] ?? '')),null,['reason'=>'missing_capability']);
            http_response_code(403);
            echo json_encode(['success'=>false,'error'=>'Forbidden: izin view_guest_identity diperlukan.']);
            break;
        }
        $identityRateKey = 'booking-identity:' . ($loggedInStaff['id'] ?? 'unknown') . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!enforceRateLimit($pdo,$identityRateKey,30,60,300)) break;
        $bookingId = trim((string)($_GET['id'] ?? ''));
        if ($bookingId === '' || strlen($bookingId) > 190) {
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>'ID reservasi tidak valid.']);
            break;
        }
        try {
            $stmtIdentity = $pdo->prepare("SELECT id,guestName,roomNumber,status,ktpPhoto FROM bookings WHERE id=? LIMIT 1");
            $stmtIdentity->execute([$bookingId]);
            $identityBooking = $stmtIdentity->fetch();
            if (!$identityBooking) {
                http_response_code(404);
                echo json_encode(['success'=>false,'error'=>'Reservasi tidak ditemukan.']);
                break;
            }
            $identity = bookingIdentityDataUrl($pdo, (string)($identityBooking['ktpPhoto'] ?? ''));
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Melihat KTP tamu','booking',$bookingId,null,[
                'roomNumber'=>$identityBooking['roomNumber'],
                'bookingStatus'=>$identityBooking['status'],
                'storage'=>$identity['storage'] ?? 'none',
                'available'=>!empty($identity['available'])
            ]);
            logActivity($pdo,'VIEW_GUEST_IDENTITY','Melihat KTP reservasi '.$bookingId.' kamar '.($identityBooking['roomNumber'] ?? ''),$loggedInStaff['id'] ?? null,$loggedInStaff['name'] ?? null);
            echo json_encode(['success'=>true,'booking'=>[
                'id'=>$identityBooking['id'],
                'guestName'=>$identityBooking['guestName'],
                'roomNumber'=>$identityBooking['roomNumber'],
                'status'=>$identityBooking['status'],
                'hasKtpPhoto'=>!empty($identity['available'])
            ],'dataUrl'=>$identity['dataUrl'] ?? null,'storage'=>$identity['storage'] ?? 'none','message'=>$identity['reason'] ?? null]);
        } catch (Throwable $identityError) {
            writeEnterpriseAudit($pdo,$loggedInStaff,'Gagal melihat KTP tamu','booking',$bookingId,null,['errorClass'=>get_class($identityError)]);
            http_response_code(422);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('KTP tidak dapat dibuka',$identityError)]);
        }
        break;


    // Read-only reconciliation endpoint for a mutation whose HTTP response was lost.
    // It never re-executes an operation and only exposes receipts owned by the logged-in staff.
    case 'operation-receipt-status':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break;
        }
        $operationId=trim((string)($_GET['operationId']??$_GET['operation_id']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,100}$/',$operationId)){
            http_response_code(422); echo json_encode(['success'=>false,'error'=>'operation_id tidak valid.']); break;
        }
        $stmt=$pdo->prepare("SELECT operation_id,status,http_status,response_body,error_message,created_at,updated_at,completed_at FROM request_operation_receipts WHERE operation_id=? AND staff_id=? LIMIT 1");
        $stmt->execute([$operationId,(string)($loggedInStaff['id']??'')]);
        $receipt=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$receipt){echo json_encode(['success'=>true,'found'=>false,'operationId'=>$operationId]);break;}
        $receiptStatus=strtolower((string)($receipt['status']??'processing'));
        $terminal=in_array($receiptStatus,['completed','failed','rejected'],true);
        echo json_encode([
            'success'=>true,'found'=>true,'operationId'=>$operationId,'receiptStatus'=>$receiptStatus,
            'terminal'=>$terminal,'httpStatus'=>(int)($receipt['http_status']??0),
            'responseBody'=>$terminal?tamasyaDecodeReceiptResponseBody((string)($receipt['response_body']??'')):null,
            'errorMessage'=>$terminal?(string)($receipt['error_message']??''):null,
            'updatedAt'=>$receipt['updated_at']??null,'completedAt'=>$receipt['completed_at']??null,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    // ----------------------------------------------------------------
    // GET/POST/PUT/DELETE /api/bookings ATAU api.php?action=bookings
    // Manajemen Pemesanan Kamar (Daftar, Tambah, Edit, Hapus)
    // ----------------------------------------------------------------
    // POST /api/room-transfers/{bookingId}
    // Dedicated active-stay command boundary. The browser may submit only transfer
    // intent; canonical booking/folio/extras/tax state is loaded by the server.
    case 'room-transfers':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'message'=>'Method Not Allowed']);
            break;
        }
        requireCapability($loggedInStaff, 'manage_stay_access', ['admin', 'manager', 'receptionist']);
        $dedicatedRoomTransferCommand = true;
        // Fall through into the booking mutation engine, but force its internal
        // branch to PUT semantics without changing the public receipt method/action.

    case 'bookings':
        $method = !empty($dedicatedRoomTransferCommand) ? 'PUT' : $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            requireRoles($loggedInStaff, ['admin', 'manager', 'receptionist', 'finance']);
        } elseif ($method === 'DELETE') {
            // Penghapusan permanen reservasi hanya untuk pengawas. Resepsionis
            // menggunakan perubahan status/cancel agar jejak transaksi tetap ada.
            requireRoles($loggedInStaff, ['admin', 'manager']);
        } elseif ($method === 'POST') {
            // Buat booking = penjualan: role baseline ATAU centangan sell_room_access.
            requireCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist']);
        } else {
            // Edit booking (termasuk perpanjangan/pindah via PUT): boleh via
            // centangan sell ATAU stay, agar finance yang dipilih salah satunya
            // tetap bisa bekerja. Tanpa centangan, fallback ke role lama.
            if (!hasCapability($loggedInStaff, 'sell_room_access', ['admin', 'manager', 'receptionist'])
                && !hasCapability($loggedInStaff, 'manage_stay_access', ['admin', 'manager', 'receptionist'])) {
                http_response_code(403);
                echo json_encode(["success" => false, "error" => "Forbidden: Anda tidak memiliki izin untuk operasi ini."]);
                exit;
            }
        }
        
        if ($method === 'POST') {
            $operationId=trim((string)($GLOBALS['tamasya_request_operation_id']??''));
            if($operationId===''){
                http_response_code(428);
                echo json_encode(['success'=>false,'message'=>'X-Tamasya-Operation-ID wajib untuk membuat booking.']);
                break;
            }
            try{
                $result=createCanonicalBookingWorkflow($pdo,$loggedInStaff,(array)$input,'web',$operationId);
                $compactResponse=(string)($_GET['compact']??'')==='1';
                $response=[
                    'success'=>true,
                    'duplicate'=>!empty($result['duplicate']),
                    'bookingId'=>$result['bookingId']??null,
                    'bookingStatus'=>$result['bookingStatus']??null,
                    'paymentStatus'=>$result['paymentStatus']??null,
                    'message'=>($result['bookingStatus']??'')==='reserved'
                        ?'Reservasi mendatang berhasil disimpan dan belum dianggap check-in.'
                        :'Registrasi check-in berhasil disimpan melalui workflow booking pusat.'
                ];
                if($compactResponse){
                    try{$response['delta']=tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,$result);}
                    catch(Throwable $deltaError){
                        error_log(clientExceptionMessage('[booking compact delta] refresh penuh diperlukan',$deltaError));
                        $response['delta']=null;$response['refreshRequired']=true;
                    }
                }else{
                    $response['db']=getRoleScopedHotelData($pdo,$loggedInStaff);
                }
                echo json_encode($response);
            }catch(Throwable $e){
                tamasyaApplyExceptionHttpStatus($e,500);
                echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal menyimpan booking',$e)]);
            }
        } elseif ($method === 'PUT') {
            // Edit booking details (e.g. extending check-out date, changing totalAmount, etc.)
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "ID Booking harus disertakan!"]);
                break;
            }
            
            $isDedicatedRoomTransfer = !empty($dedicatedRoomTransferCommand);
            if ($isDedicatedRoomTransfer) {
                $allowedTransferKeys=['targetRoomNumber','rateMode','surchargeAmount','keyDisposition','keyReason','expectedVersion'];
                $unexpectedTransferKeys=array_values(array_diff(array_keys((array)$input),$allowedTransferKeys));
                if($unexpectedTransferKeys){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'message'=>'Payload pindah kamar memuat field yang tidak diizinkan: '.implode(', ',$unexpectedTransferKeys).'.']);
                    break;
                }
                foreach(['targetRoomNumber','rateMode','keyDisposition','keyReason'] as $scalarKey){
                    if(array_key_exists($scalarKey,$input) && $input[$scalarKey]!==null && !is_scalar($input[$scalarKey])){
                        http_response_code(422);
                        echo json_encode(['success'=>false,'message'=>'Field '.$scalarKey.' pada command pindah kamar harus berupa nilai scalar.']);
                        break 2;
                    }
                }
                $targetRoomNumber=trim((string)($input['targetRoomNumber'] ?? ''));
                $transferRateMode=strtolower(trim((string)($input['rateMode'] ?? '')));
                $transferSurcharge=$input['surchargeAmount'] ?? 0;
                $expectedTransferVersion=filter_var($input['expectedVersion'] ?? null,FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if($targetRoomNumber==='' || strlen($targetRoomNumber)>100){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'message'=>'Kamar tujuan pindah kamar tidak valid.']);
                    break;
                }
                if(!in_array($transferRateMode,['keep','update'],true)){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'message'=>'Mode tarif pindah kamar wajib keep atau update.']);
                    break;
                }
                if(!is_numeric($transferSurcharge) || (float)$transferSurcharge<0 || (float)$transferSurcharge>1000000000000){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'message'=>'Biaya tambahan pindah kamar tidak valid.']);
                    break;
                }
                if($expectedTransferVersion===false){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'message'=>'Versi booking wajib disertakan untuk mencegah pindah kamar dari data yang sudah berubah.']);
                    break;
                }
                // Deliberately minimal. In particular: no extras, totalAmount,
                // guest identity, tax fields, payment fields, or stay-window fields.
                $bData=['roomNumber'=>$targetRoomNumber];
                $clientTx=null;
                $transferContext=[
                    'rateMode'=>$transferRateMode,
                    'surchargeAmount'=>round((float)$transferSurcharge,2),
                    'keyDisposition'=>$input['keyDisposition'] ?? null,
                    'keyReason'=>trim((string)($input['keyReason'] ?? '')),
                    'operationId'=>trim((string)($GLOBALS['tamasya_request_operation_id'] ?? '')),
                    'expectedVersion'=>(int)$expectedTransferVersion,
                ];
            } else {
                $bData = $input['booking'] ?? $input;
                $clientTx = $input['transaction'] ?? null;
                $transferContext = is_array($input['transferContext'] ?? null) ? $input['transferContext'] : [];
            }
            $deferredTransferSmartLockJobId = null;
            $deferredStayWindowSmartLockJobId = null;
            $transferLifecycleResult = null;
            $deferredBookingTransactionTelegramMessage = null;
            $txId = null;
            $serverTransferFinance = null;
            
            try {
                $pdo->beginTransaction();
                
                // Get current booking first to check for any changes
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $oldBooking = $stmt->fetch();
                
                if (!$oldBooking) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Booking tidak ditemukan!"]);
                    $pdo->rollBack();
                    break;
                }
                $oldLifecycleStatus = strtolower((string)($oldBooking['status'] ?? ''));
                if (!in_array($oldLifecycleStatus, ['reserved','active'], true)) {
                    http_response_code(409);
                    throw new RuntimeException('Hanya reservasi menunggu check-in atau booking aktif yang boleh diperbarui. Booking selesai/batal harus dikoreksi melalui workflow audit.');
                }
                $editCheckinTime='14:00:00';
                $editCheckoutTime='12:00:00';
                try{
                    $editSettingsStmt=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1");
                    $editSettings=$editSettingsStmt?$editSettingsStmt->fetch(PDO::FETCH_ASSOC):false;
                    if($editSettings){
                        $editCheckinTime=substr((string)($editSettings['checkin_time']??'14:00:00'),0,8);
                        $editCheckoutTime=substr((string)($editSettings['checkout_time']??'12:00:00'),0,8);
                    }
                }catch(Throwable $ignoredEditOperationalClock){}
                try{
                    $oldStayWindow=resolveHotelBookingStayWindow($oldBooking,$editCheckoutTime,$editCheckinTime);
                    $effectiveStayWindow=resolveHotelBookingStayWindow(array_merge($oldBooking,$bData),$editCheckoutTime,$editCheckinTime);
                }catch(InvalidArgumentException $stayWindowError){
                    http_response_code(422);
                    throw $stayWindowError;
                }
                $effectiveCheckIn=(string)$effectiveStayWindow['checkIn'];
                $effectiveCheckOut=(string)$effectiveStayWindow['checkOut'];
                $effectiveOpenEnded=(bool)$effectiveStayWindow['isOpenEnded'];
                $effectiveStayMode=(string)$effectiveStayWindow['stayMode'];
                $effectiveScheduledCheckInAt=$effectiveStayWindow['scheduledCheckInAt'];
                $effectiveScheduledCheckOutAt=$effectiveStayWindow['scheduledCheckOutAt'];
                $effectiveStartAt=(string)$effectiveStayWindow['startAt'];
                $effectiveEndAt=(string)$effectiveStayWindow['endAt'];
                $effectiveTotal=(float)($bData['totalAmount']??$oldBooking['totalAmount']??0);
                if($effectiveTotal<0||$effectiveTotal>1000000000000){
                    http_response_code(422);
                    throw new InvalidArgumentException('Total booking hasil perubahan tidak valid.');
                }
                $ledgerBeforeUpdate=bookingLedgerTotals($pdo,$id);
                if($effectiveTotal+0.01<$ledgerBeforeUpdate['net']){
                    http_response_code(409);
                    throw new RuntimeException('Total booking tidak boleh lebih kecil dari penerimaan bersih yang sudah masuk. Proses refund/penyesuaian terlebih dahulu.');
                }

                $oldRoomNumber = (string)($oldBooking['roomNumber'] ?? '');
                $newRoomNumber = trim((string)($bData['roomNumber'] ?? $oldRoomNumber));
                $roomTransfer = $newRoomNumber !== '' && $newRoomNumber !== $oldRoomNumber;
                if($isDedicatedRoomTransfer){
                    if($oldLifecycleStatus!=='active'){
                        http_response_code(409);
                        throw new RuntimeException('Endpoint pindah kamar aktif hanya untuk tamu yang sudah check-in. Reservasi mendatang dipindahkan melalui edit reservasi.');
                    }
                    if(!$roomTransfer){
                        http_response_code(409);
                        throw new RuntimeException('Kamar tujuan harus berbeda dari kamar aktif saat ini.');
                    }
                    $expectedVersion=(int)($transferContext['expectedVersion'] ?? 0);
                    if($expectedVersion<=0 || (int)($oldBooking['version'] ?? 0)!==$expectedVersion){
                        http_response_code(409);
                        throw new RuntimeException('Data booking sudah berubah sejak form pindah kamar dibuka. Muat ulang data kamar lalu ulangi agar tidak menimpa perubahan user lain.');
                    }
                } elseif($roomTransfer && $oldLifecycleStatus==='active') {
                    http_response_code(409);
                    throw new RuntimeException('Pindah kamar booking aktif tidak boleh melalui edit booking generik. Gunakan command /api/room-transfers/{bookingId}.');
                }
                $targetRoom = null;
                $sourceRoom = null;

                // Financial fields are server-governed. A normal detail edit may
                // not silently rewrite the total or extras. When a booking charge
                // is supplied, its amount must exactly explain the total delta and
                // an extra payload may add exactly one immutable line item.
                $oldTotalAmount=round((float)($oldBooking['totalAmount'] ?? 0),2);
                $requestedTotalAmount=round((float)($bData['totalAmount'] ?? $oldTotalAmount),2);
                $oldExtras=tamasyaDecodeBookingExtras($oldBooking['extras'] ?? null);
                $requestedExtras=array_key_exists('extras',$bData)?tamasyaDecodeBookingExtras($bData['extras']):$oldExtras;
                $semanticAction=null;
                $semanticAmount=0.0;
                $semanticTax=null;
                $semanticRecordCash=false;
                $semanticSource=trim((string)($bData['bookingSource'] ?? $oldBooking['bookingSource'] ?? 'Direct')) ?: 'Direct';
                $semanticDate=date('Y-m-d');
                $semanticOperationId='';
                $canonicalExtras=static function(array $extras): string {
                    usort($extras,static fn($a,$b)=>strcmp((string)($a['id']??''),(string)($b['id']??'')));
                    return json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '[]';
                };
                if(!$clientTx){
                    if(array_key_exists('totalAmount',$bData) && !moneyMatches($requestedTotalAmount,$oldTotalAmount,0.01)){
                        http_response_code(409);
                        throw new RuntimeException('Total booking tidak boleh diubah melalui edit detail biasa. Gunakan transaksi biaya/refund atau koreksi audit.');
                    }
                    if(array_key_exists('extras',$bData) && $canonicalExtras($requestedExtras)!==$canonicalExtras($oldExtras)){
                        http_response_code(409);
                        throw new RuntimeException('Daftar layanan booking tidak boleh diubah tanpa transaksi biaya yang sesuai.');
                    }
                }else{
                    if(!is_array($clientTx)){
                        http_response_code(422);
                        throw new InvalidArgumentException('Payload transaksi booking tidak valid.');
                    }
                    $semanticAmount=round((float)($clientTx['amount'] ?? 0),2);
                    if($semanticAmount<=0 || $semanticAmount>1000000000000){
                        http_response_code(422);
                        throw new InvalidArgumentException('Nominal transaksi biaya booking tidak valid.');
                    }
                    $semanticAction=$roomTransfer?'transfer':inferBookingChargeAction($clientTx);
                    if($semanticAction==='extension'&&($clientTx['priceMode']??'')==='negotiated'&&tamasyaStringLength(trim((string)($clientTx['negotiationReason']??'')))<5)throw new InvalidArgumentException('Alasan harga nego perpanjangan minimal 5 karakter.');
                    $semanticRecordCash=!array_key_exists('recordCash',$clientTx)
                        || !in_array(strtolower(trim((string)$clientTx['recordCash'])),['0','false','no','off',''],true);
                    $semanticDate=validIsoDate((string)($clientTx['date']??''))?(string)$clientTx['date']:date('Y-m-d');
                    $semanticOperationId=trim((string)($clientTx['operationId']??''));
                    $expectedTotalAmount=round($oldTotalAmount+$semanticAmount,2);
                    if(!moneyMatches($requestedTotalAmount,$expectedTotalAmount,0.01)){
                        http_response_code(409);
                        throw new RuntimeException('Total booking harus bertambah tepat sebesar transaksi biaya: Rp '.number_format($semanticAmount,0,',','.').'.');
                    }
                    if($semanticAction==='extra'){
                        $newExtraId=trim((string)($clientTx['bookingExtraId'] ?? ''));
                        if($newExtraId===''){
                            http_response_code(422);
                            throw new InvalidArgumentException('ID layanan extra wajib dan harus sama pada booking serta transaksi.');
                        }
                        $oldById=[];foreach($oldExtras as $extraRow){$extraId=(string)($extraRow['id']??'');if($extraId!=='')$oldById[$extraId]=$extraRow;}
                        $newById=[];foreach($requestedExtras as $extraRow){$extraId=(string)($extraRow['id']??'');if($extraId!=='')$newById[$extraId]=$extraRow;}
                        if(isset($oldById[$newExtraId]) || !isset($newById[$newExtraId]) || count($newById)!==count($oldById)+1){
                            http_response_code(409);
                            throw new RuntimeException('Transaksi extra harus menambahkan tepat satu layanan baru dan tidak boleh menimpa layanan lama.');
                        }
                        foreach($oldById as $extraId=>$oldExtraRow){
                            if(!isset($newById[$extraId]) || $canonicalExtras([$newById[$extraId]])!==$canonicalExtras([$oldExtraRow])){
                                http_response_code(409);
                                throw new RuntimeException('Layanan lama tidak boleh diubah saat menambahkan pembayaran extra baru.');
                            }
                        }
                        $newExtra=$newById[$newExtraId];
                        $newExtraAmount=isset($newExtra['total'])&&is_numeric($newExtra['total'])
                            ? round(max(0,(float)$newExtra['total']),2)
                            : round(max(0,(float)($newExtra['price']??0))*max(1,(int)($newExtra['qty']??1)),2);
                        if(!moneyMatches($newExtraAmount,$semanticAmount,0.01)){
                            http_response_code(409);
                            throw new RuntimeException('Nilai layanan extra harus sama dengan nominal transaksi booking.');
                        }
                    }elseif(array_key_exists('extras',$bData) && $canonicalExtras($requestedExtras)!==$canonicalExtras($oldExtras)){
                        http_response_code(409);
                        throw new RuntimeException('Daftar layanan hanya boleh berubah pada transaksi extra.');
                    }
                    if($semanticAction==='extension' && strtotime($effectiveEndAt)<=strtotime((string)$oldStayWindow['endAt'])){
                        http_response_code(409);
                        throw new RuntimeException('Biaya perpanjangan wajib disertai waktu checkout yang lebih lambat.');
                    }

                    $semanticTax=resolveBookingChargeTaxPolicy(
                        array_merge($oldBooking,$bData,['bookingSource'=>$semanticSource]),
                        array_merge($clientTx,[
                            'action'=>$semanticAction,
                            'bookingSource'=>$semanticSource,
                            'transactionKind'=>'booking_charge',
                            'date'=>$semanticDate
                        ]),
                        $semanticAmount,
                        $pdo
                    );
                    if($semanticAction==='extra'){
                        foreach($requestedExtras as &$requestedExtra){
                            if((string)($requestedExtra['id']??'')!==$newExtraId)continue;
                            $requestedExtra['total']=$semanticAmount;
                            $requestedExtra['baseAmount']=$semanticTax['baseAmount'];
                            $requestedExtra['taxAmount']=$semanticTax['taxAmount'];
                            $requestedExtra['taxRate']=$semanticTax['taxRate'];
                            $requestedExtra['paymentStatus']=$semanticRecordCash?'paid':'unpaid';
                            $requestedExtra['paymentOperationId']=$semanticOperationId?:null;
                            break;
                        }
                        unset($requestedExtra);
                        $bData['extras']=$requestedExtras;
                    }
                    if(!$semanticRecordCash)$bData['paymentStatus']='unpaid';
                }

                if (array_key_exists('status',$bData) && (string)$bData['status'] !== (string)$oldBooking['status']) {
                    http_response_code(409);
                    throw new RuntimeException('Perubahan status booking wajib melalui endpoint status khusus agar pembayaran, kunci, housekeeping, audit, dan sinkronisasi diproses bersama.');
                }

                // Kunci kamar sumber dan kamar efektif dalam transaksi yang sama. Pemeriksaan
                // benturan tanggal wajib dilakukan walaupun hanya tanggal yang diedit, sebab
                // dua perangkat dapat mengubah reservasi yang berbeda secara bersamaan.
                if ($oldRoomNumber !== '') {
                    $stmtSourceRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
                    $stmtSourceRoom->execute([$oldRoomNumber]);
                    $sourceRoom = $stmtSourceRoom->fetch();
                    if (!$sourceRoom) throw new RuntimeException('Kamar asal booking tidak ditemukan.');
                }

                if ($newRoomNumber === '') {
                    http_response_code(422);
                    throw new InvalidArgumentException('Nomor kamar hasil perubahan tidak boleh kosong.');
                }
                if ($roomTransfer) {
                    $stmtTargetRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
                    $stmtTargetRoom->execute([$newRoomNumber]);
                    $targetRoom = $stmtTargetRoom->fetch();
                } else {
                    $targetRoom = $sourceRoom;
                }
                if (!$targetRoom) throw new RuntimeException('Kamar tujuan tidak ditemukan.');

                $targetBlockers=getRoomOperationalBlockers($pdo,$newRoomNumber,true);
                if($oldLifecycleStatus==='active' && $roomTransfer){
                    // Perpindahan tamu fisik membutuhkan kamar yang benar-benar siap sekarang.
                    if(tamasyaDeriveRoomOperationalStatus($targetBlockers)!=='available'){
                        http_response_code(409);
                        throw new RuntimeException(roomOperationalBlockerMessage($newRoomNumber,$targetBlockers) ?: 'Kamar tujuan belum siap untuk perpindahan tamu aktif.');
                    }
                }else{
                    // Reservasi/edit masa depan mengabaikan occupancy/HK saat ini dan hanya
                    // menolak blocker exceptional; overlap waktu diuji terpisah di bawah.
                    $reservationBlockers=tamasyaReservationInventoryBlockers($targetBlockers);
                    if($reservationBlockers){
                        http_response_code(409);
                        throw new RuntimeException('Kamar tujuan belum dapat menerima reservasi. '.roomOperationalBlockerMessage($newRoomNumber,$reservationBlockers));
                    }
                }

                tamasyaR3AssertStayWindowNoOverlap($pdo,(string)$id,$newRoomNumber,$effectiveStartAt,$effectiveEndAt,true);

                // R3 invariant: historical check-in of an active stay is immutable in the
                // normal edit route. Active room transfer and stay-window mutation are also
                // split into separate operations so access/occupancy lifecycle cannot race.
                $oldStartTs=strtotime((string)$oldStayWindow['startAt']);
                $newStartTs=strtotime($effectiveStartAt);
                $oldEndTs=strtotime((string)$oldStayWindow['endAt']);
                $newEndTs=strtotime($effectiveEndAt);
                $activeStayWindowChanged=$oldLifecycleStatus==='active' && ($oldStartTs!==$newStartTs || $oldEndTs!==$newEndTs);
                if($oldLifecycleStatus==='active' && $oldStartTs!==$newStartTs){
                    http_response_code(409);
                    throw new RuntimeException('Waktu mulai booking aktif tidak boleh diubah melalui edit biasa. Gunakan koreksi audit agar histori check-in tetap konsisten.');
                }
                if($oldLifecycleStatus==='active' && $oldEndTs!==false && $newEndTs!==false && $newEndTs<$oldEndTs){
                    http_response_code(409);
                    throw new RuntimeException('Masa inap booking aktif tidak boleh dipersingkat melalui edit biasa. Gunakan checkout/koreksi audit sesuai kejadian sebenarnya.');
                }
                if($roomTransfer && $activeStayWindowChanged){
                    http_response_code(409);
                    throw new RuntimeException('Pindah kamar aktif dan perubahan masa inap harus dilakukan sebagai dua operasi terpisah agar okupansi, folio, dan akses kamar tetap konsisten.');
                }
                if($oldLifecycleStatus==='active' && !$roomTransfer && $oldEndTs!==$newEndTs){
                    $newCheckoutDueAt=trim((string)($effectiveStayWindow['checkoutDueAt']??''));
                    $accessMode=strtolower(trim((string)($oldBooking['accessMode']??'')));
                    if($newCheckoutDueAt==='' && in_array($accessMode,['smart','hybrid'],true) && strtolower((string)($oldBooking['keyControlStatus']??''))==='issued') {
                        http_response_code(409);
                        throw new RuntimeException('Booking aktif dengan smart-lock tidak boleh menjadi open-ended tanpa masa berlaku akses yang pasti.');
                    }
                    if($newCheckoutDueAt!=='') {
                        $deferredStayWindowSmartLockJobId=tamasyaR3QueueSmartLockValidityRefresh($pdo,$loggedInStaff,$oldBooking,$newCheckoutDueAt,'web-booking-edit');
                    }
                }

                // FINAL5B: web room-transfer rate/surcharge is server-authoritative and NON-CASH.
                // The browser sends only intent (keep/update + surcharge); server derives
                // remaining nights, effective booked nightly base, target room base, PBJT,
                // gross delta, and overpayment safety before changing the folio snapshot.
                $transferRateMode=strtolower(trim((string)($transferContext['rateMode'] ?? '')));
                $hasTransferFinanceIntent=$roomTransfer && ($transferRateMode!=='' || array_key_exists('surchargeAmount',$transferContext));
                if($hasTransferFinanceIntent){
                    if($clientTx){
                        http_response_code(409);
                        throw new RuntimeException('Pindah kamar FINAL5B tidak menerima transaksi kas bersamaan dengan penyesuaian tarif. Biaya pindah masuk folio dan dibayar melalui pembayaran booking/checkout.');
                    }
                    $serverTransferFinance=tamasyaBuildRoomTransferFinancialPlan(
                        $pdo,$oldBooking,$sourceRoom,$targetRoom,
                        $transferRateMode!==''?$transferRateMode:'keep',
                        round((float)($transferContext['surchargeAmount'] ?? 0),2),
                        date('Y-m-d')
                    );
                    $bData['totalAmount']=$serverTransferFinance['newTotalAmount'];
                }
                $bData['roomNumber'] = $newRoomNumber;
                $bData['roomType'] = (string)($targetRoom['type'] ?? ($bData['roomType'] ?? $oldBooking['roomType'] ?? ''));
                
                // Fields to update
                $fieldsToUpdate = [];
                $params = [];
                
                if (isset($bData['guestName'])) { $fieldsToUpdate[] = "guestName = ?"; $params[] = $bData['guestName']; }
                if (isset($bData['guestEmail'])) { $fieldsToUpdate[] = "guestEmail = ?"; $params[] = $bData['guestEmail']; }
                if (isset($bData['guestPhone'])) { $fieldsToUpdate[] = "guestPhone = ?"; $params[] = $bData['guestPhone']; }
                if (isset($bData['roomNumber'])) { $fieldsToUpdate[] = "roomNumber = ?"; $params[] = $bData['roomNumber']; }
                if (isset($bData['roomType'])) { $fieldsToUpdate[] = "roomType = ?"; $params[] = $bData['roomType']; }
                if (isset($bData['checkIn'])) { $fieldsToUpdate[] = "checkIn = ?"; $params[] = $bData['checkIn']; }
                if (isset($bData['checkOut'])) { $fieldsToUpdate[] = "checkOut = ?"; $params[] = $bData['checkOut']; }
                if (isset($bData['totalAmount'])) { $fieldsToUpdate[] = "totalAmount = ?"; $params[] = (int)round(floatval($bData['totalAmount'])); }
                if (isset($bData['bookingSource'])) { $fieldsToUpdate[] = "bookingSource = ?"; $params[] = trim((string)$bData['bookingSource']) ?: 'Direct'; }
                // vatRate/vatAmount dari browser tidak pernah dipercaya. Biaya baru
                // ditambahkan sebagai snapshot komponennya; edit sumber/tanggal tanpa
                // biaya menyusun ulang agregat kamar + extra, bukan memajaki seluruh
                // booking menggunakan tarif kamar saja.
                $taxDriverChanged = array_key_exists('totalAmount',$bData)
                    || array_key_exists('bookingSource',$bData)
                    || array_key_exists('checkIn',$bData);
                if ($serverTransferFinance!==null) {
                    $fieldsToUpdate[] = "vatRate = ?"; $params[] = $serverTransferFinance['newVatRate'];
                    $fieldsToUpdate[] = "vatAmount = ?"; $params[] = (float)$serverTransferFinance['newVatAmount'];
                } elseif ($clientTx && is_array($semanticTax)) {
                    $oldVatAmount=max(0.0,(float)($oldBooking['vatAmount']??0));
                    $oldVatRate=is_numeric($oldBooking['vatRate']??null)?(float)$oldBooking['vatRate']:null;
                    $chargeRate=(float)$semanticTax['taxRate'];
                    $newVatAmount=round($oldVatAmount+(float)$semanticTax['taxAmount'],2);
                    $newVatRate=$oldTotalAmount<=0.0001
                        ? $chargeRate
                        : (($oldVatRate!==null&&abs($oldVatRate-$chargeRate)<0.0001)?$oldVatRate:null);
                    $fieldsToUpdate[] = "vatRate = ?"; $params[] = $newVatRate;
                    $fieldsToUpdate[] = "vatAmount = ?"; $params[] = $newVatAmount;
                } elseif ($taxDriverChanged) {
                    $aggregateBooking=array_merge($oldBooking,$bData,[
                        'bookingSource'=>trim((string)($bData['bookingSource']??$oldBooking['bookingSource']??'Direct'))?:'Direct',
                        'extras'=>$bData['extras']??$oldBooking['extras']??null
                    ]);
                    $serverTax=resolveBookingAggregateTaxSnapshot(
                        $pdo,$aggregateBooking,
                        (float)($bData['totalAmount']??$oldBooking['totalAmount']??0),
                        (string)($bData['checkIn']??$oldBooking['checkIn']??date('Y-m-d'))
                    );
                    $fieldsToUpdate[] = "vatRate = ?"; $params[] = $serverTax['taxRate'];
                    $fieldsToUpdate[] = "vatAmount = ?"; $params[] = (float)$serverTax['taxAmount'];
                    if(!array_key_exists('extras',$bData) && $serverTax['extras'])$bData['extras']=$serverTax['extras'];
                }
                if (array_key_exists('ktpPhoto',$bData) && trim((string)($bData['ktpPhoto'] ?? '')) !== '') {
                    $fieldsToUpdate[] = "ktpPhoto = ?";
                    $params[] = normalizeBookingIdentityReferenceForStorage($pdo,$bData['ktpPhoto']);
                }
                if (isset($bData['isSplitPayment'])) { $fieldsToUpdate[] = "isSplitPayment = ?"; $params[] = (int)$bData['isSplitPayment']; }
                if (isset($bData['splitCashAmount'])) { $fieldsToUpdate[] = "splitCashAmount = ?"; $params[] = $bData['splitCashAmount'] === "" ? null : floatval($bData['splitCashAmount']); }
                if (isset($bData['splitTransferAmount'])) { $fieldsToUpdate[] = "splitTransferAmount = ?"; $params[] = $bData['splitTransferAmount'] === "" ? null : floatval($bData['splitTransferAmount']); }
                if (isset($bData['splitTransferBankAccountId'])) { $fieldsToUpdate[] = "splitTransferBankAccountId = ?"; $params[] = empty($bData['splitTransferBankAccountId']) ? null : $bData['splitTransferBankAccountId']; }
                $stayWindowDriverChanged=array_key_exists('checkIn',$bData)||array_key_exists('checkOut',$bData)
                    ||array_key_exists('isOpenEnded',$bData)||array_key_exists('stayMode',$bData)
                    ||array_key_exists('scheduledCheckInAt',$bData)||array_key_exists('scheduledCheckOutAt',$bData);
                if ($stayWindowDriverChanged) {
                    $fieldsToUpdate[] = "isOpenEnded = ?"; $params[] = $effectiveOpenEnded?1:0;
                    $fieldsToUpdate[] = "stayMode = ?"; $params[] = $effectiveStayMode;
                    $fieldsToUpdate[] = "scheduledCheckInAt = ?"; $params[] = $effectiveScheduledCheckInAt;
                    $fieldsToUpdate[] = "scheduledCheckOutAt = ?"; $params[] = $effectiveScheduledCheckOutAt;
                    $fieldsToUpdate[] = "checkoutDueAt = ?"; $params[] = $effectiveStayWindow['checkoutDueAt'];
                }
                if (isset($bData['extras'])) { 
                    $fieldsToUpdate[] = "extras = ?"; 
                    $params[] = is_array($bData['extras']) ? json_encode($bData['extras']) : $bData['extras']; 
                }
                
                if (!empty($fieldsToUpdate)) {
                    // Setiap mutasi booking harus menaikkan versi dan mencatat aktor/sumber
                    // agar conflict detection, audit, serta replikasi node tidak melihat row stale.
                    $fieldsToUpdate[] = "version = version + 1";
                    $fieldsToUpdate[] = "updatedAt = CURRENT_TIMESTAMP";
                    $fieldsToUpdate[] = "updatedBy = ?"; $params[] = (string)($loggedInStaff['id'] ?? '');
                    $fieldsToUpdate[] = "updatedSource = 'web'";
                    $params[] = $id;
                    $sql = "UPDATE bookings SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
                
                // Jika edit membawa transaksi tambahan, transaksi tersebut merupakan
                // penerimaan biaya/perpanjangan/extra, bukan pengeluaran atau refund.
                if ($clientTx) {
                    $requestedKind = normalizeTransactionKind((string)($clientTx['transactionKind'] ?? inferTransactionKind($clientTx)));
                    if ($requestedKind === 'down_payment') {
                        http_response_code(409);
                        throw new RuntimeException('Panjar booking wajib dicatat melalui menu Terima Panjar / endpoint booking-payments.');
                    }
                    $txType = strtolower(trim((string)($clientTx['type'] ?? 'income')));
                    $txAmt = round(floatval($clientTx['amount'] ?? 0),2);
                    $txDate = $semanticDate;
                    $txOperationId = $semanticOperationId;
                    if($txType!=='income'){
                        http_response_code(422);
                        throw new InvalidArgumentException('Biaya booking dari menu reservasi wajib berupa pemasukan. Refund hanya melalui pembatalan.');
                    }
                    if($txAmt<=0||$txAmt>1000000000000||!validIsoDate($txDate)){
                        http_response_code(422);
                        throw new InvalidArgumentException('Nominal atau tanggal transaksi booking tidak valid.');
                    }
                    if($txOperationId===''||strlen($txOperationId)>100){
                        http_response_code(422);
                        throw new InvalidArgumentException('Operation ID transaksi booking wajib dan maksimal 100 karakter.');
                    }
                    $txCat = trim((string)($clientTx['category'] ?? getRoomRentalCategoryName($pdo))) ?: getRoomRentalCategoryName($pdo);
                    $txSub = $clientTx['subcategory'] ?? '';
                    $txRoom = $clientTx['roomNumber'] ?? ($bData['roomNumber'] ?? $oldBooking['roomNumber']);
                    $txDesc = trim((string)($clientTx['description'] ?? 'Transaksi tambahan dari edit booking'));
                    if($txDesc==='')$txDesc='Transaksi tambahan dari edit booking';
                    $txBy = currentStaffLabel($loggedInStaff);
                    // Akun transaksi tambahan tidak boleh diwarisi diam-diam dari pembayaran awal.
                    // FINAL8: pembayaran extra/perpanjangan memakai metode eksplisit. Tunai
                    // tidak boleh membawa bankAccountId; transfer wajib bank; QRIS wajib edc_qris.
                    $txBank = array_key_exists('bankAccountId',$clientTx) && trim((string)$clientTx['bankAccountId'])!=='' ? trim((string)$clientTx['bankAccountId']) : null;
                    $txPaymentMethod = tamasyaNormalizePaymentMethod((string)($clientTx['paymentMethod'] ?? ''));
                    if(!$semanticRecordCash){
                        $txBank=null;
                        $txPaymentMethod='';
                    }else{
                        if($txPaymentMethod===''){
                            // Compatibility boundary only: old clients may send an account without
                            // paymentMethod. Infer once through the canonical account registry.
                            $txPaymentMethod=$txBank!==null
                                ? tamasyaInferPaymentMethodFromAccount($pdo,$txBank,true,['transfer','qris'])
                                : 'cash';
                        }
                        $txBank=tamasyaResolvePaymentAccount($pdo,$txPaymentMethod,$txBank,[
                            'allowedMethods'=>['cash','transfer','qris'],'lock'=>true,'context'=>'Pembayaran extra/perpanjangan booking'
                        ]);
                    }
                    $txSource = $semanticSource;
                    $txProof = $clientTx['proofUrl'] ?? null;
                    $txAction = $semanticAction ?: ($roomTransfer ? 'transfer' : inferBookingChargeAction($clientTx));
                    if ($roomTransfer) {
                        $txCat = getRoomRentalCategoryName($pdo);
                        $txRoom = $newRoomNumber;
                        $txSub = (string)($targetRoom['type'] ?? $bData['roomType'] ?? $oldBooking['roomType'] ?? '');
                        $txDesc = "Biaya Pindah Kamar {$oldRoomNumber} → {$newRoomNumber} - " . ($bData['guestName'] ?? $oldBooking['guestName']);
                    }
                    $tax=is_array($semanticTax)?$semanticTax:resolveBookingChargeTaxPolicy(
                        array_merge($oldBooking,$bData,['bookingSource'=>$txSource]),
                        array_merge($clientTx,['action'=>$txAction,'bookingSource'=>$txSource,'date'=>$txDate,'transactionKind'=>'booking_charge']),
                        $txAmt,$pdo
                    );

                    if($semanticRecordCash){
                        // Remember whether this operation already exists only so retries do not
                        // emit duplicate notifications.  The posting authority itself validates
                        // the complete economic replay identity before it may reuse the row.
                        $duplicateTx=$pdo->prepare("SELECT id FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");
                        $duplicateTx->execute([$txOperationId]);
                        $existingTx=$duplicateTx->fetch(PDO::FETCH_ASSOC)?:null;
                        $txId = $existingTx ? (string)$existingTx['id'] : generateServerId('tx_booking');
                        $bookingSemanticKey=$txAction==='extra'?'extra_service':'room_rental';
                        $bookingChargeRequiresShift=false;
                        $bookingChargeShiftSessionId=null;
                        if(!$existingTx){
                            $bookingChargeRequiresShift=tamasyaManualTransactionRequiresOpenShift(['type'=>'income','amount'=>$txAmt,'bankAccountId'=>$txBank]);
                            $bookingChargeShiftSessionId=resolveOpenShiftSessionId($pdo,$loggedInStaff,$bookingChargeRequiresShift);
                        }
                        $txId=tamasyaPostFinancialTransaction($pdo,[
                            'id'=>$txId,'type'=>'income','category'=>$txCat,'categorySystemKey'=>$bookingSemanticKey,'subcategory'=>$txSub,'roomNumber'=>$txRoom,
                            'amount'=>$txAmt,'date'=>$txDate,'description'=>$txDesc,'proofUrl'=>$txProof,'bankAccountId'=>$txBank,'bookingId'=>$id,
                            'createdBy'=>$txBy,'bookingSource'=>$txSource,'baseAmount'=>$tax['baseAmount'],'taxAmount'=>$tax['taxAmount'],'taxRate'=>$tax['taxRate'],
                            'taxSnapshotStatus'=>$tax['taxSnapshotStatus'],'taxSource'=>$tax['taxSource'],'taxRuleId'=>$tax['taxRuleId'],
                            'transactionKind'=>'booking_charge','sourceEntity'=>'booking','sourceEntityId'=>$id,'isSystemGenerated'=>1,
                            'operationId'=>$txOperationId,'shiftSessionId'=>$bookingChargeShiftSessionId,'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
                        ],$loggedInStaff,'booking',['lockCatalog'=>true,'source'=>'web','idempotentByOperationId'=>true]);
                        if($bookingChargeShiftSessionId!==null) attachTransactionToActorShift($pdo,$txId,$loggedInStaff,$bookingChargeRequiresShift);
                        if ($txAction === 'extra') {
                            tamasyaAttachBookingExtraPayment(
                                $pdo,(string)$id,
                                isset($clientTx['bookingExtraId']) ? (string)$clientTx['bookingExtraId'] : null,
                                (string)$txId,$txOperationId
                            );
                        }
                    }else{
                        $existingTx=null;
                        $txId=null;
                    }
                    if($semanticRecordCash&&!$existingTx){
                        $taxText = (float)$tax['taxRate'] > 0
                            ? "
🏷️ PBJT {$tax['taxRate']}%: *Rp " . number_format((float)$tax['taxAmount'],0,',','.') . '*'
                            : "
🏷️ PBJT: *Tidak dikenakan*";
                        $tgMsgTx = "📥 *PEMASUKAN BARU* (Booking Update)

" .
                                   "📁 Kategori: *{$txCat}* " . ($txSub ? " > {$txSub}" : '') . "
" .
                                   "💰 Nominal: *Rp " . number_format($txAmt, 0, ',', '.') . "*{$taxText}
" .
                                   "📝 Deskripsi: *{$txDesc}*
" .
                                   "👤 Dicatat: *{$txBy}*
📅 Tanggal: {$txDate}";
                        $deferredBookingTransactionTelegramMessage = $tgMsgTx;
                    }
                }

                // Status fisik kamar hanya berubah ketika tamu memang sedang menginap.
                // Memindahkan reservasi mendatang tidak boleh mengubah kondisi kamar saat ini.
                if ($roomTransfer && $oldLifecycleStatus === 'active') {
                    $transferLifecycleResult = finalizeActiveRoomTransferOperationalLifecycle(
                        $pdo,$loggedInStaff,$oldBooking,$newRoomNumber,'web-room-transfer',[
                            'keyDisposition'=>$transferContext['keyDisposition'] ?? null,
                            'keyReason'=>$transferContext['keyReason'] ?? '',
                            'operationId'=>$transferContext['operationId'] ?? ($clientTx['operationId'] ?? null)
                        ]
                    );
                    $deferredTransferSmartLockJobId = $transferLifecycleResult['smartLockJobId'] ?? null;
                }
                if ($roomTransfer) {
                    tamasyaSyncGuestServiceBookingLifecycle($pdo,$loggedInStaff,(string)$id,$oldLifecycleStatus,$newRoomNumber,'web-room-transfer');
                }
                
                // Add notification dan audit yang membedakan kamar asal dari kamar aktif saat ini.
                $notifId = generateServerId('n');
                $guestN = $bData['guestName'] ?? $oldBooking['guestName'];
                $roomN = $roomTransfer ? $newRoomNumber : ($bData['roomNumber'] ?? $oldBooking['roomNumber']);
                $lifecycleLabel = $oldLifecycleStatus === 'reserved' ? 'Reservasi' : 'Pemesanan aktif';
                $notifMsg = $roomTransfer
                    ? ($oldLifecycleStatus === 'reserved'
                        ? "Reservasi {$guestN} dipindahkan dari Kamar {$oldRoomNumber} ke Kamar {$newRoomNumber}."
                        : "Tamu {$guestN} dipindahkan dari Kamar {$oldRoomNumber} ke Kamar {$newRoomNumber}.")
                    : "{$lifecycleLabel} Kamar {$roomN} ({$guestN}) berhasil diperbarui.";
                $stmt = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'booking')");
                $stmt->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);
                
                $logAction = $roomTransfer ? "room_transfer" : "update_booking";
                $logDesc = $roomTransfer
                    ? ($oldLifecycleStatus === 'reserved'
                        ? "Pindah alokasi reservasi {$id}: Kamar {$oldRoomNumber} → Kamar {$newRoomNumber} atas nama {$guestN}"
                        : "Pindah kamar Booking {$id}: Kamar {$oldRoomNumber} → Kamar {$newRoomNumber} atas nama {$guestN}")
                    : "Pembaruan detail {$lifecycleLabel} Kamar {$roomN} atas nama {$guestN}";
                logActivity($pdo, $logAction, $logDesc, $loggedInStaff['id'] ?? null, $loggedInStaff['name'] ?? null);
                // Field agregat pembayaran dan status lunas merupakan proyeksi server.
                recalculateBookingFinancials($pdo,$id,true);
                assertBookingLedgerInvariant($pdo,$id,$loggedInStaff,'web',false);

                $updatedBookingStmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
                $updatedBookingStmt->execute([$id]);
                $updatedBooking = $updatedBookingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $auditBefore = [
                    'guestName'=>$oldBooking['guestName'] ?? null,
                    'guestEmail'=>$oldBooking['guestEmail'] ?? null,
                    'guestPhone'=>$oldBooking['guestPhone'] ?? null,
                    'roomNumber'=>$oldBooking['roomNumber'] ?? null,
                    'roomType'=>$oldBooking['roomType'] ?? null,
                    'checkIn'=>$oldBooking['checkIn'] ?? null,
                    'checkOut'=>$oldBooking['checkOut'] ?? null,
                    'totalAmount'=>$oldBooking['totalAmount'] ?? null,
                    'bookingSource'=>$oldBooking['bookingSource'] ?? null,
                    'status'=>$oldBooking['status'] ?? null,
                ];
                $auditAfter = [
                    'guestName'=>$updatedBooking['guestName'] ?? null,
                    'guestEmail'=>$updatedBooking['guestEmail'] ?? null,
                    'guestPhone'=>$updatedBooking['guestPhone'] ?? null,
                    'roomNumber'=>$updatedBooking['roomNumber'] ?? null,
                    'roomType'=>$updatedBooking['roomType'] ?? null,
                    'checkIn'=>$updatedBooking['checkIn'] ?? null,
                    'checkOut'=>$updatedBooking['checkOut'] ?? null,
                    'totalAmount'=>$updatedBooking['totalAmount'] ?? null,
                    'bookingSource'=>$updatedBooking['bookingSource'] ?? null,
                    'status'=>$updatedBooking['status'] ?? null,
                ];
                if($clientTx&&$semanticAction==='extension')$auditAfter['extensionPricing']=['priceMode'=>$clientTx['priceMode']??'master','negotiationReason'=>$clientTx['negotiationReason']??null,'gross'=>$semanticAmount,'baseAmount'=>$semanticTax['baseAmount'],'taxAmount'=>$semanticTax['taxAmount']];
                if($serverTransferFinance!==null){
                    $auditAfter['roomTransferFinance']=[
                        'rateMode'=>$serverTransferFinance['rateMode'],
                        'effectiveDate'=>$serverTransferFinance['effectiveDate'],
                        'remainingNights'=>$serverTransferFinance['remainingNights'],
                        'currentNightlyBase'=>$serverTransferFinance['currentNightlyBase'],
                        'targetNightlyBase'=>$serverTransferFinance['targetNightlyBase'],
                        'rateBaseDelta'=>$serverTransferFinance['rateBaseDelta'],
                        'rateTaxDelta'=>$serverTransferFinance['rateTaxDelta'],
                        'rateGrossDelta'=>$serverTransferFinance['rateGrossDelta'],
                        'surchargeGross'=>$serverTransferFinance['surchargeGross'],
                        'surchargeTax'=>$serverTransferFinance['surchargeTax'],
                        'totalDelta'=>$serverTransferFinance['totalDelta'],
                        'taxDelta'=>$serverTransferFinance['taxDelta'],
                        'cashMutation'=>false,
                    ];
                }
                if ($auditBefore !== $auditAfter || $clientTx || $serverTransferFinance!==null) {
                    writeRequiredEnterpriseAudit(
                        $pdo,$loggedInStaff,
                        $roomTransfer ? ($oldLifecycleStatus === 'reserved' ? 'Memindahkan alokasi reservasi' : 'Pindah kamar') : 'Memperbarui booking',
                        'booking',(string)$id,$auditBefore,$auditAfter,'web'
                    );
                }

                tamasyaFinancialCommit($pdo);

                // Broadcast runs after the commit, so it must never be able to fail a save that
                // already succeeded. Previously an exception here fell into the outer
                // catch, which answered "Gagal memperbarui pemesanan" for a booking that
                // was already written; the rollback was a no-op and the operator saw a
                // failure for data that was saved. Mirrors the smart-lock handling below.
                $bookingTelegramBroadcastError = null;
                if ($deferredBookingTransactionTelegramMessage !== null) {
                    try {
                        broadcastTelegramNotification($pdo, $deferredBookingTransactionTelegramMessage, true);
                    } catch (Throwable $telegramBroadcastError) {
                        error_log('[Booking Telegram Broadcast] '.$telegramBroadcastError->getMessage());
                        $bookingTelegramBroadcastError = 'Notifikasi Telegram gagal dikirim; pemesanan tetap tersimpan.';
                    }
                }

                // Job revoke smart-lock sengaja dipanggil setelah commit. Kegagalan
                // bridge tidak membatalkan perpindahan database; sistem membuat
                // alert kritis agar pencabutan akses kamar lama ditindaklanjuti.
                $stayWindowSmartLockResult = null;
                if ($deferredStayWindowSmartLockJobId) {
                    try {
                        $stayWindowSmartLockResult = processSmartLockBridgeJobById($pdo,$deferredStayWindowSmartLockJobId,$loggedInStaff,'web-stay-window-refresh');
                    } catch (Throwable $smartLockError) {
                        error_log('[Stay Window Smart Lock] '.$smartLockError->getMessage());
                        $stayWindowSmartLockResult = ['jobId'=>$deferredStayWindowSmartLockJobId,'status'=>'error','message'=>'Refresh masa berlaku smart-lock perlu ditinjau dari log pekerjaan.'];
                    }
                }

                $transferSmartLockResult = null;
                if ($deferredTransferSmartLockJobId) {
                    try {
                        $transferSmartLockResult = processDeferredCheckoutSmartLockJob($pdo,$deferredTransferSmartLockJobId,$loggedInStaff,'web-room-transfer');
                    } catch (Throwable $smartLockError) {
                        error_log('[Room Transfer Smart Lock] '.$smartLockError->getMessage());
                        $transferSmartLockResult = ['jobId'=>$deferredTransferSmartLockJobId,'status'=>'error','message'=>'Pemrosesan smart-lock perlu ditinjau dari log pekerjaan.'];
                    }
                }

                // Broadcast update/check-out to Telegram
                $statusTxt = strtoupper($oldLifecycleStatus);
                if ($roomTransfer) {
                    $tgTitle = $oldLifecycleStatus === 'reserved' ? 'PINDAH ALOKASI RESERVASI' : 'PINDAH KAMAR';
                    $tgMsg = "🔄 *{$tgTitle}*\n\n" .
                              "👤 Tamu: *{$guestN}*\n" .
                              "🔑 Kamar: *{$oldRoomNumber} → {$newRoomNumber}*\n" .
                              "Status Reservasi: *{$statusTxt}*\n" .
                              "Booking ID: `{$id}`";
                    broadcastTelegramNotification($pdo, $tgMsg, true);
                } else {
                    $tgMsg = "🔄 *PERUBAHAN DATA RESERVASI*\n\n" .
                              "👤 Tamu: *" . $guestN . "*\n" .
                              "🔑 Kamar: *" . $roomN . "*\n" .
                              "Status Reservasi: *" . $statusTxt . "*\n" .
                              "Data diperbarui dari aplikasi.";
                    broadcastTelegramNotification($pdo, $tgMsg, true);
                }
                
                $compactRequested = (string)($_GET['compact'] ?? '0') === '1' && !$roomTransfer;
                $response = [
                    "success" => true,
                    "message" => $roomTransfer
                        ? ($oldLifecycleStatus === 'reserved'
                            ? "Reservasi {$guestN} berhasil dipindahkan dari Kamar {$oldRoomNumber} ke Kamar {$newRoomNumber}."
                            : "Tamu {$guestN} berhasil dipindahkan dari Kamar {$oldRoomNumber} ke Kamar {$newRoomNumber}. Kamar asal masuk alur Housekeeping/QC; kamar tujuan menjadi kamar aktif.")
                        : "Pemesanan berhasil diperbarui!",
                    // The save is committed; a failed notification is reported as a
                    // warning so the operator is never told a persisted change failed.
                    "warnings" => $bookingTelegramBroadcastError ? [$bookingTelegramBroadcastError] : [],
                    "transferLifecycle" => $transferLifecycleResult ? [
                        'oldRoomNumber'=>$transferLifecycleResult['oldRoomNumber'] ?? null,
                        'newRoomNumber'=>$transferLifecycleResult['newRoomNumber'] ?? null,
                        'keyDisposition'=>$transferLifecycleResult['keyDisposition'] ?? null,
                        'housekeepingTaskId'=>$transferLifecycleResult['housekeepingTaskId'] ?? null,
                        'smartLock'=>$transferSmartLockResult
                    ] : null,
                    "transferFinance" => $serverTransferFinance,
                    "stayWindowSmartLock" => $stayWindowSmartLockResult,
                ];
                if ($compactRequested) {
                    $response['delta'] = tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,[
                        'bookingId'=>(string)$id,'roomNumber'=>(string)$roomN,
                        'transactionIds'=>$txId ? [(string)$txId] : []
                    ]);
                } else {
                    $response['db'] = getRoleScopedHotelData($pdo, $loggedInStaff);
                }
                echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage($isDedicatedRoomTransfer?"Gagal memindahkan kamar":"Gagal memperbarui pemesanan", $e)]);
            }
        } elseif ($method === 'DELETE') {
            // Delete booking completely
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "ID Booking harus disertakan!"]);
                break;
            }
            
            try {
                $pdo->beginTransaction();
                
                // Get current booking first to free up room
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Booking tidak ditemukan!"]);
                    $pdo->rollBack();
                    break;
                }

                $stmtLinkedTx = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE bookingId = ? OR (sourceEntity = 'booking' AND sourceEntityId = ?)");
                $stmtLinkedTx->execute([$id, $id]);
                if ((int)$stmtLinkedTx->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(["success" => false, "message" => "Booking memiliki transaksi keuangan. Batalkan/checkout booking; jangan hapus permanen agar audit kas tetap utuh."]);
                    $pdo->rollBack();
                    break;
                }
                $relatedBlockers=bookingOperationalDeletionBlockers($pdo,(string)$id);
                if($relatedBlockers){
                    http_response_code(409);
                    echo json_encode(["success"=>false,"message"=>"Booking mempunyai ".implode(', ',$relatedBlockers).". Gunakan Pemeliharaan Data agar preview, relasi, audit, dan tombstone diproses aman."]);
                    $pdo->rollBack();
                    break;
                }
                
                // Delete from bookings table
                $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
                $stmt->execute([$id]);
                
                // If room status was booked, check if we should free it up
                $roomNumber = $booking['roomNumber'];
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber = ? AND status = 'active'");
                $stmtCheck->execute([$roomNumber]);
                $activeCount = $stmtCheck->fetchColumn();

                $roomAfterStatus='';
                if ($activeCount == 0) {
                    // Deleting a booking can remove the last domain blocker. Always
                    // reconcile from domain state so stale booked/maintenance/dirty
                    // caches cannot survive just because the stored value was unexpected.
                    $projection=tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$roomNumber,'web-delete');
                    $roomAfterStatus=(string)($projection['roomStatus']??'');
                }
                insertSyncTombstones($pdo,[['type'=>'bookings','id'=>(string)$id]],'direct_delete_'.substr(hash('sha256',(string)$id),0,40),(string)$loggedInStaff['id']);
                
                // Add notification
                $notifId = generateServerId('n');
                $notifMsg = "Pemesanan Kamar " . $booking['roomNumber'] . " (" . $booking['guestName'] . ") telah dihapus.";
                $stmt = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'booking')");
                $stmt->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);
                
                logActivity($pdo, "delete_booking", "Menghapus permanen data reservasi Kamar " . $booking['roomNumber'] . " atas nama " . $booking['guestName']);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus booking tanpa transaksi','booking',(string)$id,tamasyaBookingAuditSnapshot($booking),['deleted'=>true,'roomStatus'=>$roomAfterStatus?:null],'web-delete');
                bumpServerRevision($pdo);

                tamasyaFinancialCommit($pdo);
                
                echo json_encode([
                    "success" => true,
                    "message" => "Pemesanan berhasil dihapus!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal menghapus pemesanan", $e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/PUT/DELETE /api/rooms/<id> ATAU api.php?action=rooms&id=<id>
    // Manajemen Kamar (Status, Tambah, Edit, Hapus)
    // ----------------------------------------------------------------
    case 'rooms':
        $method = $_SERVER['REQUEST_METHOD'];
        requireRoles($loggedInStaff, ['admin', 'manager']);
        $roomActorRole = strtolower((string)($loggedInStaff['role'] ?? ''));
        if ($method !== 'PUT' && $method !== 'POST' && $method !== 'DELETE') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $id = $_GET['id'] ?? $input['id'] ?? '';

        if ($method === 'DELETE') {
            requireRoles($loggedInStaff, ['admin', 'manager']);
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "ID Kamar harus disertakan!"]);
                break;
            }
            try {
                $pdo->beginTransaction();
                // Kunci row kamar agar tidak dapat dijual perangkat lain saat proses hapus.
                $stmtRoom = $pdo->prepare("SELECT id,number,type,price,status,floor FROM rooms WHERE id = ? OR number = ? LIMIT 1 FOR UPDATE");
                $stmtRoom->execute([$id, $id]);
                $roomRecord = $stmtRoom->fetch(PDO::FETCH_ASSOC);
                $roomNum = (string)($roomRecord['number'] ?? '');
                $roomEntityId = (string)($roomRecord['id'] ?? '');
                if ($roomNum === '' || $roomEntityId === '') {
                    http_response_code(404);
                    throw new RuntimeException('Kamar tidak ditemukan.');
                }

                $stmtB = $pdo->prepare("SELECT id,status,checkIn,checkOut FROM bookings WHERE roomNumber = ? AND status IN ('reserved','active') LIMIT 1 FOR UPDATE");
                $stmtB->execute([$roomNum]);
                $blockingBooking = $stmtB->fetch(PDO::FETCH_ASSOC);
                if ($blockingBooking) {
                    http_response_code(409);
                    throw new RuntimeException('Kamar tidak dapat dihapus karena masih mempunyai reservasi menunggu atau booking aktif.');
                }
                $stmtAccess = $pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
                $stmtAccess->execute([$roomNum]);
                $roomAccessBeforeDelete = $stmtAccess->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($roomAccessBeforeDelete && (!empty($roomAccessBeforeDelete['current_booking_id']) || in_array((string)($roomAccessBeforeDelete['physical_key_status'] ?? 'secured'), ['issued','missing','override'], true))) {
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai penugasan booking atau status kunci yang belum aman.');
                }
                $stmtPendingRoomOps = $pdo->prepare("SELECT 1 FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') LIMIT 1 FOR UPDATE");
                $stmtPendingRoomOps->execute([$roomNum]);
                if ($stmtPendingRoomOps->fetchColumn()) {
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai tugas housekeeping terbuka. Selesaikan atau batalkan tugas terlebih dahulu.');
                }
                $stmtPendingMaintenance=$pdo->prepare("SELECT 1 FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled') LIMIT 1 FOR UPDATE");
                $stmtPendingMaintenance->execute([$roomNum,'room:'.$roomNum]);
                if($stmtPendingMaintenance->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai tiket maintenance aktif. Selesaikan atau batalkan tiket sebelum menghapus kamar.');
                }
                $stmtPendingCustody=$pdo->prepare("SELECT 1 FROM lost_found_items WHERE room_number=? AND custody_status IN ('found','secured','guest_notified') LIMIT 1 FOR UPDATE");
                $stmtPendingCustody->execute([$roomNum]);
                if($stmtPendingCustody->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai custody Lost & Found terbuka. Selesaikan custody sebelum menghapus master kamar.');
                }
                $stmtPendingReview=$pdo->prepare("SELECT 1 FROM maintenance_cancellation_reviews WHERE room_number=? AND status='open' LIMIT 1 FOR UPDATE");
                $stmtPendingReview->execute([$roomNum]);
                if($stmtPendingReview->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai review pembatalan maintenance terbuka.');
                }
                $stmtPendingGuestService=$pdo->prepare("SELECT 1 FROM guest_service_requests WHERE room_number=? AND status IN ('open','assigned','in_progress') LIMIT 1 FOR UPDATE");
                $stmtPendingGuestService->execute([$roomNum]);
                if($stmtPendingGuestService->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai permintaan tamu aktif. Selesaikan atau pindahkan konteks permintaan sebelum menghapus master kamar.');
                }
                $stmtPendingIncident=$pdo->prepare("SELECT 1 FROM operational_incidents WHERE room_number=? AND status IN ('open','in_progress') LIMIT 1 FOR UPDATE");
                $stmtPendingIncident->execute([$roomNum]);
                if($stmtPendingIncident->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai security/safety/occupancy incident terbuka. Selesaikan incident sebelum menghapus master kamar.');
                }
                $stmtPendingHold=$pdo->prepare("SELECT 1 FROM room_operational_holds WHERE room_number=? AND status='open' LIMIT 1 FOR UPDATE");
                $stmtPendingHold->execute([$roomNum]);
                if($stmtPendingHold->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai operational hold terbuka.');
                }
                $stmtPendingAlert=$pdo->prepare("SELECT 1 FROM system_alerts WHERE acknowledged_at IS NULL AND source IN (?,?,?,?,?) LIMIT 1 FOR UPDATE");
                $stmtPendingAlert->execute(['lost-found:'.$roomNum,'lost-found-custody:'.$roomNum,'room-damage:'.$roomNum,'maintenance-cancelled:'.$roomNum,'key-missing:'.$roomNum]);
                if($stmtPendingAlert->fetchColumn()){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai alert operasional yang belum diselesaikan. Akui/tuntaskan alert sebelum menghapus kamar.');
                }
                $stmtPendingSmartLock = $pdo->prepare("SELECT 1 FROM smart_lock_jobs WHERE room_number=? AND status NOT IN ('completed','cancelled') LIMIT 1 FOR UPDATE");
                $stmtPendingSmartLock->execute([$roomNum]);
                if ($stmtPendingSmartLock->fetchColumn()) {
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai pekerjaan smart-lock tertunda.');
                }
                // R3 canonical readiness also covers structured Night Audit blockers and
                // future blocker classes; room deletion must not maintain a parallel list.
                $deleteCanonicalBlockers=getRoomOperationalBlockers($pdo,$roomNum,true);
                if($deleteCanonicalBlockers){
                    http_response_code(409);
                    throw new RuntimeException('Kamar masih mempunyai blocker operasional canonical: '.roomOperationalBlockerMessage($roomNum,$deleteCanonicalBlockers).'.');
                }

                $roomBeforeDelete = array_merge(is_array($roomRecord) ? $roomRecord : ['id'=>$roomEntityId,'number'=>$roomNum], is_array($roomAccessBeforeDelete) ? ['access'=>$roomAccessBeforeDelete] : []);
                $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ? OR number = ?");
                $stmt->execute([$id, $id]);
                $pdo->prepare("DELETE FROM room_access_control WHERE room_number=?")->execute([$roomNum]);
                insertSyncTombstones($pdo,[['type'=>'rooms','id'=>$roomEntityId]],'direct_room_delete_'.substr(hash('sha256',$roomEntityId.'|'.$roomNum),0,40),(string)$loggedInStaff['id']);
                logActivity($pdo, "delete_room", "Menghapus kamar nomor: " . $roomNum);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus kamar','room',$roomEntityId,$roomBeforeDelete,['deleted'=>true],'web-delete');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);

                echo json_encode([
                    "success" => true,
                    "message" => "Kamar berhasil dihapus!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal menghapus kamar", $e)]);
            }
            break;
        }

        if ($method === 'POST' && empty($id)) {
            requireRoles($loggedInStaff, ['admin', 'manager']);
            // Tambah kamar baru
            $number = $input['number'] ?? '';
            $type = $input['type'] ?? '';
            $price = floatval($input['price'] ?? 0);
            $floor = intval($input['floor'] ?? 1);
            
            if (empty($number) || empty($type)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Nomor kamar dan tipe wajib diisi!"]);
                break;
            }
            
            try {
                $pdo->beginTransaction();
                $roomTypeCatalog = requireActiveRoomTypeCatalog($pdo, (string)$type, true, false);
                $type = (string)$roomTypeCatalog['name'];
                // Cek nomor kamar unik di dalam transaksi untuk menjaga konsistensi
                // room master dan room_access_control pada retry/multi-user.
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE number = ? FOR UPDATE");
                $stmt->execute([$number]);
                if ($stmt->fetchColumn() > 0) {
                    http_response_code(409);
                    throw new RuntimeException("Nomor kamar $number sudah terdaftar!");
                }

                $roomId = generateServerId('r');
                $stmt = $pdo->prepare("INSERT INTO rooms (id, number, type, price, status, floor) VALUES (?, ?, ?, ?, 'available', ?)");
                $stmt->execute([$roomId, $number, $type, $price, $floor]);
                $pdo->prepare("INSERT INTO room_access_control (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,last_event_at,updated_by,updated_at) VALUES (?,'physical',?,'secured',NULL,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE physical_key_ref=COALESCE(NULLIF(physical_key_ref,''),VALUES(physical_key_ref)),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
                    ->execute([$number,'KEY-'.$number,$loggedInStaff['id'] ?? null]);

                // Tambah Notifikasi
                $notifId = generateServerId('n');
                $notifMsg = "Kamar baru nomor $number (Tipe $type) berhasil ditambahkan ke inventori.";
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')");
                $stmtNotif->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);

                logActivity($pdo, "create_room", "Menambahkan kamar baru nomor: $number (Tipe: $type)");
                $roomAfterStmt=$pdo->prepare("SELECT * FROM rooms WHERE id=? LIMIT 1");$roomAfterStmt->execute([$roomId]);$roomAfter=$roomAfterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$roomId,'number'=>$number,'type'=>$type,'price'=>$price,'floor'=>$floor,'status'=>'available'];
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambah kamar','room',$roomId,null,$roomAfter,'web');
                bumpServerRevision($pdo);
                tamasyaFinancialCommit($pdo);

                echo json_encode([
                    "success" => true,
                    "message" => "Kamar baru berhasil ditambahkan!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal menambah kamar", $e)]);
            }
            break;
        }

        // PUT atau POST untuk modifikasi data kamar yang sudah ada
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "ID Kamar harus disertakan!"]);
            break;
        }

        try {
            $pdo->beginTransaction();
            $stmtLockedRoom = $pdo->prepare("SELECT * FROM rooms WHERE id = ? OR number = ? LIMIT 1 FOR UPDATE");
            $stmtLockedRoom->execute([$id, $id]);
            $lockedRoom = $stmtLockedRoom->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRoom) {
                http_response_code(404);
                throw new RuntimeException('Kamar tidak ditemukan.');
            }
            $currentRoomNumber = (string)$lockedRoom['number'];
            $requestedStatus=array_key_exists('status',$input)?strtolower(trim((string)$input['status'])):null;
            if ($requestedStatus!==null) {
                http_response_code(409);
                throw new RuntimeException('Status kamar tidak dapat diubah manual. Buat/selesaikan lifecycle Booking, Housekeeping, Maintenance, Key Control, Lost & Found, Night Audit, atau Operational Hold; rooms.status hanya proyeksi server.');
            }

            $fieldsToUpdate=[];$params=[];$targetRoomNumber=$currentRoomNumber;
            if (array_key_exists('number',$input)) {
                $requestedRoomNumber=trim((string)$input['number']);
                if($requestedRoomNumber==='')throw new InvalidArgumentException('Nomor kamar tidak boleh kosong.');
                if($requestedRoomNumber!==$currentRoomNumber){
                    $stmtRoomReferences=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=?");$stmtRoomReferences->execute([$currentRoomNumber]);
                    if((int)$stmtRoomReferences->fetchColumn()>0){http_response_code(409);throw new RuntimeException('Nomor kamar tidak boleh diubah karena sudah direferensikan oleh data booking.');}
                    $activeOperationalRef=$pdo->prepare("SELECT (SELECT COUNT(*) FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed')) + (SELECT COUNT(*) FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled')) + (SELECT COUNT(*) FROM lost_found_items WHERE room_number=? AND custody_status IN ('found','secured','guest_notified')) + (SELECT COUNT(*) FROM maintenance_cancellation_reviews WHERE room_number=? AND status='open') + (SELECT COUNT(*) FROM operational_incidents WHERE room_number=? AND status IN ('open','in_progress')) + (SELECT COUNT(*) FROM guest_service_requests WHERE room_number=? AND status IN ('open','assigned','in_progress')) + (SELECT COUNT(*) FROM room_operational_holds WHERE room_number=? AND status='open')");
                    $activeOperationalRef->execute([$currentRoomNumber,$currentRoomNumber,'room:'.$currentRoomNumber,$currentRoomNumber,$currentRoomNumber,$currentRoomNumber,$currentRoomNumber,$currentRoomNumber]);
                    if((int)$activeOperationalRef->fetchColumn()>0){http_response_code(409);throw new RuntimeException('Nomor kamar tidak boleh diubah selama masih ada lifecycle operasional aktif (housekeeping, maintenance, incident, guest service, atau hold).');}
                    $renameBlockers=getRoomOperationalBlockers($pdo,$currentRoomNumber,true);
                    if($renameBlockers){http_response_code(409);throw new RuntimeException('Nomor kamar tidak boleh diubah selama masih ada blocker operasional kamar. Tuntaskan alert/kunci/smart-lock terlebih dahulu.');}
                    $stmtDuplicateRoom=$pdo->prepare("SELECT COUNT(*) FROM rooms WHERE number=? AND id<>?");$stmtDuplicateRoom->execute([$requestedRoomNumber,(string)$lockedRoom['id']]);
                    if((int)$stmtDuplicateRoom->fetchColumn()>0){http_response_code(409);throw new RuntimeException('Nomor kamar sudah digunakan oleh kamar lain.');}
                }
                $fieldsToUpdate[]='number=?';$params[]=$requestedRoomNumber;$targetRoomNumber=$requestedRoomNumber;
            }
            if(array_key_exists('type',$input)){ $requestedRoomType=trim((string)$input['type']); if(mb_strtolower($requestedRoomType)===mb_strtolower(trim((string)$lockedRoom['type']))){ $canonicalRoomType=(string)$lockedRoom['type']; } else { $roomTypeCatalog=requireActiveRoomTypeCatalog($pdo,$requestedRoomType,true,false); $canonicalRoomType=(string)$roomTypeCatalog['name']; } $fieldsToUpdate[]='type=?'; $params[]=$canonicalRoomType; }
            if(array_key_exists('price',$input)){$fieldsToUpdate[]='price=?';$params[]=floatval($input['price']);}
            if(array_key_exists('floor',$input)){$fieldsToUpdate[]='floor=?';$params[]=intval($input['floor']);}
            if(!$fieldsToUpdate){http_response_code(400);throw new InvalidArgumentException('Tidak ada data master kamar yang diubah.');}

            if($fieldsToUpdate){
                $fieldsToUpdate[]='version=version+1';$fieldsToUpdate[]='updatedAt=CURRENT_TIMESTAMP';$fieldsToUpdate[]='updatedBy=?';$params[]=(string)($loggedInStaff['id']??'');$fieldsToUpdate[]="updatedSource='web'";$params[]=(string)$lockedRoom['id'];
                $pdo->prepare('UPDATE rooms SET '.implode(', ',$fieldsToUpdate).' WHERE id=?')->execute($params);
                if($targetRoomNumber!==$currentRoomNumber){
                    $pdo->prepare("UPDATE room_access_control SET room_number=?,physical_key_ref=CASE WHEN physical_key_ref=CONCAT('KEY-',?) THEN CONCAT('KEY-',?) ELSE physical_key_ref END,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                        ->execute([$targetRoomNumber,$currentRoomNumber,$targetRoomNumber,(string)($loggedInStaff['id']??''),$currentRoomNumber]);
                }
            }
            $roomAfterStmt=$pdo->prepare("SELECT * FROM rooms WHERE id=? LIMIT 1");$roomAfterStmt->execute([(string)$lockedRoom['id']]);$roomAfter=$roomAfterStmt->fetch(PDO::FETCH_ASSOC)?:$lockedRoom;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui kamar','room',(string)$lockedRoom['id'],$lockedRoom,$roomAfter,'web');
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);

            logActivity($pdo,'update_room','Memperbarui data master kamar nomor: '.$targetRoomNumber,(string)($loggedInStaff['id']??''),currentStaffLabel($loggedInStaff));
            echo json_encode([
                'success'=>true,
                'message'=>'Data kamar berhasil diperbarui!',
                'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal mengubah data kamar", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/bookings/<id>/payments ATAU api.php?action=booking-payments&id=<id>
    // Panjar berulang dicatat sebagai ledger atomik dan idempoten.
    // ----------------------------------------------------------------
    // GET/POST /api/bookings/<id>/security-deposit
    case 'guest-security-deposits':
        requireCapability($loggedInStaff,'receive_payment_access',['admin','manager','receptionist','finance']);
        $bookingId=trim((string)($_GET['id']??$input['id']??''));
        if($bookingId===''){http_response_code(400);echo json_encode(['success'=>false,'error'=>'ID booking deposito wajib.']);break;}
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $summary=tamasyaSecurityDepositSummary($pdo,$bookingId,false);
            $ledgerStmt=$pdo->prepare("SELECT id,operation_id AS operationId,entry_type AS entryType,amount,payment_method AS paymentMethod,bank_account_id AS bankAccountId,reason,reason_type AS reasonType,transaction_id AS transactionId,shift_session_id AS shiftSessionId,created_by AS createdBy,created_by_name AS createdByName,source,created_at AS createdAt FROM guest_security_deposit_ledger WHERE booking_id=? ORDER BY created_at,id");
            $ledgerStmt->execute([$bookingId]);
            echo json_encode(['success'=>true,'summary'=>$summary,'ledger'=>$ledgerStmt->fetchAll(PDO::FETCH_ASSOC)?:[]]);break;
        }
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $command=strtolower(trim((string)($input['command']??'receive')));
        try{
            $pdo->beginTransaction();
            if($command==='configure'){
                $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$bookingStmt->execute([$bookingId]);$booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
                if(!$booking)throw new RuntimeException('Booking tidak ditemukan.');
                if(!in_array(strtolower((string)($booking['status']??'')),['reserved','active'],true))throw new RuntimeException('Kewajiban deposito hanya dapat diatur untuk reservasi atau booking aktif.');
                $before=tamasyaSecurityDepositSummary($pdo,$bookingId,true);
                $summary=tamasyaEnsureSecurityDepositRow($pdo,$bookingId,round((float)($input['requiredAmount']??0),2),$loggedInStaff,'web');
                tamasyaSyncSecurityDepositProjection($pdo,$bookingId,$summary,$loggedInStaff,'web');
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengatur kewajiban deposito jaminan','guest_security_deposit',(string)($summary['id']??$bookingId),$before,$summary,'web');
                $result=['summary'=>$summary];
            }elseif($command==='receive'){
                $payload=(array)$input;$payload['operationId']=trim((string)($input['operationId']??($GLOBALS['tamasya_request_operation_id']??'')));
                $result=tamasyaReceiveGuestSecurityDepositInTransaction($pdo,$loggedInStaff,$bookingId,$payload,'web');
            }elseif(in_array($command,['settle','refund_full','hold'],true)){
                $payload=(array)$input;if($command==='refund_full')$payload['disposition']='refund_full';elseif($command==='hold')$payload['disposition']='hold';else $payload['disposition']=$payload['disposition']??'settle';
                $payload['operationId']=trim((string)($input['operationId']??($GLOBALS['tamasya_request_operation_id']??'')));
                $result=tamasyaSettleGuestSecurityDepositInTransaction($pdo,$loggedInStaff,$bookingId,$payload,'web');
            }else throw new InvalidArgumentException('Perintah deposito tidak valid.');
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            $depositResponse=['success'=>true]+$result;
            if((string)($_GET['compact']??'0')==='1'){
                $roomStmt=$pdo->prepare("SELECT roomNumber FROM bookings WHERE id=? LIMIT 1");$roomStmt->execute([$bookingId]);$roomNumber=(string)($roomStmt->fetchColumn()?:'');
                $transactionIds=[];
                if(!empty($result['transactionId']))$transactionIds[]=(string)$result['transactionId'];
                foreach((array)($result['transactionIds']??[]) as $resultTransactionId)if(trim((string)$resultTransactionId)!=='')$transactionIds[]=(string)$resultTransactionId;
                $depositResponse['delta']=tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,['bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'transactionIds'=>$transactionIds]);
            }else{$depositResponse['db']=getRoleScopedHotelData($pdo,$loggedInStaff);}
            echo json_encode($depositResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,422);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Operasi deposito gagal',$e)]);}
        break;

    case 'booking-payments':
        requireCapability($loggedInStaff, 'receive_payment_access', ['admin', 'manager', 'receptionist', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'message'=>'Method Not Allowed']);
            break;
        }
        $bookingPaymentId=trim((string)($_GET['id'] ?? $input['bookingId'] ?? $input['id'] ?? ''));
        if($bookingPaymentId===''){
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'ID booking wajib disertakan.']);
            break;
        }
        try{
            $pdo->beginTransaction();
            $paymentResult=recordBookingDeposit($pdo,$loggedInStaff,$bookingPaymentId,$input,'web');
            tamasyaFinancialCommit($pdo);
            $postCommitWarnings=[];
            if(empty($paymentResult['duplicate']) && !empty($paymentResult['telegramMessage'])){
                try{broadcastTelegramNotification($pdo,(string)$paymentResult['telegramMessage'],true);}
                catch(Throwable $notifyError){$postCommitWarnings[]='Panjar tersimpan, tetapi notifikasi Telegram gagal dikirim.';}
            }
            $paymentResponse=[
                'success'=>true,
                'message'=>!empty($paymentResult['duplicate'])?'Panjar sudah pernah diproses; tidak dibuat ganda.':'Panjar berhasil dicatat.',
                'result'=>$paymentResult,
                'warnings'=>$postCommitWarnings,
            ];
            if((string)($_GET['compact']??'0')==='1'){
                $roomStmt=$pdo->prepare("SELECT roomNumber FROM bookings WHERE id=? LIMIT 1");$roomStmt->execute([$bookingPaymentId]);$roomNumber=(string)($roomStmt->fetchColumn()?:'');
                $paymentResponse['delta']=tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,['bookingId'=>$bookingPaymentId,'roomNumber'=>$roomNumber,'transactionIds'=>!empty($paymentResult['transactionId'])?[(string)$paymentResult['transactionId']]:[]]);
            }else{$paymentResponse['db']=getRoleScopedHotelData($pdo,$loggedInStaff);}
            echo json_encode($paymentResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }catch(InvalidArgumentException $e){
            if($pdo->inTransaction())$pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success'=>false,'message'=>clientExceptionMessage('', $e)]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if(http_response_code()<400)http_response_code(409);
            echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal mencatat panjar',$e)]);
        }
        break;

    // ----------------------------------------------------------------
    // PUT /api/bookings/<id>/status ATAU api.php?action=bookings-status&id=<id>
    // Update status reservasi (Check-out tamu / Batal)
    // ----------------------------------------------------------------
    case 'bookings-status':
        tamasyaRuntimeSetStage('bookings-status:authorize');
        requireCapability($loggedInStaff, 'manage_stay_access', ['admin', 'manager', 'receptionist']);
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        tamasyaRuntimeSetStage('bookings-status:parse_input');
        $id = $_GET['id'] ?? $input['id'] ?? '';
        $status = $input['status'] ?? ''; // 'completed' atau 'cancelled'

        if (empty($id) || empty($status)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "ID Booking dan Status baru harus disertakan!"]);
            break;
        }
        if (!in_array($status, ['active','completed','cancelled'], true)) {
            http_response_code(422);
            echo json_encode(["success"=>false,"message"=>"Status booking hanya boleh active, completed, atau cancelled."]);
            break;
        }

        try {
            tamasyaRuntimeSetStage('bookings-status:begin');
            $postCommitTelegramMessage=null;
            $deferredSmartLockJobId=null;
            $postCommitWarnings=[];
            $pdo->beginTransaction();

            tamasyaRuntimeSetStage('bookings-status:load_booking');
            // Cari booking terkait untuk mendapatkan nomor kamarnya
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Booking tidak ditemukan!"]);
                $pdo->rollBack();
                break;
            }
            $currentBookingStatus = strtolower((string)($booking['status'] ?? 'active'));
            $compactStatusResponse = $status === 'active' && (string)($_GET['compact'] ?? '') === '1';
            if ($currentBookingStatus === $status) {
                // Retry setelah respons jaringan hilang: jangan memposting kas/refund dua kali.
                tamasyaFinancialCommit($pdo);
                $retryResponse=[
                    "success"=>true,
                    "message"=>"Status booking sudah diproses sebelumnya; tidak ada transaksi ganda."
                ];
                if($compactStatusResponse){
                    try{$retryResponse['delta']=tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,['bookingId'=>$id,'roomNumber'=>(string)($booking['roomNumber']??''),'transactionIds'=>[]]);}
                    catch(Throwable $deltaError){error_log(clientExceptionMessage('[checkin compact delta] refresh penuh diperlukan',$deltaError));$retryResponse['delta']=null;$retryResponse['refreshRequired']=true;}
                }else{$retryResponse['db']=getRoleScopedHotelData($pdo,$loggedInStaff);}
                echo json_encode($retryResponse);
                break;
            }
            if ($status === 'active' && $currentBookingStatus !== 'reserved') {
                http_response_code(409);
                throw new RuntimeException('Hanya reservasi berstatus reserved yang dapat di-check-in.');
            }
            if ($status === 'completed' && $currentBookingStatus !== 'active') {
                http_response_code(409);
                throw new RuntimeException('Hanya tamu yang sudah check-in yang dapat di-check-out.');
            }
            if ($status === 'cancelled' && !in_array($currentBookingStatus, ['reserved','active'], true)) {
                http_response_code(409);
                throw new RuntimeException('Booking yang sudah selesai atau dibatalkan tidak boleh dibatalkan ulang.');
            }

            if($status==='cancelled')tamasyaMultiRoomAssertCancellation($pdo,(string)$id);
            $roomNumber = (string)$booking['roomNumber'];
            $checkoutOperationId=trim((string)($input['operationId']??''))?:generateServerId('op_checkout_web');
            $keyReturned=!empty($input['keyReturned']);
            $keyMissing=!empty($input['keyMissing']);
            $keyReason=trim((string)($input['keyMissingReason']??$input['keyOverrideReason']??$input['checkoutNotes']??''));
            // Kompatibilitas request lama: Manager yang mengirim override reason tanpa flag
            // keyMissing tetap dicatat sebagai kunci belum kembali, bukan dipalsukan returned.
            if(!$keyReturned && !$keyMissing && $keyReason!=='' && hasCapability($loggedInStaff,'override_key_checkout',['admin','manager']))$keyMissing=true;
            $keyDisposition=$keyReturned?'returned':($keyMissing?'missing':'unknown');
            $vacancyReportId=trim((string)($input['vacancyReportId']??''));
            $checkoutMode=trim((string)($input['checkoutMode']??''));
            if($checkoutMode==='')$checkoutMode=$keyMissing?'without_notice':($vacancyReportId!==''?'field_verified':'normal');
            $securityDepositDisposition=strtolower(trim((string)($input['securityDepositDisposition']??'')));
            $securityDepositRefundAmount=max(0.0,round((float)($input['securityDepositRefundAmount']??0),2));
            $securityDepositForfeitAmount=max(0.0,round((float)($input['securityDepositForfeitAmount']??0),2));
            $securityDepositRefundMethod=strtolower(trim((string)($input['securityDepositRefundMethod']??'cash')));
            $securityDepositRefundBankAccountId=trim((string)($input['securityDepositRefundBankAccountId']??''));
            $securityDepositForfeitReason=trim((string)($input['securityDepositForfeitReason']??''));
            $securityDepositForfeitType=strtolower(trim((string)($input['securityDepositForfeitType']??'other')));
            $financialDisposition=strtolower(trim((string)($input['financialDisposition']??'settle_now')));
            $financialClosureReason=trim((string)($input['financialClosureReason']??'Tamu telah meninggalkan kamar; tagihan dicatat sebagai piutang untuk ditindaklanjuti.'));
            if(!in_array($financialDisposition,['settle_now','defer'],true))throw new InvalidArgumentException('Pilihan penutupan keuangan tidak valid.');
            if($financialDisposition==='defer'&&$status!=='completed')throw new InvalidArgumentException('Penundaan keuangan hanya berlaku untuk checkout operasional.');
            if($financialDisposition==='defer')$securityDepositDisposition='hold';

            // Reservasi mendatang baru mengubah kondisi kamar saat check-in aktual.
            if ($status === 'active') {
                tamasyaRuntimeSetStage('bookings-status:checkin_validate');
                tamasyaCanonicalReservedCheckInInTransaction($pdo,$loggedInStaff,$booking,'web');
            }

            // Pembatalan tidak memakai lifecycle checkout. Checkout final baru
            // dijalankan setelah seluruh validasi dan ledger pembayaran lulus agar
            // akses smart lock tidak dicabut sebelum transaksi server konsisten.
            if($status==='cancelled'){
                tamasyaRuntimeSetStage('bookings-status:cancel_validate');
                $openReportsStmt=$pdo->prepare("SELECT id FROM room_vacancy_reports WHERE booking_id=? AND status IN ('pending','verified') ORDER BY id FOR UPDATE");
                $openReportsStmt->execute([$id]);
                if($openReportsStmt->fetch(PDO::FETCH_ASSOC)){
                    http_response_code(409);
                    throw new RuntimeException('Booking memiliki laporan kamar kosong yang masih aktif. Verifikasi checkout atau tolak laporan terlebih dahulu.');
                }
                if ($currentBookingStatus === 'reserved') {
                    $stmt=$pdo->prepare("UPDATE bookings SET status='cancelled',version=version+1,updatedBy=?,updatedSource='web' WHERE id=? AND status='reserved'");
                    $stmt->execute([(string)$loggedInStaff['id'],$id]);
                    if($stmt->rowCount()!==1)throw new RuntimeException('Reservasi sudah berubah sebelum pembatalan diselesaikan.');
                    tamasyaSyncGuestServiceBookingLifecycle($pdo,$loggedInStaff,(string)$id,'cancelled',$roomNumber,'web-reservation-cancel');
                }
                // Booking yang sudah aktif tidak boleh membuat kamar langsung tersedia.
                // Setelah refund ledger konsisten, lifecycle operasional di bawah akan
                // mencabut akses, memvalidasi kunci, menandai kamar maintenance, dan
                // membuat tugas housekeeping secara atomik.
            }

            // 2b. Sinkronisasi booking, kas, pajak, dan laporan keuangan secara non-destruktif.
            $roomCategoryName = getRoomRentalCategoryName($pdo);
            $securityDepositSettlement=null;
            if(in_array($status,['completed','cancelled'],true)){
                $depositBefore=tamasyaSecurityDepositSummary($pdo,(string)$id,true);
                if((float)($depositBefore['heldBalance']??0)>0.01){
                    if($securityDepositDisposition===''){
                        http_response_code(409);
                        throw new RuntimeException('Deposito jaminan masih ditahan Rp '.number_format((float)$depositBefore['heldBalance'],0,',','.').'. Pilih kembalikan penuh, potong/sebagian, atau tahan menunggu inspeksi sebelum menyelesaikan checkout/pembatalan.');
                    }
                    $securityDepositSettlement=tamasyaSettleGuestSecurityDepositInTransaction($pdo,$loggedInStaff,(string)$id,[
                        'disposition'=>$securityDepositDisposition,
                        'refundAmount'=>$securityDepositRefundAmount,
                        'forfeitAmount'=>$securityDepositForfeitAmount,
                        'refundMethod'=>$securityDepositRefundMethod,
                        'refundBankAccountId'=>$securityDepositRefundBankAccountId,
                        'forfeitReason'=>$securityDepositForfeitReason,
                        'forfeitType'=>$securityDepositForfeitType,
                        'reason'=>$securityDepositForfeitReason?:'Menunggu pemeriksaan kamar/kunci',
                        'operationId'=>'gsd_settle_'.substr(hash('sha256',$checkoutOperationId),0,70)
                    ],'web');
                }
            }
            if ($status === 'cancelled') {
                // Histori penerimaan tidak dihapus. Setiap penerimaan dibalik dengan refund
                // pada kanal yang sama sehingga kas, bank, piutang OTA, pajak, dan audit tetap dapat ditelusuri.
                tamasyaRuntimeSetStage('bookings-status:refund');
                createBookingRefundTransactions($pdo, $booking, $loggedInStaff, 'web');
                assertBookingLedgerInvariant($pdo, $id, $loggedInStaff, 'web', true);
                if ($currentBookingStatus === 'active') {
                    tamasyaRuntimeSetStage('bookings-status:cancel_operational');
                    $operational=finalizeCheckoutOperationalLifecycle($pdo,$loggedInStaff,$booking,'web',[
                        'keyDisposition'=>$keyDisposition,'keyReason'=>$keyReason,'checkoutMode'=>$checkoutMode,
                        'operationId'=>$checkoutOperationId,'vacancyReportId'=>$vacancyReportId,'finalStatus'=>'cancelled'
                    ]);
                    // The terminal lifecycle changes ACTIVE -> CANCELLED after refund posting.
                    // Rebuild the canonical booking projection after that status transition;
                    // otherwise roomCharge/balanceDue/paymentStatus can retain an ACTIVE snapshot.
                    recalculateBookingFinancials($pdo,$id,true);
                    assertBookingLedgerInvariant($pdo,$id,$loggedInStaff,'web',false);
                    $deferredSmartLockJobId=(string)($operational['smartLockJobId']??'');
                }
            } elseif ($status === 'completed') {
                tamasyaRuntimeSetStage('bookings-status:checkout_ledger');
                $scheduledCheckIn=(string)($booking['checkIn'] ?? '');
                $actualCheckInAt=(string)($booking['actualCheckInAt'] ?? '');
                if (!validIsoDate($scheduledCheckIn) || $scheduledCheckIn > date('Y-m-d')) {
                    http_response_code(409);
                    throw new RuntimeException('Checkout ditolak karena tanggal check-in booking belum tiba atau tidak valid. Koreksi lifecycle booking terlebih dahulu.');
                }
                if ($actualCheckInAt === '' || substr($actualCheckInAt,0,10) < $scheduledCheckIn) {
                    http_response_code(409);
                    throw new RuntimeException('Checkout ditolak karena waktu check-in aktual tidak sesuai tanggal reservasi. Gunakan koreksi audit sebelum melanjutkan.');
                }
                $bookingTotalAmount = max(0.0, (float)($booking['totalAmount'] ?? 0));
                $bookingSource = trim((string)($booking['bookingSource'] ?? 'Direct')) ?: 'Direct';
                $checkoutPaymentMethod = array_key_exists('paymentMethod',$input)
                    ? strtolower(trim((string)($input['paymentMethod'] ?? '')))
                    : '';
                $checkoutBankAccountId = array_key_exists('bankAccountId',$input)
                    ? trim((string)($input['bankAccountId'] ?? ''))
                    : '';
                $ledgerBeforeSettlement = bookingLedgerTotals($pdo, $id);

                if ((int)($booking['isOpenEnded'] ?? 0) === 1) {
                    if (!array_key_exists('totalAmount', $input)) {
                        http_response_code(422);
                        throw new InvalidArgumentException('Total tagihan aktual wajib diisi untuk checkout durasi terbuka.');
                    }
                    $bookingTotalAmount = round((float)$input['totalAmount'], 2);
                    $paidLedgerAmount = max(0.0, (float)$ledgerBeforeSettlement['net']);
                    if ($bookingTotalAmount < $paidLedgerAmount - 1 || $bookingTotalAmount <= 0) {
                        http_response_code(422);
                        throw new InvalidArgumentException('Total tagihan aktual tidak boleh lebih kecil dari seluruh pembayaran yang sudah diterima dan harus lebih dari nol.');
                    }
                    // Total durasi terbuka baru diketahui saat checkout. Hitung ulang
                    // komponen kamar, tetapi pertahankan snapshot PBJT setiap extra.
                    $minimumCheckOut=(new DateTimeImmutable($scheduledCheckIn))->modify('+1 day')->format('Y-m-d');
                    $actualCheckOutDate=max(date('Y-m-d'),$minimumCheckOut);
                    $serverTax = resolveBookingAggregateTaxSnapshot(
                        $pdo,array_merge($booking,['bookingSource'=>$bookingSource]),$bookingTotalAmount,$actualCheckOutDate
                    );
                    $pdo->prepare("UPDATE bookings SET checkOut=?,totalAmount=?,vatRate=?,vatAmount=?,extras=?,isOpenEnded=0 WHERE id=?")
                        ->execute([
                            $actualCheckOutDate,$bookingTotalAmount,$serverTax['taxRate'],$serverTax['taxAmount'],
                            json_encode($serverTax['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id
                        ]);
                    $booking['checkOut'] = $actualCheckOutDate;
                    $booking['totalAmount'] = $bookingTotalAmount;
                    $booking['vatRate'] = $serverTax['taxRate'];
                    $booking['vatAmount'] = $serverTax['taxAmount'];
                    $booking['extras'] = $serverTax['extras'];
                } else {
                    // Booking normal sudah mempunyai snapshot pajak saat kamar,
                    // extension, dan extra dibuat. Checkout tidak boleh menghitung
                    // ulang histori tersebut memakai aturan pajak hari ini.
                    $metadataValid=is_numeric($booking['vatAmount']??null)
                        && (float)$booking['vatAmount']>=0
                        && (float)$booking['vatAmount']<=$bookingTotalAmount+1
                        && (($booking['vatRate']??null)===null || (is_numeric($booking['vatRate'])&&(float)$booking['vatRate']>=0&&(float)$booking['vatRate']<=100));
                    if(!$metadataValid){
                        $serverTax=resolveBookingAggregateTaxSnapshot(
                            $pdo,array_merge($booking,['bookingSource'=>$bookingSource]),$bookingTotalAmount,
                            $booking['checkIn']??date('Y-m-d')
                        );
                        $pdo->prepare("UPDATE bookings SET vatRate=?,vatAmount=?,extras=? WHERE id=?")
                            ->execute([
                                $serverTax['taxRate'],$serverTax['taxAmount'],
                                json_encode($serverTax['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id
                            ]);
                        $booking['vatRate']=$serverTax['taxRate'];
                        $booking['vatAmount']=$serverTax['taxAmount'];
                        $booking['extras']=$serverTax['extras'];
                    }
                }

                if ($ledgerBeforeSettlement['net'] > $bookingTotalAmount + 1) {
                    http_response_code(409);
                    throw new RuntimeException('Penerimaan booking sudah melebihi tagihan. Checkout dihentikan agar kas tidak dihitung ganda.');
                }
                $remainingTotalAmount = max(0.0, round($bookingTotalAmount - $ledgerBeforeSettlement['net'], 2));
                $deferFinancialClosure=$financialDisposition==='defer'&&$remainingTotalAmount>0.01;
                if($deferFinancialClosure){
                    if($vacancyReportId==='')throw new RuntimeException('Checkout operasional dengan piutang wajib berasal dari laporan kamar kosong terverifikasi.');
                    if(!in_array($checkoutMode,['field_verified','manager_override'],true))throw new RuntimeException('Mode checkout operasional tidak valid untuk penundaan tagihan.');
                    if(tamasyaStringLength($financialClosureReason)<5)throw new InvalidArgumentException('Alasan piutang checkout minimal 5 karakter.');
                }
                $bookingVatRate = is_numeric($booking['vatRate'] ?? null) ? max(0.0, (float)$booking['vatRate']) : null;
                $remainingVatAmount = 0.0;
                $remainingBaseAmount = $remainingTotalAmount;

                if ($remainingTotalAmount > 0 && !$deferFinancialClosure) {
                    if ($checkoutPaymentMethod === '') {
                        http_response_code(422);
                        throw new InvalidArgumentException('Metode pembayaran pelunasan wajib dipilih dari transaksi checkout saat ini; metode lama booking tidak boleh diwarisi otomatis.');
                    }
                    // Alokasikan PBJT berdasarkan snapshot agregat booking dan saldo
                    // transaksi bertanda (income dikurangi refund/expense). Jangan
                    // mengasumsikan seluruh tagihan memakai tarif kamar tunggal.
                    $checkoutTaxAllocation=resolveBookingPaymentTaxAllocation(
                        $pdo,$booking,$remainingTotalAmount,(float)$ledgerBeforeSettlement['net'],
                        $booking['checkIn']??date('Y-m-d')
                    );
                    $settlementSnapshot=$checkoutTaxAllocation['snapshot'];
                    if(empty($settlementSnapshot['preserved'])){
                        $pdo->prepare("UPDATE bookings SET vatRate=?,vatAmount=?,extras=? WHERE id=?")
                            ->execute([
                                $settlementSnapshot['taxRate'],$settlementSnapshot['taxAmount'],
                                json_encode($settlementSnapshot['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id
                            ]);
                        $booking['vatRate']=$settlementSnapshot['taxRate'];
                        $booking['vatAmount']=$settlementSnapshot['taxAmount'];
                        $booking['extras']=$settlementSnapshot['extras'];
                    }
                    $bookingVatRate=$checkoutTaxAllocation['taxRate'];
                    $remainingVatAmount=(float)$checkoutTaxAllocation['taxAmount'];
                    $remainingBaseAmount=(float)$checkoutTaxAllocation['baseAmount'];
                    $isSplitPayment = !empty($input['isSplitPayment']) || $checkoutPaymentMethod === 'split';
                    $splitCashAmount = isset($input['splitCashAmount']) ? round((float)$input['splitCashAmount'], 2) : 0.0;
                    $splitTransferAmount = isset($input['splitTransferAmount']) ? round((float)$input['splitTransferAmount'], 2) : 0.0;
                    $splitTransferBankAccountId = $input['splitTransferBankAccountId'] ?? null;
                    $postSettlement=function(string $transactionId,float $postingAmount,string $postingDescription,?string $postingBank,float $postingBase,float $postingTax,string $postingOperation,array $splitSnapshot=[]) use ($pdo,$roomCategoryName,$booking,$roomNumber,$id,$bookingSource,$bookingVatRate,$loggedInStaff): bool {
                        // A lost HTTP response may legitimately replay the same checkout command.
                        // Reuse is allowed only after the central posting authority proves that
                        // the complete financial intent is identical to the committed row.
                        $exists=$pdo->prepare("SELECT id FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");
                        $exists->execute([$postingOperation]);
                        $wasExisting=(bool)$exists->fetchColumn();
                        $postingRequiresShift=!empty($splitSnapshot['isSplitPayment'])
                            ? ((float)($splitSnapshot['splitCashAmount']??0)>0.009)
                            : tamasyaManualTransactionRequiresOpenShift(['type'=>'income','amount'=>$postingAmount,'bankAccountId'=>$postingBank]);
                        $postingShiftSessionId=null;
                        if(!$wasExisting)$postingShiftSessionId=resolveOpenShiftSessionId($pdo,$loggedInStaff,$postingRequiresShift);
                        // A live booking settlement is covered by
                        // tamasyaAssertLiveTaxSnapshotIntegrity(), which requires the tax
                        // contract on booking income. The settlement amount is an
                        // allocation of the booking's aggregate PBJT snapshot, not a new
                        // sale under one live rule; the allocation attach below fills the
                        // final component rule evidence.
                        tamasyaPostFinancialTransaction($pdo,[
                            'id'=>$transactionId,'type'=>'income','category'=>$roomCategoryName,'categorySystemKey'=>'room_rental','subcategory'=>$booking['roomType']??null,
                            'roomNumber'=>$roomNumber,'amount'=>$postingAmount,'date'=>date('Y-m-d'),'description'=>$postingDescription,'createdBy'=>currentStaffLabel($loggedInStaff),
                            'bankAccountId'=>$postingBank,'bookingId'=>$id,'bookingSource'=>$bookingSource,'baseAmount'=>$postingBase,'taxAmount'=>$postingTax,
                            'taxRate'=>$bookingVatRate,'transactionKind'=>'settlement','sourceEntity'=>'booking','sourceEntityId'=>$id,'isSystemGenerated'=>1,
                            'operationId'=>$postingOperation,'shiftSessionId'=>$postingShiftSessionId,'updatedBy'=>$loggedInStaff['id']??null,
                            'taxRuleId'=>null,
                            'taxRuleName'=>null,
                            'taxSource'=>'booking_component_allocation','taxSnapshotStatus'=>'confirmed',
                            'updatedSource'=>'web','version'=>1,
                            'isSplitPayment'=>!empty($splitSnapshot['isSplitPayment'])?1:0,
                            'splitCashAmount'=>$splitSnapshot['splitCashAmount']??0,
                            'splitTransferAmount'=>$splitSnapshot['splitTransferAmount']??0,
                            'splitTransferBankAccountId'=>$splitSnapshot['splitTransferBankAccountId']??null,
                        ],$loggedInStaff,'booking',['lockCatalog'=>true,'source'=>'web','idempotentByOperationId'=>true]);
                        return !$wasExisting;
                    };
                    if ($isSplitPayment) {
                        $splitTransferBankAccountId=trim((string)$splitTransferBankAccountId);
                        if ($splitCashAmount <= 0 || $splitTransferAmount <= 0 || $splitTransferBankAccountId===''
                            || !moneyMatches($splitCashAmount + $splitTransferAmount, $remainingTotalAmount, 0.01)) {
                            http_response_code(422);
                            throw new InvalidArgumentException('Nominal split checkout harus sama dengan sisa tagihan dan akun transfer/QRIS wajib dipilih.');
                        }
                        tamasyaInferPaymentMethodFromAccount($pdo,$splitTransferBankAccountId,true,['transfer','qris']);
                        // Satu checkout split = satu transaksi canonical.  Ini
                        // menyamakan bentuk ledger dengan backfill/offline dan membuat
                        // refund/reversal dapat mempertahankan kedua kaki settlement.
                        $splitSettlementId='tx_settle_' . substr(hash('sha256',$id),0,28);
                        $splitSettlementOp='booking:'.$id.':settlement';
                        if($postSettlement(
                            $splitSettlementId,$remainingTotalAmount,
                            'Pelunasan Kamar '.$roomNumber.' - '.$booking['guestName'].' (Split Tunai + Transfer/QRIS)',
                            null,$remainingBaseAmount,$remainingVatAmount,$splitSettlementOp,
                            ['isSplitPayment'=>1,'splitCashAmount'=>$splitCashAmount,'splitTransferAmount'=>$splitTransferAmount,'splitTransferBankAccountId'=>$splitTransferBankAccountId]
                        )){
                            tamasyaAttachBookingReceiptRevenueAllocations($pdo,$booking,$splitSettlementId,$remainingTotalAmount,(float)$ledgerBeforeSettlement['net'],$splitSettlementOp,$loggedInStaff,'web',$booking['checkIn']??date('Y-m-d'));
                        }
                        $pdo->prepare("UPDATE bookings SET paymentStatus='paid',paymentMethod='split',bankAccountId=NULL,isSplitPayment=1,splitCashAmount=?,splitTransferAmount=?,splitTransferBankAccountId=? WHERE id=?")
                            ->execute([$splitCashAmount,$splitTransferAmount,$splitTransferBankAccountId,$id]);
                    } else {
                        if($checkoutPaymentMethod==='ota' && !tamasyaIsExplicitOtaSourceLabel($bookingSource)){
                            http_response_code(422);
                            throw new InvalidArgumentException('Piutang OTA hanya boleh dipakai untuk booking eksternal/OTA.');
                        }
                        $checkoutBankAccountId=tamasyaResolvePaymentAccount($pdo,$checkoutPaymentMethod,$checkoutBankAccountId,[
                            'allowedMethods'=>['cash','transfer','qris','ota'],'lock'=>true,'context'=>'Pelunasan checkout'
                        ]);
                        $label = $checkoutPaymentMethod === 'qris' ? 'QRIS' : ($checkoutPaymentMethod === 'transfer' ? 'Transfer' : ($checkoutPaymentMethod === 'ota' ? 'Piutang OTA' : 'Tunai'));
                        $settlementId='tx_settle_' . substr(hash('sha256',$id),0,28);
                        $settlementOp='booking:'.$id.':settlement';
                        if($postSettlement($settlementId,$remainingTotalAmount,'Pelunasan Kamar '.$roomNumber.' - '.$booking['guestName'].' ('.$label.')',$checkoutBankAccountId,$remainingBaseAmount,$remainingVatAmount,$settlementOp)){
                            tamasyaAttachBookingReceiptRevenueAllocations($pdo,$booking,$settlementId,$remainingTotalAmount,(float)$ledgerBeforeSettlement['net'],$settlementOp,$loggedInStaff,'web',$booking['checkIn']??date('Y-m-d'));
                        }
                        $pdo->prepare("UPDATE bookings SET paymentStatus='paid',paymentMethod=?,bankAccountId=?,isSplitPayment=0,splitCashAmount=NULL,splitTransferAmount=NULL,splitTransferBankAccountId=NULL WHERE id=?")
                            ->execute([$checkoutPaymentMethod,$checkoutBankAccountId,$id]);
                    }
                } elseif($remainingTotalAmount<=0.01) {
                    $pdo->prepare("UPDATE bookings SET paymentStatus='paid',financialClosureStatus=CASE WHEN financialClosureStatus='pending' THEN 'settled' ELSE financialClosureStatus END,financialClosureBalance=0 WHERE id=?")->execute([$id]);
                }
                if($deferFinancialClosure){
                    tamasyaMarkBookingFinancialClosurePending($pdo,$loggedInStaff,(string)$id,$checkoutOperationId,$financialClosureReason,'web');
                }
                tamasyaRuntimeSetStage('bookings-status:checkout_invariant');
                assertBookingLedgerInvariant($pdo, $id, $loggedInStaff, 'web', false);
                tamasyaRuntimeSetStage('bookings-status:checkout_operational');
                $operational=finalizeCheckoutOperationalLifecycle($pdo,$loggedInStaff,$booking,'web',[
                    'keyDisposition'=>$keyDisposition,'keyReason'=>$keyReason,'checkoutMode'=>$checkoutMode,
                    'operationId'=>$checkoutOperationId,'vacancyReportId'=>$vacancyReportId
                ]);
                assertBookingLedgerInvariant($pdo, $id, $loggedInStaff, 'web', false);
                $deferredSmartLockJobId=(string)($operational['smartLockJobId']??'');
            }

            tamasyaRuntimeSetStage('bookings-status:audit');
            $statusTransitionStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");$statusTransitionStmt->execute([$id]);$statusTransitionAudit=$statusTransitionStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$statusTransitionAudit)throw new RuntimeException('State booking sesudah perubahan status tidak ditemukan.');
            writeRequiredEnterpriseAudit(
                $pdo,$loggedInStaff,
                $status==='active'?'Check-in reservasi':($status==='completed'?'Checkout booking':'Membatalkan booking'),
                'booking',(string)$id,tamasyaBookingAuditSnapshot($booking),tamasyaBookingAuditSnapshot($statusTransitionAudit),'web'
            );
            bumpServerRevision($pdo);

            tamasyaRuntimeSetStage('bookings-status:notification');
            // 3. Tambah Notifikasi & Telegram Broadcast
            $notifId = generateServerId('n');
            $actionWord = $status === 'completed' ? "Check-Out Selesai" : ($status === 'active' ? "Check-In Selesai" : "Pembatalan");
            $notifMsg = "$actionWord Kamar $roomNumber atas nama " . $booking['guestName'] . " berhasil diproses.";
            $stmt = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'booking')");
            $stmt->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);

            if ($status === 'active') {
                $postCommitTelegramMessage = "✅ *CHECK-IN RESERVASI*\n\n👤 Tamu: *" . $booking['guestName'] . "*\n🔑 Kamar: *" . $roomNumber . "*\n🗓 Jadwal: *" . ($booking['checkIn'] ?? '') . "* s.d. *" . ($booking['checkOut'] ?? '') . "*";
            } elseif ($status === 'completed') {
                $stmtLatest = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
                $stmtLatest->execute([$id]);
                $latestBooking = $stmtLatest->fetch();
                $postCommitTelegramMessage = getCheckOutTelegramMessage($latestBooking, $latestBooking['roomType'] ?? 'Standard', "Aplikasi");
            } elseif ($status === 'cancelled') {
                $cancelRoomMessage=$currentBookingStatus==='active'
                    ? 'Booking aktif dihentikan. Kamar masuk *🛠️ MAINTENANCE* dan wajib melewati housekeeping sebelum siap dijual kembali.'
                    : 'Reservasi mendatang dibatalkan. Tidak ada okupansi aktif yang harus ditutup.';
                $postCommitTelegramMessage = "⚠️ *RESERVASI DIBATALKAN* (Aplikasi)\n\n" .
                         "👤 Tamu: *" . $booking['guestName'] . "*\n" .
                         "🔑 Kamar: *" . $roomNumber . "*\n\n" .
                         $cancelRoomMessage;
            }

            tamasyaRuntimeSetStage('bookings-status:commit');
            tamasyaFinancialCommit($pdo);

            tamasyaRuntimeSetStage('bookings-status:post_commit');
            $smartLockResult=null;
            if($deferredSmartLockJobId!==''){
                try{$smartLockResult=processDeferredCheckoutSmartLockJob($pdo,$deferredSmartLockJobId,$loggedInStaff,'web');}
                catch(Throwable $postCommitError){error_log('[checkout] post-commit smart-lock: '.$postCommitError->getMessage());$postCommitWarnings[]='Checkout tersimpan, tetapi status smart-lock perlu diperiksa.';}
            }
            if($postCommitTelegramMessage!==null){
                try{broadcastTelegramNotification($pdo,$postCommitTelegramMessage);}
                catch(Throwable $postCommitError){error_log('[checkout] post-commit Telegram: '.$postCommitError->getMessage());$postCommitWarnings[]='Checkout tersimpan, tetapi broadcast Telegram gagal.';}
            }

            tamasyaRuntimeSetStage('bookings-status:response');
            $statusResponse=[
                "success" => true,
                "message" => $status === 'active' ? "Check-in reservasi berhasil diproses." : "Check-Out / Pembatalan berhasil diproses di database MySQL!",
                "operationId" => $checkoutOperationId ?? null,
                "securityDeposit" => $securityDepositSettlement,
                "smartLock" => $smartLockResult,
                "warnings" => $postCommitWarnings
            ];
            if($compactStatusResponse){
                try{$statusResponse['delta']=tamasyaBookingCreateCompactDelta($pdo,$loggedInStaff,['bookingId'=>$id,'roomNumber'=>$roomNumber,'transactionIds'=>[]]);}
                catch(Throwable $deltaError){error_log(clientExceptionMessage('[checkin compact delta] refresh penuh diperlukan',$deltaError));$statusResponse['delta']=null;$statusResponse['refreshRequired']=true;}
            }else{$statusResponse['db']=getRoleScopedHotelData($pdo,$loggedInStaff);}
            echo json_encode($statusResponse);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal update status booking", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/transaction-booking-action
    // Mengalokasikan transaksi kas manual ke kamar/perpanjangan/extra tanpa menimpa transaksi sumber.
    // ----------------------------------------------------------------
    case 'transaction-booking-action':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
            break;
        }

        try {
            $pdo->beginTransaction();
            $result = processManualBookingAction($pdo, $loggedInStaff, $input, 'web');

            if (($result['status'] ?? '') === 'error') {
                $pdo->rollBack();
                http_response_code((int)($result['http'] ?? 400));
                echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Gagal memproses transaksi.']);
                break;
            }
            if (($result['status'] ?? '') === 'conflict') {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => $result['conflict']['message'] ?? 'Data berubah di server.', 'conflict' => $result['conflict']]);
                break;
            }

            if (($result['status'] ?? '') === 'applied') {
                bumpServerRevision($pdo);
            }
            tamasyaFinancialCommit($pdo);
            $manualAllocationSmartLockResult=null;
            if(!empty($result['smartLockRefreshJobId'])){
                try{$manualAllocationSmartLockResult=processSmartLockBridgeJobById($pdo,(string)$result['smartLockRefreshJobId'],$loggedInStaff,'manual-extension-refresh');}
                catch(Throwable $smartLockError){$manualAllocationSmartLockResult=['status'=>'error','message'=>clientExceptionMessage('Refresh smart-lock perpanjangan belum selesai',$smartLockError)];}
            }

            if (($result['status'] ?? '') === 'applied' && empty($result['reportingOnly'])) {
                $tax = $result['tax'];
                $taxText = (float)$tax['taxRate'] > 0
                    ? "
🏷️ PBJT {$tax['taxRate']}%: Rp " . number_format((float)$tax['taxAmount'], 0, ',', '.')
                    : "
🏷️ PBJT: Tidak dikenakan";
                broadcastTelegramNotification(
                    $pdo,
                    "🧾 *ALOKASI TRANSAKSI → " . strtoupper((string)$result['label']) . "*

🚪 Kamar: *{$result['roomNumber']}*
👤 Tamu: *{$result['guestName']}*
💰 Nominal: *Rp " . number_format((float)$result['amount'], 0, ',', '.') . "*{$taxText}
👤 Oleh: *" . currentStaffLabel($loggedInStaff) . '*',
                    true
                );
            }

            echo json_encode([
                'success' => true,
                'duplicate' => ($result['status'] ?? '') === 'duplicate',
                'message' => ($result['status'] ?? '') === 'duplicate'
                    ? 'Alokasi transaksi ini sudah diproses.'
                    : (!empty($result['reportingOnly'])
                        ? 'Arsip alokasi historis berhasil disimpan untuk laporan tanpa mengubah reservasi dibatalkan.'
                        : 'Rincian reservasi berhasil dialokasikan. Total tagihan booking Rp '
                            . number_format((float)($result['bookingTotalBefore'] ?? 0), 0, ',', '.')
                            . ' menjadi Rp '
                            . number_format((float)($result['bookingTotalAfter'] ?? 0), 0, ',', '.')
                            . '. Nominal receipt kas tetap Rp '
                            . number_format((float)($result['cashTransactionAmount'] ?? 0), 0, ',', '.')
                            . ' karena penerimaan sudah tercatat pada transaksi manual.'),
                'operationId' => $result['operationId'] ?? null,
                'smartLockRefresh' => $manualAllocationSmartLockResult,
                'bookingImpact' => ($result['status'] ?? '') === 'duplicate' ? null : [
                    'totalBefore' => (float)($result['bookingTotalBefore'] ?? 0),
                    'totalDelta' => (float)($result['bookingTotalDelta'] ?? 0),
                    'totalAfter' => (float)($result['bookingTotalAfter'] ?? 0),
                    'cashTransactionAmount' => (float)($result['cashTransactionAmount'] ?? 0),
                    'cashDelta' => (float)($result['cashDelta'] ?? 0),
                ],
                'db' => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success' => false, 'error' => clientExceptionMessage('Gagal memproses layanan/perpanjangan', $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/transaction-allocation-void
    // Pembatalan alokasi secara audit. Transaksi kas sumber tetap utuh.
    // ----------------------------------------------------------------
    case 'transaction-allocation-void':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        try {
            $pdo->beginTransaction();
            $result=voidTransactionAllocation($pdo,$loggedInStaff,$input,'web');
            if (($result['status']??'')==='error') {
                $pdo->rollBack();
                http_response_code((int)($result['http']??400));
                echo json_encode(['success'=>false,'error'=>$result['error']??'Gagal membatalkan alokasi.']);
                break;
            }
            if (($result['status']??'')==='voided') bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            $voidSmartLockRefresh=null;
            if(!empty($result['smartLockRefreshJobId'])){
                try{$voidSmartLockRefresh=processSmartLockBridgeJobById($pdo,(string)$result['smartLockRefreshJobId'],$loggedInStaff,'allocation-void-stay-refresh');}
                catch(Throwable $smartLockError){$voidSmartLockRefresh=['jobId'=>$result['smartLockRefreshJobId'],'status'=>'error','message'=>clientExceptionMessage('Refresh smart-lock pembatalan perpanjangan belum selesai',$smartLockError)];}
            }
            echo json_encode([
                'success'=>true,
                'duplicate'=>($result['status']??'')==='duplicate',
                'message'=>($result['status']??'')==='duplicate'?'Alokasi sudah dibatalkan sebelumnya.':'Alokasi dibatalkan; transaksi kas sumber tetap tersimpan.',
                'smartLockRefresh'=>$voidSmartLockRefresh,
                'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
            ]);
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal membatalkan alokasi transaksi',$e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST/PUT /api/booking-audit-correction
    // Koreksi reservasi dan transaksi sumber secara atomik setelah audit manual.
    // Dapat dipakai setelah checkout. Transaksi shift yang sudah dikunci tidak
    // ditimpa; server membuat pembalik + pengganti agar jejak audit tetap utuh.
    // ----------------------------------------------------------------
}
