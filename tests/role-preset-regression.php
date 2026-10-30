<?php
// Regresi Fase 2/3 "Kelola Jabatan": registry paket, seed default per jabatan (cermin
// preset editor staf app-core.js), normalisasi entri paket, pembangunan override akun,
// penambalan baris lama (Fase 3), dan penjagaan jalur rilis (migrasi, route, UI).
// Murni fungsi + cek statis, tanpa DB/server: penerapan ke akun hanya lewat route 052
// dan tidak diuji di sini.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
require dirname(__DIR__).'/api/support/017_authorization_policy.php';
require dirname(__DIR__).'/api/support/021_role_presets.php';

$passed = 0;
function presetCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name.PHP_EOL;
}

$roles = tamasyaRolePresetRoles();
$caps = tamasyaCapabilityRegistry();
$tabs = tamasyaDesktopTabRegistry();
$notifs = tamasyaTelegramNotificationRegistry();
$seed = tamasyaRolePresetSeed();

presetCheck($roles === ['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain'], 'Daftar jabatan = 9 non-owner (urutan kanonik)');
presetCheck(!in_array('owner', $roles, true), 'Owner tidak termasuk jabatan yang dapat dikelola');
presetCheck(count($caps) === 23 && count($tabs) === 15 && count($notifs) === 6, 'Registry 23 capability / 15 tab / 6 notifikasi');
presetCheck(count($seed) === 9 && !isset($seed['owner']), 'Seed mencakup 9 jabatan, tanpa owner');
presetCheck(array_keys($seed['admin']['desktopTabs']) === $tabs, 'Urutan kunci tab seed = registry (cermin app-core db)');
presetCheck(array_keys($seed['admin']['telegramNotifications']) === $notifs, 'Urutan kunci notifikasi seed = registry');
presetCheck(array_keys($seed['admin']['capabilities']) === $caps, 'Urutan kunci capability seed = registry');
$allBool = true;
foreach ($seed as $preset) {
    foreach (['desktopTabs','telegramNotifications','capabilities'] as $group) {
        foreach ($preset[$group] as $value) { if (!is_bool($value)) $allBool = false; }
    }
}
presetCheck($allBool, 'Semua nilai seed boolean tegas');

// Spot-check paritas dengan preset editor staf (PA(role,null)/Eh(role) app-core.js).
presetCheck($seed['manager']['desktopTabs']['staff'] === false && $seed['manager']['desktopTabs']['finance'] === false && $seed['manager']['capabilities']['manage_backup'] === false && $seed['manager']['capabilities']['approve_sensitive_actions'] === true, 'Seed manager: staf/finance tertutup, backup tertutup, approve terbuka');
presetCheck($seed['finance']['capabilities']['receive_payment_access'] === true && $seed['finance']['capabilities']['perform_night_audit'] === true && $seed['finance']['capabilities']['sell_room_access'] === false && $seed['finance']['desktopTabs']['rooms'] === false, 'Seed finance: kas & night audit terbuka, jual kamar/kamar tertutup');
presetCheck($seed['receptionist']['capabilities']['sell_room_access'] === true && $seed['receptionist']['capabilities']['manage_stay_access'] === true && $seed['receptionist']['capabilities']['approve_sensitive_actions'] === false && $seed['receptionist']['desktopTabs']['support'] === true && $seed['receptionist']['desktopTabs']['report'] === false, 'Seed resepsionis: jual/inap terbuka, approve & laporan tertutup');
presetCheck($seed['keamanan']['capabilities']['perform_night_audit'] === true && $seed['keamanan']['capabilities']['reconcile_payments'] === false && $seed['keamanan']['desktopTabs']['inventory'] === false, 'Seed keamanan: night audit saja, tanpa rekon/inventaris');
presetCheck($seed['cleaning_service']['capabilities']['perform_housekeeping'] === true && $seed['cleaning_service']['desktopTabs']['inventory'] === true && $seed['koki']['capabilities']['perform_housekeeping'] === false && $seed['koki']['desktopTabs']['inventory'] === true, 'Seed cleaning service & koki sesuai preset app-core');
presetCheck($seed['admin']['telegramNotifications'] === ['bookings'=>true,'finance'=>true,'inventory'=>true,'operations'=>true,'hr'=>true,'system'=>true] && $seed['cleaning_service']['telegramNotifications']['bookings'] === false && $seed['cleaning_service']['telegramNotifications']['inventory'] === true, 'Seed notifikasi Telegram: admin semua, cleaning service inventaris/operasional saja');

