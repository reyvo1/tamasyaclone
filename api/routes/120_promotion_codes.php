<?php
/** Promotion codes: dynamic promo/voucher foundation (backend only, feature-flagged). */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'promotion-codes') return;
$routeHandled = true;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action);
$command = trim((string)($method === 'GET' ? ($_GET['command'] ?? 'list') : ($input['command'] ?? $_GET['command'] ?? 'list')));
$role = (string)($loggedInStaff['role'] ?? '');

requireRoles($loggedInStaff, ['admin', 'manager', 'finance', 'owner']);

if (!tamasyaPromotionCodesEnabled()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Fitur promotion codes dinonaktifkan.']);
    return;
}
if (!tamasyaPromotionCodesSchemaReady($pdo)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Schema promotion codes belum terpasang.']);
    return;
}

try {
    switch ($command) {
        case 'status':
            echo json_encode(['success' => true, 'data' => [
                'enabled' => tamasyaPromotionCodesEnabled(),
                'schemaReady' => tamasyaPromotionCodesSchemaReady($pdo),
            ]]);
            break;

        case 'list':
            $filters = [];
            if (isset($_GET['status'])) $filters['status'] = trim((string)$_GET['status']);
            if (isset($_GET['appliesTo'])) $filters['appliesTo'] = trim((string)$_GET['appliesTo']);
            echo json_encode(['success' => true, 'data' => tamasyaPromotionCodesList($pdo, $filters)]);
            break;

        case 'save':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); break; }
            $input = is_array($input ?? null) ? $input : [];
            $id = trim((string)($input['id'] ?? ''));
            $operationId = trim((string)($input['operationId'] ?? ''));
            if ($operationId === '') $operationId = 'promo_' . bin2hex(random_bytes(8));
            if ($id === '') {
                $row = tamasyaPromotionCodeCreate($pdo, $loggedInStaff, $operationId, $input);
            } else {
                $row = tamasyaPromotionCodeUpdate($pdo, $id, $loggedInStaff, $input);
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        case 'status-update':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); break; }
            $input = is_array($input ?? null) ? $input : [];
            $id = trim((string)($input['id'] ?? ''));
            $status = trim((string)($input['status'] ?? ''));
            if ($id === '' || $status === '') { http_response_code(400); echo json_encode(['success'=>false,'message'=>'ID dan status wajib.']); break; }
            $row = tamasyaPromotionCodeUpdate($pdo, $id, $loggedInStaff, ['status' => $status]);
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        case 'validate':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method Not Allowed']); break; }
            $input = is_array($input ?? null) ? $input : [];
            $code = trim((string)($input['code'] ?? ''));
            $appliesTo = trim((string)($input['appliesTo'] ?? 'both'));
            $amount = (float)($input['amount'] ?? 0);
            $result = tamasyaPromotionCodeValidate($pdo, $code, $appliesTo, $amount);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        case 'usage-list':
        case 'usages':
            $filters = [];
            if (isset($_GET['promotionCodeId']) || isset($_GET['promotion_code_id']) || isset($_GET['promoCodeId']) || isset($_GET['promo_code_id'])) {
                $filters['promotionCodeId'] = trim((string)($_GET['promotionCodeId'] ?? $_GET['promotion_code_id'] ?? $_GET['promoCodeId'] ?? $_GET['promo_code_id']));
            }
            if (isset($_GET['usageType']) || isset($_GET['usage_type']) || isset($_GET['source'])) {
                $filters['usageType'] = trim((string)($_GET['usageType'] ?? $_GET['usage_type'] ?? $_GET['source']));
            }
            if (isset($_GET['actorId']) || isset($_GET['actor_id'])) {
                $filters['actorId'] = trim((string)($_GET['actorId'] ?? $_GET['actor_id']));
            }
            if (isset($_GET['operationId']) || isset($_GET['operation_id'])) {
                $filters['operationId'] = trim((string)($_GET['operationId'] ?? $_GET['operation_id']));
            }
            if (isset($_GET['propertyId']) || isset($_GET['property_id'])) {
                $filters['propertyId'] = trim((string)($_GET['propertyId'] ?? $_GET['property_id']));
            }
            if (!tamasyaPromotionCodeUsageSchemaReady($pdo)) {
                http_response_code(503);
                echo json_encode(['success' => false, 'error' => 'Schema promotion code usages belum terpasang.']);
                break;
            }
            echo json_encode(['success' => true, 'data' => tamasyaPromotionCodeUsagesList($pdo, $filters)]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Command promotion-codes tidak dikenal.']);
            break;
    }
} catch (Throwable $e) {
    tamasyaApplyExceptionHttpStatus($e, 500);
    echo json_encode(['success' => false, 'error' => clientExceptionMessage('Promotion codes gagal', $e)]);
}
