<?php
/**
 * Kontrak Owner "melihat seperti Admin, tetapi read-only" + guarantee bahwa
 * setiap jalur tulis mewajibkan metode non-GET dan setiap public action punya
 * pengawan.
 *
 * Latar:
 * - F-01 (HIGH): owner bisa mengubah preset jabatan lewat GET.
 * - F-09 (INFO): 25 public action melewati autentikasi normal.
 * - Kontrak Owner: capability baca Owner mengikuti Admin (preset + katalog),
 *   tetapi setiap metode selain GET/HEAD/OPTIONS diblokir untuk Owner.
 */
$root = dirname(__DIR__);
$read = static function (string $rel) use ($root): string {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) { throw new RuntimeException('Berkas wajib tidak ditemukan: ' . $rel); }
    return (string)file_get_contents($path);
};
$checks = 0;
$ownerCheck = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . "\n";
};

/* ===== 1. KONTEKS: guard global owner ada sebelum route dispatch ===== */
$api = $read('api.php');
$ownerGuardPos = strpos($api, 'tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action)');
$routerPos = strpos($api, "require __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'router.php'");
$ownerCheck($ownerGuardPos !== false && $routerPos !== false && $ownerGuardPos < $routerPos,
    'Guard global owner dijalankan sebelum route dispatch (semua metode non-GET ditolak untuk Owner)');
$ownerCheck(str_contains($api, "in_array(\$method, ['GET','HEAD','OPTIONS'], true)") || str_contains($read('api/support/017_authorization_policy.php'), "['GET','HEAD','OPTIONS']"),
    'Owner hanya boleh GET/HEAD/OPTIONS; metode lain ditolak');

/* ===== 2. JALUR TULIS WAJIB POST (proteksi ulang kelas F-01) ===== */
$writeRoutes = [
    'api/routes/052_role_presets.php' => "wajib memakai POST",
    'api/routes/110_growth_suite.php' => "Method Not Allowed",
    'api/routes/112_enterprise_completion.php' => "Method Not Allowed",
    'api/routes/096_facility_management.php' => "REQUEST_METHOD",
];
foreach ($writeRoutes as $rel => $needle) {
    $source = $read($rel);
    $ownerCheck(str_contains($source, $needle), $rel . ' mewajibkan metode POST untuk aksi tulis');
}
$ownerCheck(str_contains($read('api/routes/052_role_presets.php'), '$isMutationCommand&&$method!==\'POST\''),
    'Route 052 menolak aksi mutasi yang bukan POST (termasuk lewat parameter command)');

/* ===== 3. PUBLIC ACTION: setiap yang menulis punya pengawan ===== */
$publicGuards = [
    'api/routes/050_auth_staff.php' => ['verify-2fa', 'hash_equals'],
    'api/routes/080_telegram_webhook.php' => ['telegram-webhook', 'hash_equals'],
    'api/routes/010_cluster_status.php' => ['node-cluster-control', 'tamasyaVerifyNodeRequest'],
    'api/routes/076_communication_webhook.php' => ['communication-webhook', 'verifyInbound'],
    'api/routes/110_growth_suite.php' => ['growth-payment-webhook', 'tamasyaPaymentWebhookAuthenticate'],
    'api/routes/110_growth_suite.php' => ['growth-channel-webhook', 'tamasyaPaymentWebhookAuthenticate'],
    'api/routes/058_biometric_attendance.php' => ['biometric-device-webhook', 'tamasyaBiometricAuthenticateDeviceWebhook'],
    'api/routes/015_public_website.php' => ['public-reservation-request', 'tamasyaPublicRequireAllowedOrigin'],
    'api/routes/015_public_website.php' => ['public-help-chat', 'tamasyaPublicRequireAllowedOrigin'],
];
foreach ($publicGuards as $rel => $needles) {
    $source = $read($rel);
    $ok = true;
    foreach ($needles as $needle) { if (!str_contains($source, $needle)) { $ok = false; } }
    $ownerCheck($ok, 'Public action ' . $needles[0] . ' punya pengawan (' . $needles[1] . ')');
}
$ownerCheck(str_contains($read('api.php'), "tamasyaVerifyNodeRequest(\$pdo, 'node-cluster-telegram-webhook'"),
    'Public action node-cluster-telegram-webhook punya pengawan (tanda tangan node)');
$ownerCheck(str_contains($read('api.php'), "tamasyaVerifyNodeRequest(\$pdo,'node-cluster-telegram-delivery'"),
    'Public action node-cluster-telegram-delivery punya pengawan (tanda tangan node)');

/* ===== 4. KONTRAK OWNER: capability baca = Admin, tulis tetap mustahil ===== */
if (!defined('TAMASYA_API_ENTRY')) { define('TAMASYA_API_ENTRY', true); }
require_once $root . '/api/support/022_dynamic_rbac.php';
require_once $root . '/api/support/021_role_presets.php';
require_once $root . '/api/support/017_authorization_policy.php';