// Spot-check capability Fase 3 (default = fallback route: 095/017/018/096).
presetCheck($seed['admin']['capabilities']['view_reports'] === true && $seed['manager']['capabilities']['view_support_inbox'] === true && $seed['manager']['capabilities']['manage_facilities'] === true && $seed['finance']['capabilities']['view_reports'] === true && $seed['finance']['capabilities']['view_support_inbox'] === false && $seed['finance']['capabilities']['manage_facilities'] === false && $seed['receptionist']['capabilities']['view_support_inbox'] === true && $seed['receptionist']['capabilities']['view_reports'] === false && $seed['keamanan']['capabilities']['manage_facilities'] === false, 'Seed Fase 3: admin/manager penuh, finance laporan, resepsionis inbox, sisanya tertutup');
presetCheck($seed['admin']['capabilities']['daily_summary'] === true && $seed['manager']['capabilities']['daily_summary'] === true && $seed['receptionist']['capabilities']['daily_summary'] === true && $seed['finance']['capabilities']['daily_summary'] === false && $seed['keamanan']['capabilities']['daily_summary'] === false, 'Seed Sprint A #1: daily_summary = fallback route 090 (admin/manager/resepsionis)');

// Normalisasi entri dari klien.
$roundtrip = tamasyaRolePresetNormalize(json_encode($seed['admin']));
presetCheck($roundtrip === $seed['admin'], 'Normalisasi roundtrip seed admin utuh (JSON string diterima)');
$partial = tamasyaRolePresetNormalize(['desktopTabs'=>['dashboard'=>true], 'telegramNotifications'=>[], 'capabilities'=>['view_audit_log'=>'1']]);
presetCheck($partial['desktopTabs']['dashboard'] === true && $partial['desktopTabs']['rooms'] === false && $partial['capabilities']['view_audit_log'] === true && $partial['capabilities']['manage_backup'] === false && $partial['telegramNotifications']['finance'] === false, 'Kunci absen = false dan 1/"1" diterima (eksplisit, tanpa warisan)');
$rejectedUnknown = false;
try { tamasyaRolePresetNormalize(['desktopTabs'=>[], 'telegramNotifications'=>[], 'capabilities'=>['capability_palsu'=>true]]); } catch (InvalidArgumentException $e) { $rejectedUnknown = true; }
presetCheck($rejectedUnknown, 'Kunci di luar registry ditolak');
$rejectedMissing = false;
try { tamasyaRolePresetNormalize(['desktopTabs'=>[]]); } catch (InvalidArgumentException $e) { $rejectedMissing = true; }
presetCheck($rejectedMissing, 'Grup paket yang hilang ditolak');
$rejectedValue = false;
try { tamasyaRolePresetNormalize(['desktopTabs'=>[], 'telegramNotifications'=>[], 'capabilities'=>['manage_backup'=>'ya']]); } catch (InvalidArgumentException $e) { $rejectedValue = true; }
presetCheck($rejectedValue, 'Nilai non-boolean ditolak (bukan diam-diam false)');

