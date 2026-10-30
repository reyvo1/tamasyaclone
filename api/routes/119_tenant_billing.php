<?php
declare(strict_types=1);
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); exit; }
$ensure = static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS tenant_billing_accounts (
        property_id VARCHAR(190) NOT NULL PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        plan VARCHAR(50) NOT NULL DEFAULT 'dummy',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tenant_billing_invoices (
        id VARCHAR(190) NOT NULL PRIMARY KEY,
        property_id VARCHAR(190) NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
        status VARCHAR(20) NOT NULL DEFAULT 'issued',
        due_date DATE NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (property_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
$ensure($pdo);

$propertyId = '';
try {
    $r = $pdo->query("SELECT property_id FROM property_settings WHERE id='system_default' LIMIT 1")->fetchColumn();
    $propertyId = trim((string)$r);
} catch (Throwable $e) { $propertyId = ''; }
if ($propertyId === '') { $propertyId = trim((string)getenv('TAMASYA_PROPERTY_ID')); }

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$command = trim((string)($_GET['command'] ?? $_POST['command'] ?? ''));
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if ($command === '' && isset($input['command'])) { $command = trim((string)$input['command']); }

if ($action === 'tenant-billing') {
    $tenantBillingReadCommands = ['status', 'list-invoices'];
    if (in_array($command, $tenantBillingReadCommands, true)) {
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance', 'owner']);
    } else {
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
    }
    if ($command === 'status') {
        $stmt = $pdo->prepare("SELECT status,plan FROM tenant_billing_accounts WHERE property_id=? LIMIT 1");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->prepare("INSERT INTO tenant_billing_accounts (property_id,status,plan) VALUES (?,?,?)")->execute([$propertyId,'active','dummy']);
            $row = ['status'=>'active','plan'=>'dummy'];
        }
        echo json_encode(['success'=>true,'propertyId'=>$propertyId,'status'=>$row['status'],'plan'=>$row['plan']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($command === 'create-invoice') {
        $amount = (float)($input['amount'] ?? 0);
        $due = trim((string)($input['dueDate'] ?? ''));
        if ($amount <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'amount harus > 0']); exit; }
        if ($due === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { $due = date('Y-m-d', strtotime('+7 days')); }
        $id = 'inv_' . bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO tenant_billing_invoices (id,property_id,amount,currency,status,due_date) VALUES (?,?,?,?,?,?)")
            ->execute([$id,$propertyId,$amount,'IDR','issued',$due]);
        echo json_encode(['success'=>true,'invoiceId'=>$id,'status'=>'issued'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($command === 'pay') {
        $id = trim((string)($input['invoiceId'] ?? ''));
        if ($id === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'invoiceId wajib']); exit; }
        $q = $pdo->prepare("SELECT status FROM tenant_billing_invoices WHERE id=? AND property_id=? LIMIT 1");
        $q->execute([$id,$propertyId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'invoice tidak ditemukan']); exit; }
        if ($row['status'] !== 'issued') { http_response_code(409); echo json_encode(['success'=>false,'error'=>'invoice status tidak issued']); exit; }
        $pdo->prepare("UPDATE tenant_billing_invoices SET status='paid', paid_at=NOW() WHERE id=? AND property_id=?")->execute([$id,$propertyId]);
        echo json_encode(['success'=>true,'invoiceId'=>$id,'status'=>'paid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($command === 'suspend') {
        $pdo->prepare("INSERT INTO tenant_billing_accounts (property_id,status,plan) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status='suspended'")->execute([$propertyId,'suspended','dummy']);
        $pdo->prepare("UPDATE tenant_billing_invoices SET status='overdue' WHERE property_id=? AND status='issued' AND due_date < CURDATE()")->execute([$propertyId]);
        echo json_encode(['success'=>true,'propertyId'=>$propertyId,'status'=>'suspended'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($command === 'auto-suspend') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM tenant_billing_invoices WHERE property_id=? AND status='issued' AND due_date < CURDATE()");
        $q->execute([$propertyId]);
        $cnt = (int)$q->fetchColumn();
        if ($cnt > 0) {
            $pdo->prepare("INSERT INTO tenant_billing_accounts (property_id,status,plan) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status='suspended'")->execute([$propertyId,'suspended','dummy']);
            $pdo->prepare("UPDATE tenant_billing_invoices SET status='overdue' WHERE property_id=? AND status='issued' AND due_date < CURDATE()")->execute([$propertyId]);
            echo json_encode(['success'=>true,'suspended'=>true,'overdueCount'=>$cnt], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success'=>true,'suspended'=>false,'overdueCount'=>0], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($command === 'reopen') {
        $pdo->prepare("INSERT INTO tenant_billing_accounts (property_id,status,plan) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status='active'")->execute([$propertyId,'active','dummy']);
        echo json_encode(['success'=>true,'propertyId'=>$propertyId,'status'=>'active'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($command === 'list-invoices') {
        $stmt = $pdo->prepare("SELECT id,amount,currency,status,due_date,paid_at,created_at FROM tenant_billing_invoices WHERE property_id=? ORDER BY created_at DESC");
        $stmt->execute([$propertyId]);
        echo json_encode(['success'=>true,'invoices'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'command tidak dikenal'], JSON_UNESCAPED_UNICODE);
    exit;
}