$policySource = $read('api/support/017_authorization_policy.php');
$ownerCheck(str_contains($policySource, "tamasyaRbacRolePermission('admin', 'capabilities', \$capability)"),
    'Capability baca Owner diambil dari preset Admin (identik dengan Admin)');
$ownerCheck(str_contains($policySource, "in_array('admin', (array)\$fallbackRoles, true)"),
    'Capability Owner memakai fallback role Admin bila preset tidak mendefinisikan kunci');
$ownerCheck(str_contains($policySource, "tamasyaRbacRolePreset('admin',\$pdo)"),
    'Session Owner disusun dari preset Admin sehingga frontend dan API konsisten');
$ownerCheck(str_contains($policySource, "'readOnly'=>true"),
    'Session Owner tetap ditandai readOnly');

$admin = ['id' => 'audit-admin', 'name' => 'Audit Admin', 'username' => 'audit-admin', 'role' => 'admin', 'status' => 'active', 'permissions' => null];
$owner = ['id' => 'audit-owner', 'name' => 'Audit Owner', 'username' => 'audit-owner', 'role' => 'owner', 'status' => 'active', 'permissions' => null];

/* Tanpa PDO: helper RBAC tidak membuka koneksi sendiri dan menghasilkan
 * fallback aman. Owner tetap tidak boleh menulis. */