// Pembacaan toleran + pembangunan override.
$coerced = tamasyaRolePresetCoerce(['desktopTabs'=>['dashboard'=>1,'rooms'=>'true','alien'=>true], 'telegramNotifications'=>['finance'=>'sampah'], 'capabilities'=>['view_audit_log'=>'1']]);
presetCheck(count($coerced['desktopTabs']) === 15 && $coerced['desktopTabs']['dashboard'] === true && $coerced['desktopTabs']['rooms'] === true && $coerced['telegramNotifications']['finance'] === false && $coerced['capabilities']['view_audit_log'] === true && $coerced['capabilities']['manage_backup'] === false, 'Coerce toleran: kunci asing diabaikan, nilai ganjil jadi false');
$override = tamasyaRolePresetBuildOverride($seed['manager'], ['readOnly'=>true,'capabilities'=>['manage_backup'=>true]]);
presetCheck($override['readOnly'] === true && $override['capabilities'] === $seed['manager']['capabilities'] && $override['desktopTabs'] === $seed['manager']['desktopTabs'], 'Override gabungan: kunci lain (readOnly) dipertahankan, tiga grup ditimpa penuh');
$overrideFromJunk = tamasyaRolePresetBuildOverride($seed['finance'], '{bukan json');
presetCheck($overrideFromJunk['capabilities'] === $seed['finance']['capabilities'] && !isset($overrideFromJunk['readOnly']), 'Override dari permissions korup tetap aman (mulai basis kosong)');

// Diff pratinjau: acuan sebelum = override akun, jatuh ke seed jabatan bila tidak ada.
$noDiff = tamasyaRolePresetAccountDiff($seed['admin'], 'admin', null);
presetCheck($noDiff === [], 'Akun tanpa override tidak berubah bila paket = seed jabatan');
$flip = tamasyaRolePresetAccountDiff(array_merge($seed['finance'], ['capabilities'=>array_merge($seed['finance']['capabilities'], ['manage_backup'=>true])]), 'finance', null);
presetCheck(count($flip) === 1 && $flip['capabilities']['manage_backup'] === ['before'=>false,'after'=>true], 'Perubahan tunggal terdeteksi dengan before dari seed jabatan');
$overrideWins = tamasyaRolePresetAccountDiff(array_merge($seed['finance'], ['capabilities'=>array_merge($seed['finance']['capabilities'], ['receive_payment_access'=>false])]), 'finance', json_encode(['capabilities'=>['receive_payment_access'=>true]]));
presetCheck(count($overrideWins) === 1 && $overrideWins['capabilities']['receive_payment_access'] === ['before'=>true,'after'=>false], 'Override akun menang sebagai nilai sebelum');
$fallback = tamasyaRolePresetAccountDiff(array_merge($seed['manager'], ['capabilities'=>array_merge($seed['manager']['capabilities'], ['perform_housekeeping'=>false])]), 'manager', json_encode(['capabilities'=>[]]));
presetCheck(count($fallback) === 1 && $fallback['capabilities']['perform_housekeeping'] === ['before'=>true,'after'=>false], 'Override tanpa kunci jatuh ke seed jabatan sebagai nilai sebelum');

// Penambalan baris lama (Fase 3): kunci baru diisi seed, centangan lama tak tersentuh.
$oldRow = ['capabilities'=>['manage_backup'=>true], 'desktopTabs'=>['dashboard'=>true], 'telegramNotifications'=>['finance'=>true]];
$healedRow = tamasyaRolePresetBackfill($oldRow, 'finance');
presetCheck(count($healedRow['capabilities']) === 23 && $healedRow['capabilities']['manage_backup'] === true && $healedRow['capabilities']['view_reports'] === true && $healedRow['capabilities']['view_support_inbox'] === false && $healedRow['capabilities']['sell_room_access'] === false && $healedRow['capabilities']['daily_summary'] === false && count($healedRow['desktopTabs']) === 15 && $healedRow['desktopTabs']['dashboard'] === true && $healedRow['desktopTabs']['rooms'] === false && $healedRow['telegramNotifications']['finance'] === true, 'Backfill Fase 3/Sprint A #1: kunci lama dipertahankan, kunci baru diisi seed jabatan (23 kunci penuh)');
presetCheck(tamasyaRolePresetBackfill($seed['admin'], 'admin') === $seed['admin'] && tamasyaRolePresetBackfill($seed['lain_lain'], 'lain_lain') === $seed['lain_lain'], 'Backfill idempoten pada paket yang sudah lengkap');

// Jalur rilis: route, router, require boot, migrasi, baseline setup, UI.
$route = file_get_contents(dirname(__DIR__).'/api/routes/052_role_presets.php');
presetCheck(str_contains($route, "!== 'role-preset'") && str_contains($route, "requireRoles(\$loggedInStaff,['admin']);") && str_contains($route, 'tamasyaEnforceOwnerReadOnly($loggedInStaff,(string)$action,$isMutationCommand);') && str_contains($route, "\$isMutationCommand&&\$method!=='POST'"), 'Route 052 admin-only + owner read-only per command + mutation wajib POST');
presetCheck(str_contains($route, 'role-preset-list') && str_contains($route, 'role-preset-save') && str_contains($route, 'role-preset-preview') && str_contains($route, 'role-preset-apply'), 'Empat command paket jabatan tersedia');
presetCheck(str_contains($route, "tamasyaRolePresetNormalize(\$input['preset']??null)") && str_contains($route, "SELECT role,permissions,updated_at FROM role_capability_presets WHERE role=? FOR UPDATE"), 'Simpan tervalidasi; penerapan membaca paket tersimpan (bukan kiriman klien)');
presetCheck(str_contains($route, "(\$input['confirm']??null)!==true") && substr_count($route, 'writeRequiredEnterpriseAudit') >= 3, 'Terapkan butuh confirm=true dan tercatat audit (per akun + ringkasan)');
$router = file_get_contents(dirname(__DIR__).'/api/router.php');
presetCheck(str_contains($router, "routes/052_role_presets.php"), 'Router memuat route 052');
$apiBoot = file_get_contents(dirname(__DIR__).'/api.php');
presetCheck(str_contains($apiBoot, "'021_role_presets.php';"), 'Boot api.php memuat support 021');
$migration = file_get_contents(dirname(__DIR__).'/migrations/20261019_ROLE_CAPABILITY_PRESETS.sql');
presetCheck(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `role_capability_presets`') && str_contains($migration, 'INSERT IGNORE INTO `role_capability_presets`'), 'Migrasi idempoten: tabel + seed INSERT IGNORE');
preg_match_all("/^\('([a-z_]+)', '/m", $migration, $seedRows);
presetCheck(count($seedRows[1]) === 9 && !in_array('owner', $seedRows[1], true) && $seedRows[1] === $roles, 'Migrasi men-seed 9 jabatan kanonik tanpa owner');
$baseline = file_get_contents(dirname(__DIR__).'/database_setup.sql');
presetCheck(str_contains($baseline, 'CREATE TABLE IF NOT EXISTS `role_capability_presets`') && str_contains($baseline, 'INSERT IGNORE INTO `role_capability_presets`'), 'database_setup.sql memuat tabel + seed paket jabatan');
preg_match_all('/^CREATE TABLE/m', $baseline, $baselineTables);
presetCheck(count($baselineTables[0]) === 133, 'Baseline = 133 CREATE TABLE (116 + role_capability_presets + staff_job_titles + 7 tabel Dynamic RBAC + exchange_rates + promotion_codes + promotion_code_usages)');
$staffChunk = file_get_contents(dirname(__DIR__).'/assets/chunks/staff.js');
presetCheck(str_contains($staffChunk, 'function RolePresetPanel(') && str_contains($staffChunk, 'export {QleBridge as Qle};') && str_contains($staffChunk, 'function Qle({currentRole:e,onRefreshData:a,currentPermissions:tamasyaCurrentPermissions})') && str_contains($staffChunk, 't.jsx(Qle,{currentPermissions:tamasyaCurrentPermissions,currentRole:e,onRefreshData:a})') && str_contains($staffChunk, 'function QleBridge({currentRole:e,onRefreshData:a,currentPermissions:tamasyaCurrentPermissions})'), 'UI: panel Kelola Jabatan + bridge Qle meneruskan currentPermissions ke Qle asli');
presetCheck(str_contains($staffChunk, 'const[rpOpen,setRpOpen]=f.useState(!1);') && str_contains($staffChunk, 'rpOpen?"":"hidden"'), 'UI: panel Kelola Jabatan terlipat default agar centangan tidak tampil dobel dengan form akun');
presetCheck(str_contains($staffChunk, 'Default Semua Akun') && str_contains($staffChunk, 'Izin Khusus Akun Ini') && str_contains($staffChunk, 'HANYA berlaku untuk akun ini'), 'UI: label pembeda jelas antara paket jabatan (semua akun) dan izin khusus akun');
presetCheck(str_contains($staffChunk, 'action=role-preset') && str_contains($staffChunk, '"role-preset-apply"') && str_contains($staffChunk, '"role-preset-save"'), 'UI memanggil endpoint 052 (list/save/preview/apply)');
$authPolicy = file_get_contents(dirname(__DIR__).'/api/support/017_authorization_policy.php');
presetCheck(str_contains($authPolicy, "function hasCapability(\$user, \$capability, \$fallbackRoles = [])") && str_contains($authPolicy, "tamasyaPermissionOverride(\$user['permissions']??null,'capabilities',(string)\$capability)"), 'Enforcement 017 tidak berubah (override lalu fallback role)');
$dailyFallback = ['admin','manager','receptionist'];
presetCheck(hasCapability(['role'=>'admin','permissions'=>null], 'daily_summary', $dailyFallback) === true && hasCapability(['role'=>'manager','permissions'=>null], 'daily_summary', $dailyFallback) === true && hasCapability(['role'=>'receptionist','permissions'=>null], 'daily_summary', $dailyFallback) === true && hasCapability(['role'=>'finance','permissions'=>null], 'daily_summary', $dailyFallback) === false && hasCapability(['role'=>'owner','permissions'=>null], 'daily_summary', $dailyFallback) === true && hasCapability(['role'=>'admin','permissions'=>'{"capabilities":{"daily_summary":false}}'], 'daily_summary', $dailyFallback) === false && hasCapability(['role'=>'finance','permissions'=>'{"capabilities":{"daily_summary":true}}'], 'daily_summary', $dailyFallback) === true, 'daily_summary: fallback 090 dipertahankan, override akun menang, Owner membaca seperti Admin (tulis tetap diblokir penjaga metode)');
presetCheck(str_contains($authPolicy, "'view_guest_identity','view_reports','view_support_inbox','manage_facilities','daily_summary']"), 'Owner session 017 memuat capability Fase 3/Sprint A #1 (view_* tetap read-only)');

// Fase 3: route yang dikonversi ke capability + jalur rilisnya.
$reportRoute = file_get_contents(dirname(__DIR__).'/api/routes/095_canonical_reports.php');
presetCheck(substr_count($reportRoute, "requireCapability(\$loggedInStaff,'view_reports',['admin','manager','finance']);") === 3 && substr_count($reportRoute, "requireDesktopTabAccess(\$loggedInStaff,'report',['admin','manager','finance']);") === 3, '095: tiga aksi via view_reports, tab report tetap');
$supportInbox = file_get_contents(dirname(__DIR__).'/api/routes/017_public_support_inbox.php');
presetCheck(str_contains($supportInbox, "requireCapability(\$loggedInStaff,'view_support_inbox',['admin','manager','receptionist']);") && !str_contains($supportInbox, 'requireRoles('), '017: kotak masuk bantuan via view_support_inbox');
$reservationInbox = file_get_contents(dirname(__DIR__).'/api/routes/018_public_reservation_inbox.php');
presetCheck(str_contains($reservationInbox, "requireCapability(\$loggedInStaff, 'view_support_inbox', ['admin','manager','receptionist']);") && !str_contains($reservationInbox, 'requireRoles('), '018: review reservasi via view_support_inbox');
$facilityRoute = file_get_contents(dirname(__DIR__).'/api/routes/096_facility_management.php');
presetCheck(substr_count($facilityRoute, "requireCapability(\$loggedInStaff,'manage_facilities',['admin','manager']);") === 2 && substr_count($facilityRoute, "requireRoles(\$loggedInStaff,['admin','manager','receptionist','finance']);") === 3 && substr_count($facilityRoute, "requireRoles(\$loggedInStaff,['admin','manager','receptionist']);") === 4, '096: hanya simpan master fasilitas via capability; baca & booking tetap keras');
presetCheck(str_contains($staffChunk, '["view_reports","Lihat & kirim laporan kanonik"]') && str_contains($staffChunk, '["manage_facilities","Kelola master fasilitas"]') && substr_count($staffChunk, '["view_support_inbox",') === 1 && substr_count($staffChunk, '["daily_summary",') === 1 && substr_count($staffChunk, 'rpCapLabels.map') === 1, 'UI: fallback 3 label Fase 3 + daily_summary tetap ada; grid editor & panel memakai map katalog (23 centangan)');
$migration3 = file_get_contents(dirname(__DIR__).'/migrations/20261019_ROLE_CAPABILITY_PRESETS_FASE3.sql');
presetCheck(str_contains($migration3, 'JSON_MERGE_PATCH') && str_contains($migration3, "view_reports') IS NULL") && str_contains($migration3, "'admin','manager'") && str_contains($migration3, "'finance'") && str_contains($migration3, "'receptionist'") && str_contains($migration3, "'cleaning_service','keamanan','koki','tukang_kebun','lain_lain'"), 'Migrasi Fase 3 idempoten + sembilan jabatan ter-cover');
$migration4 = file_get_contents(dirname(__DIR__).'/migrations/20261020_DAILY_SUMMARY_CAPABILITY.sql');
presetCheck(str_contains($migration4, 'JSON_MERGE_PATCH') && str_contains($migration4, "daily_summary') IS NULL") && str_contains($migration4, "'admin','manager','receptionist'") && str_contains($migration4, "'finance'") && str_contains($migration4, "'cleaning_service','keamanan','koki','tukang_kebun','lain_lain'"), 'Migrasi Sprint A #1: daily_summary idempoten + sembilan jabatan ter-cover');
preg_match_all('/^\\(\'([a-z_]+)\', \'(\{.*\})\'\\)[,;]$/m', $baseline, $baselineRows, PREG_SET_ORDER);
$baselineSeed = [];
foreach ($baselineRows as $row) {
    $decoded = json_decode($row[2], true);
    if (!is_array($decoded) || !isset($decoded['desktopTabs'])) continue;
    if (in_array($row[1], $roles, true)) $baselineSeed[$row[1]] = $decoded;
}
presetCheck(count($baselineSeed) === 9 && $baselineSeed === $seed, 'Seed database_setup.sql = tamasyaRolePresetSeed() (paritas penuh 23 kunci, 9 jabatan)');

// R19: jabatan dinamis (tabel staff_job_titles + staff.job_title) tanpa mengubah role.
$titleDefaults = tamasyaJobTitleSystemDefaults();
presetCheck(count($titleDefaults) === 9 && $titleDefaults['admin']['base_role'] === 'admin' && $titleDefaults['lain_lain']['sort_order'] === 90, 'Fallback 9 jabatan sistem: key = base role + urutan kanonik');
presetCheck(tamasyaJobTitleSlug('Kasir Malam') === 'kasir_malam' && tamasyaJobTitleSlug('  Kasir & Front Office  ') === 'kasir_front_office' && tamasyaJobTitleSlug('!!!') === 'jabatan' && strlen(tamasyaJobTitleSlug(str_repeat('A', 40))) === 30, 'Slug jabatan: huruf kecil, non-alfanumerik -> _, maks 30, fallback');
presetCheck(tamasyaRolePresetDefaultFor('kasir', 'finance') === $seed['finance'] && tamasyaRolePresetDefaultFor('kasir') === $seed['lain_lain'] && tamasyaRolePresetDefaultFor('finance', 'admin') === $seed['finance'], 'Default jabatan dinamis = seed base role; jabatan sistem tetap seed sendiri');
$jobTitleMigration = file_get_contents(dirname(__DIR__).'/migrations/20261019_STAFF_JOB_TITLES.sql');
presetCheck(str_contains($jobTitleMigration, 'CREATE TABLE IF NOT EXISTS `staff_job_titles`') && str_contains($jobTitleMigration, 'information_schema.COLUMNS') && str_contains($jobTitleMigration, "'ALTER TABLE `staff` ADD COLUMN `job_title`") && !str_contains($jobTitleMigration, 'ADD COLUMN IF NOT EXISTS'), 'Migrasi jabatan: tabel + kolom via guard information_schema (kompatibel MySQL/MariaDB)');
preg_match_all("/^\('([a-z_]+)','([^']+)','([a-z_]+)',1,/m", $jobTitleMigration, $jobTitleSeedRows);
$jobTitleSeedOk = count($jobTitleSeedRows[1]) === 9 && $jobTitleSeedRows[1] === $roles && $jobTitleSeedRows[3] === $roles;
foreach ($jobTitleSeedRows[2] as $idx => $seedLabel) { if ($seedLabel === '' || $seedLabel !== $titleDefaults[$roles[$idx]]['label']) $jobTitleSeedOk = false; }
presetCheck($jobTitleSeedOk, 'Migrasi men-seed 9 jabatan sistem (key = base role, label sesuai fallback)');
presetCheck(str_contains($baseline, 'CREATE TABLE IF NOT EXISTS `staff_job_titles`') && str_contains($baseline, '`job_title` varchar(30) DEFAULT NULL,') && str_contains($baseline, '`idx_staff_job_title`') && str_contains($jobTitleMigration, '`idx_staff_job_title`'), 'database_setup.sql memuat tabel jabatan, kolom staff.job_title, dan indeks (sama dengan migrasi)');
$staffRoute = file_get_contents(dirname(__DIR__).'/api/routes/050_auth_staff.php');
presetCheck(str_contains($staffRoute, '$resolveStaffTitle') && str_contains($staffRoute, 's.job_title AS jobTitle') && str_contains($staffRoute, "'jobTitleLabel'") && str_contains($staffRoute, '$hasStaffJobTitleColumn'), 'Route 050: resolve jabatan + fallback kolom, GET & login memuat jobTitle/jobTitleLabel');
presetCheck(str_contains($staffRoute, 'twoFactorActive{$jobTitleSelect},') && str_contains($staffRoute, 'leaveType{$jobTitleSelect}"'), 'Route 050: kolom jobTitle terpisah koma dari kolom berikutnya (query GET staff valid)');
presetCheck(str_contains($route, 'job-title-save') && str_contains($route, 'job-title-delete') && str_contains($route, "'roles'=>array_keys(\$jobTitles)") && str_contains($route, "'staffCounts'=>\$counts"), 'Route 052: command simpan/hapus jabatan + list dinamis (labels/bases/system/staffCounts)');
presetCheck(str_contains($route, "\$taken['owner']=true;"), 'Route 052: key owner dicadangkan agar jabatan kustom tidak menabrak owner');
presetCheck(str_contains($staffChunk, 'rpApplyTitleData') && str_contains($staffChunk, 'jobTitle:b') && str_contains($staffChunk, 'Tambah Jabatan') && str_contains($staffChunk, 'Hapus Jabatan') && str_contains($staffChunk, 'job-title-save') && str_contains($staffChunk, 'job-title-delete'), 'UI: dropdown/filter/payload jabatan dinamis + panel tambah/rename/hapus');
presetCheck(str_contains($staffChunk, '"/api.php?action=role-preset&"'), 'UI panel: URL POST role-preset valid (command di body, bukan menempel di action)');
presetCheck(str_contains($route, 'in_array($baseRole,tamasyaRolePresetRoles(),true)') && str_contains($route, 'Role basis jabatan tidak dapat diubah'), 'F-07: jabatan kustom hanya mewarisi 9 base role; base role tidak bisa diubah diam-diam');
presetCheck(preg_match('/if \(isset\(\$input\[\'permissions\'\]\)\) \{\s*\$permissions = json_encode\(\$normalizedPermissions\);\s*\} else \{.*?\$permissions = null;/s', $staffRoute) === 1 && !str_contains($staffRoute, 'tamasyaRolePresetDefaultFor'), 'Route 050: akun baru dengan jabatan kustom tidak menyimpan salinan preset sebagai override');
presetCheck(str_contains($staffRoute, 'tamasyaRolePresetEnsureRows') && str_contains($staffRoute, 'tamasyaRolePresetEnsureRows($pdo)'), 'Route 050: baris paket jabatan dipastikan ada agar override NULL langsung mengikuti preset');
presetCheck(str_contains($staffRoute, '$titleActuallyChanged') && str_contains($staffRoute, '!array_key_exists(\'permissions\',$input) && $titleActuallyChanged') && preg_match('/if \(!array_key_exists\(\'permissions\',\$input\) && \$titleActuallyChanged\) \{.*?\$params\[\] = null;/s', $staffRoute) === 1 && !str_contains($staffRoute, 'json_encode(tamasyaRolePresetDefaultFor($jobTitle,$role))'), 'Route 050: override direset ke NULL (bukan salinan preset) saat jabatan/role berubah, supaya akun tetap mengikuti preset jabatan');
presetCheck(str_contains($staffRoute, "requireDesktopTabAccess(\$loggedInStaff, 'staff', ['admin','owner']);") && str_contains($staffRoute, "requireDesktopTabAccess(\$loggedInStaff, 'leaves', ['admin','manager']);") && str_contains($staffRoute, "in_array(\$loggedInStaff['role'] ?? '', ['admin','owner'], true)"), 'Route 050: GET staf penuh wajib tab staff, scope cuti wajib tab leaves; data sensitif hanya admin/owner');
$telegramSource = file_get_contents(dirname(__DIR__).'/api/modules/comms/050_integrations_telegram_mail.php');
presetCheck(str_contains($telegramSource, 'tamasyaRbacEffectivePermissions($staff, $pdo)') && str_contains($telegramSource, 'tamasyaPermissionOverride($permissionSource,') && str_contains($telegramSource, 's.job_title'), 'Telegram memakai permission efektif preset + override akun');
$operationsSource = file_get_contents(dirname(__DIR__).'/assets/chunks/operations.js');
presetCheck(str_contains($operationsSource, 'taxAllowed=tamasyaCap("manage_tax_rules"') && preg_match('/taxAllowed=tamasyaCap\("manage_tax_rules",\[[^\]]+\]\)/', $operationsSource) === 1 && str_contains($operationsSource, 'action:taxAllowed?') && str_contains($operationsSource, 'children:taxAllowed&&') && str_contains($operationsSource, 'Hn=tamasyaCap("perform_night_audit"') && !str_contains($operationsSource, 'Hn=e.currentRole==="finance"||'), 'UI pajak & night audit mengikuti capability, bukan role statis');
$appCoreSource = file_get_contents(dirname(__DIR__).'/assets/app-core.js');
presetCheck(!str_contains($appCoreSource, 'e==="finance"&&(s.perform_night_audit=!0)'), 'app-core tidak memaksa capability finance di luar preset');

echo "Role preset (Fase 2/3 Kelola Jabatan): {$passed} passed; 0 failed.".PHP_EOL;