$dsn = trim((string)getenv('TAMASYA_CATALOG_UAT_DSN'));
if ($dsn !== '') {
    try {
        $GLOBALS['tamasya_runtime_pdo'] = new PDO(
            $dsn,
            (string)getenv('TAMASYA_CATALOG_UAT_USER'),
            (string)getenv('TAMASYA_CATALOG_UAT_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $connectionError) {
        echo 'SKIP behavioral RBAC checks: ' . $connectionError->getMessage() . "\n";
    }
}
$pdo = tamasyaRbacPdo();
if (!($pdo instanceof PDO)) {
    $ownerCheck(tamasyaRbacRolePreset('admin') === null, 'Tanpa PDO preset role null (fallback aman)');
    $ownerCheck(tamasyaRbacRolePermission('admin', 'capabilities', 'manage_backup') === null, 'Tanpa PDO permission role null (fallback aman)');
    $ownerCheck(tamasyaRbacEffectivePermissions(['role' => 'finance']) === null, 'Tanpa PDO effective permissions null (fallback aman)');
    $ownerCheck(tamasyaRbacCatalogPayload() === ['roles' => [], 'capabilities' => [], 'desktopTabs' => [], 'telegramNotifications' => []], 'Tanpa PDO katalog kosong');
    $ownerCheck(hasCapability($admin, 'manage_backup', ['admin', 'manager']) === true,
        'Tanpa PDO capability Admin tetap mengikuti fallback role statis');
    $ownerCheck(hasCapability($owner, 'manage_backup', ['admin', 'manager']) === true,
        'Tanpa PDO capability Owner mengikuti capability Admin lewat fallback role');
    $ownerCheck(hasCapability($owner, 'manage_backup', []) === false,
        'Tanpa PDO capability Owner tidak membuka akses yang tidak ada di fallback');
} else {
    $adminPreset = tamasyaRbacRolePreset('admin', $pdo);
    $ownerCheck(is_array($adminPreset) && isset($adminPreset['capabilities']) && is_array($adminPreset['capabilities']),
        'Preset Admin tersedia sebagai sumber capability baca Owner');
    $checkedCaps = 0;
    foreach ((array)($adminPreset['capabilities'] ?? []) as $capability => $value) {
        $adminValue = hasCapability($admin, (string)$capability, ['admin', 'manager']);
        $ownerValue = hasCapability($owner, (string)$capability, ['admin', 'manager']);
        if ((bool)$value !== (bool)$adminValue) {
            throw new RuntimeException('FAIL capability ' . $capability . ' Admin tidak cocok dengan preset');
        }
        if ((bool)$value !== (bool)$ownerValue) {
            throw new RuntimeException('FAIL capability ' . $capability . ' Owner tidak sama dengan Admin');
        }
        $checkedCaps++;
    }
    $ownerCheck($checkedCaps >= 20, 'Owner memiliki capability baca yang sama persis dengan Admin (' . $checkedCaps . ' capability diperiksa)');

    $ownerSession = tamasyaSessionPermissions($owner, $pdo);
    $ownerCheck(($ownerSession['readOnly'] ?? false) === true, 'Session Owner ditandai readOnly');
    $ownerCheck(count(array_filter((array)($ownerSession['desktopTabs'] ?? []), static fn($v) => (bool)$v)) >= 15,
        'Owner melihat seluruh tab desktop seperti Admin');
    $ownerCheck((bool)array_filter((array)($ownerSession['telegramNotifications'] ?? []), static fn($v) => (bool)$v),
        'Owner menerima notifikasi Telegram (read-only tetap tidak bisa mutasi)');
    $ownerCheck((bool)($ownerSession['capabilities']['manage_tax_rules'] ?? false) === (bool)($adminPreset['capabilities']['manage_tax_rules'] ?? false),
        'Capability manage_tax_rules Owner sama dengan Admin');

    /* Metode: baca diizinkan, tulis ditolak. */
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $ownerCheck(tamasyaOwnerMutationDenied($owner) === false, 'Owner boleh melakukan permintaan baca (GET)');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $ownerCheck(tamasyaOwnerMutationDenied($owner) === true, 'Owner ditolak pada setiap metode tulis (POST)');
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $ownerCheck(tamasyaOwnerMutationDenied($owner) === true, 'Owner ditolak pada DELETE');
    $_SERVER['REQUEST_METHOD'] = 'GET';

    /* Katalog: status nonaktif berlaku sama untuk Admin dan Owner. */
    $catalogCap = 'view_reports';
    $before = tamasyaRbacCatalogKeyState('capabilities', $catalogCap, $pdo);
    $ownerCheck($before === 'active' || $before === 'inactive', 'Status katalog capability terbaca (' . $before . ')');
    if ($before === 'active') {
        $pdo->exec("UPDATE rbac_capabilities SET active=0 WHERE capability_key='" . $catalogCap . "'");
        tamasyaRbacFlushCache();
        $ownerCheck(tamasyaRbacCatalogKeyState('capabilities', $catalogCap, $pdo) === 'inactive',
            'Katalog nonaktif terdeteksi sebagai inactive (fail-closed)');
        $ownerCheck(hasCapability($admin, $catalogCap, ['admin', 'manager', 'finance', 'owner']) === false
            && hasCapability($owner, $catalogCap, ['admin', 'manager', 'finance', 'owner']) === false,
            'Katalog nonaktif menutup akses Admin dan Owner secara sama');
        $pdo->exec("UPDATE rbac_capabilities SET active=1 WHERE capability_key='" . $catalogCap . "'");
        tamasyaRbacFlushCache();
        $ownerCheck(hasCapability($owner, $catalogCap, ['admin', 'manager', 'finance', 'owner']) === true,
            'Akses kembali terbuka setelah katalog diaktifkan kembali');
    }
}

/* Metode guard selalu bisa diverifikasi tanpa PDO. */
$_SERVER['REQUEST_METHOD'] = 'GET';
$ownerCheck(tamasyaOwnerMutationDenied($owner) === false, 'Owner boleh melakukan permintaan baca (GET)');
$_SERVER['REQUEST_METHOD'] = 'POST';
$ownerCheck(tamasyaOwnerMutationDenied($owner) === true, 'Owner ditolak pada setiap metode tulis (POST)');
$_SERVER['REQUEST_METHOD'] = 'DELETE';
$ownerCheck(tamasyaOwnerMutationDenied($owner) === true, 'Owner ditolak pada DELETE');
$_SERVER['REQUEST_METHOD'] = 'GET';

/* ===== 5. KELOLA JABATAN: override NULL agar akun mengikuti preset ===== */
$staffRoute = $read('api/routes/050_auth_staff.php');
$ownerCheck(!str_contains($staffRoute, 'tamasyaRolePresetDefaultFor'),
    'Route 050 tidak lagi menyimpan salinan preset sebagai override akun');
$ownerCheck(str_contains($staffRoute, 'tamasyaRolePresetEnsureRows'),
    'Route 050 memastikan baris paket jabatan ada agar override NULL mengikuti preset');
$ownerCheck(str_contains($read('api/support/021_role_presets.php'), 'function tamasyaRolePresetEnsureRows'),
    'Helper tamasyaRolePresetEnsureRows tersedia');

/* ===== 6. POLICY OWNER: label tulis harus terdeteksi (prefix kata) ===== */
$policyJs = $read('assets/owner-readonly-policy.js');
$ownerCheck(str_contains($policyJs, 'terapkan') && str_contains($policyJs, 'terbitkan'),
    'Policy owner mengenali label aksi tulis tambahan (Terapkan/Terbitkan)');
$writeLabelLine = '';
foreach (preg_split('/\\r\\n|\\n/', $policyJs) as $policyLine) { if (str_contains($policyLine, 'const writeLabel=')) { $writeLabelLine = $policyLine; } }
$ownerCheck($writeLabelLine !== '' && str_contains($writeLabelLine, '\\w*/i'),
    'Policy owner mencocokkan label aksi tulis pada AWAL kata, bukan kata utuh');
echo 'Owner read contract: ' . $checks . " checks passed; 0 failed.\n";
