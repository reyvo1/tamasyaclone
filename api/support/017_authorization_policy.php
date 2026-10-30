<?php
/**
 * TAMASYA V137 canonical authorization policy.
 *
 * FINAL11 root hardening separates role/capability/desktop-tab decisions from
 * the legacy identity/audit support. Route modules continue using compatibility
 * function names, but all authorization interpretation now lives here.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Normalise a submitted permissions payload before it reaches the database.
 *
 * The staff create/update handlers used to call json_encode() on the raw input, so a
 * client that posted permissions as a JSON *string* was stored double-encoded. Later
 * reads decode that column to a PHP string instead of an array, which
 * tamasyaPermissionOverride() treats as corrupt and hard-denies. Because
 * tamasyaSessionPermissions() then reports plain null, the browser falls back to the
 * role and renders the action, while the server refuses it: the operator sees a
 * working button that answers 403 on every save.
 *
 * Only an object/array payload is accepted from now on; anything else is rejected at
 * the boundary with an actionable message instead of silently bricking the account.
 */
function tamasyaNormalizeSubmittedPermissions($raw): array {
    if ($raw === null) return [];
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') return [];
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('Format permissions tidak valid. Kirim objek JSON (desktopTabs/capabilities), bukan teks JSON.');
        }
        return $decoded;
    }
    if (!is_array($raw)) {
        throw new InvalidArgumentException('Format permissions tidak valid. Kirim objek JSON (desktopTabs/capabilities).');
    }
    return $raw;
}

/** Repair one staff.permissions value that can no longer be decoded into an object.
 *
 * Returns true when the row was rewritten. Passing the already loaded row avoids a
 * second SELECT on the login hot path.
 */
function tamasyaRepairStaffPermissionsRow(PDO $pdo, string $staffId, $raw): bool {
    if ($raw === null || trim((string)$raw) === '') return false;
    if (is_array(json_decode((string)$raw, true))) return false;
    $pdo->prepare("UPDATE staff SET permissions=NULL WHERE id=?")->execute([$staffId]);
    error_log('[Permissions] staff.permissions tidak dapat dibaca sebagai objek; dikembalikan ke baseline role untuk staff ' . $staffId);
    return true;
}

/** Repair every staff.permissions row that can no longer be decoded into an object.
 *
 * A corrupt override is unrecoverable through the UI: saving the account is itself
 * permission gated, so the account stays locked out of every mutation. The role in
 * staff.role remains the authoritative baseline entitlement, so an unreadable
 * override is normalised back to that baseline rather than denying every action
 * forever. The rewrite is logged so the change is auditable.
 */
function tamasyaRepairCorruptStaffPermissions(PDO $pdo): int {
    $rows = $pdo->query("SELECT id, permissions FROM staff WHERE permissions IS NOT NULL AND TRIM(permissions) <> ''")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $repaired = 0;
    foreach ($rows as $row) {
        if (tamasyaRepairStaffPermissionsRow($pdo, (string)$row['id'], $row['permissions'])) $repaired++;
    }
    return $repaired;
}

/** Source line 2039: requireRoles */
function tamasyaIsOwnerRole($user): bool {
    return is_array($user) && strtolower(trim((string)($user['role'] ?? ''))) === 'owner';
}

/** Owner preset is fixed: every workspace can be read, no mutation capability. */
function tamasyaSessionPermissions(array $user, ?PDO $pdo = null) {
    $permissions=$user['permissions']??null;
    if(is_string($permissions))$permissions=json_decode($permissions,true);
    if(!tamasyaIsOwnerRole($user)){
        if(function_exists('tamasyaRbacEffectivePermissions')){
            $effective=tamasyaRbacEffectivePermissions($user,$pdo);
            if(is_array($effective))return $effective;
        }
        return is_array($permissions)?$permissions:null;
    }
    $tabs=['dashboard','rooms','finance','savings','report','operations','website','support','telegram','staff','config','db_config','inventory','leaves','attendance','pos','local_connect'];
    $capabilityKeys=['approve_sensitive_actions','manage_sessions','resolve_sync_conflict','manage_tax_rules','manage_backup','reconcile_payments','correct_booking_audit','manage_room_access','perform_night_audit','perform_housekeeping','resolve_night_audit','manage_operational_settings','override_key_checkout','manage_public_website','sell_room_access','manage_stay_access','receive_payment_access','view_audit_log','view_guest_identity','view_reports','view_support_inbox','manage_facilities','daily_summary'];
    $capabilities=[];
    // Owner memakai capability Admin (preset Admin + status katalog) supaya
    // Owner benar-benar bisa melihat segalanya seperti Admin, hanya saja tidak
    // boleh mengubah apa pun. Notifikasi Telegram tetap dimatikan untuk Owner
    // karena mengirim pesan adalah tindakan, bukan pembacaan.
    $adminPreset=(function_exists('tamasyaRbacRolePreset')&&$pdo)?tamasyaRbacRolePreset('admin',$pdo):null;
    $adminMap=(is_array($adminPreset)&&isset($adminPreset['capabilities'])&&is_array($adminPreset['capabilities']))?$adminPreset['capabilities']:[];
    foreach($capabilityKeys as $key){
        if(array_key_exists($key,$adminMap))$capabilities[$key]=((bool)$adminMap[$key]);
        elseif(function_exists('tamasyaRbacRolePermission'))$capabilities[$key]=(bool)tamasyaRbacRolePermission('admin','capabilities',(string)$key,$pdo);
        else $capabilities[$key]=str_starts_with($key,'view_');
    }
    return ['desktopTabs'=>array_fill_keys($tabs,true),'telegramNotifications'=>array_fill_keys(['bookings','finance','inventory','operations','hr','system'],false),'capabilities'=>$capabilities,'readOnly'=>true];
}

function tamasyaOwnerMutationDenied($user, ?bool $isMutation = null): bool {
    if (!tamasyaIsOwnerRole($user)) return false;
    if ($isMutation !== null) return $isMutation;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return !in_array($method, ['GET','HEAD','OPTIONS'], true);
}

function tamasyaEnforceOwnerReadOnly($user, string $action = '', ?bool $isMutation = null): void {
    if (!tamasyaOwnerMutationDenied($user, $isMutation)) return;
    if ($action === 'logout') return;
    http_response_code(403);
    echo json_encode([
        'success'=>false,
        'code'=>'OWNER_READ_ONLY',
        'error'=>'Owner adalah role read-only. Perubahan data tidak diizinkan.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireRoles($user, $roles) {
    if (tamasyaIsOwnerRole($user)) {
        if (!tamasyaOwnerMutationDenied($user)) return;
    }
    $effectiveRoles = $roles;
    if (function_exists('tamasyaRbacAccessGroupRoles')) {
        $groupRoles = tamasyaRbacAccessGroupRoles((array)$roles);
        if (is_array($groupRoles)) $effectiveRoles = $groupRoles;
    }
    if (!$user || !in_array($user['role'] ?? '', $effectiveRoles, true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "error" => "Forbidden: Anda tidak memiliki izin untuk operasi ini."]);
        exit;
    }
}

/** Source line 2047: hasCapability */
function tamasyaPermissionOverride($permissions, string $group, string $key): ?bool {
    if($permissions===null || $permissions==='')return null;
    if(is_string($permissions))$permissions=json_decode($permissions,true);
    // Corrupt or unexpected permission shapes must deny, never grant via a role fallback.
    if(!is_array($permissions))return false;
    if(!array_key_exists($group,$permissions))return null;
    if(!is_array($permissions[$group]))return false;
    if(!array_key_exists($key,$permissions[$group]))return null;
    $value=$permissions[$group][$key];
    if(is_bool($value))return $value;
    if($value===1 || $value==='1')return true;
    if(is_string($value) && strtolower(trim($value))==='true')return true;
    return false;
}

function hasCapability($user, $capability, $fallbackRoles = []) {
    if (!$user) return false;
    if (tamasyaIsOwnerRole($user)) {
        // Owner membaca persis seperti Admin: capability diambil dari preset Admin
        // (dan mengikuti status katalog), sehingga tampilan dan API selalu sama.
        // Owner tetap tidak dapat mengubah apa pun karena SETIAP jalur tulis
        // mewajibkan metode selain GET/HEAD/OPTIONS, dan api.php memblokir Owner
        // pada setiap metode tersebut sebelum route dijalankan.
        $capability = (string)$capability;
        // Owner memakai capability Admin HANYA untuk pembacaan. Pada metode tulis
        // capability Owner selalu false: api.php sudah memblokir Owner pada
        // metode tersebut, dan ini lapisan kedua bila ada route yang lupa
        //(method guard).
        if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true)) return false;
        // Katalog yang sengaja dinonaktifkan menutup akses untuk Owner juga,
        // persis seperti Admin: Owner memakai paket yang sama.
        if (function_exists('tamasyaRbacCatalogKeyState')
            && tamasyaRbacCatalogKeyState('capabilities', $capability) === 'inactive') return false;
        // Semua capability view_* selalu boleh untuk Owner, walau katalog atau
        // preset belum tersedia (instalasi tanpa PDO / instalasi lama).
        if (str_starts_with($capability, 'view_')) return true;
        if (function_exists('tamasyaRbacRolePermission')) {
            $adminValue = tamasyaRbacRolePermission('admin', 'capabilities', $capability);
            if ($adminValue !== null) return (bool)$adminValue;
        }
        return in_array('admin', (array)$fallbackRoles, true);
    }
    if (function_exists('tamasyaRbacCatalogKeyState') && tamasyaRbacCatalogKeyState('capabilities',(string)$capability) === 'inactive') return false;
    $override=tamasyaPermissionOverride($user['permissions']??null,'capabilities',(string)$capability);
    if($override!==null)return $override;
    if(function_exists('tamasyaRbacRolePermission')){
        $roleKey=tamasyaRbacRoleKeyForUser($user);
        $dbValue=tamasyaRbacRolePermission($roleKey,'capabilities',(string)$capability);
        if($dbValue!==null)return $dbValue;
    }
    return in_array($user['role'] ?? '', $fallbackRoles, true);
}

/** Source line 2057: requireCapability */
function requireCapability($user, $capability, $fallbackRoles = []) {
    if (!hasCapability($user, $capability, $fallbackRoles)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden: izin '.$capability.' diperlukan.']);
        exit;
    }
}


/** Source line 2191: hasDesktopTabAccess */
function hasDesktopTabAccess($user, $tab, $fallbackRoles = []) {
    if (!$user) return false;
    if (tamasyaIsOwnerRole($user)) return true;
    if (function_exists('tamasyaRbacCatalogKeyState') && tamasyaRbacCatalogKeyState('desktopTabs',(string)$tab) === 'inactive') return false;
    $override=tamasyaPermissionOverride($user['permissions']??null,'desktopTabs',(string)$tab);
    if($override!==null)return $override;
    if(function_exists('tamasyaRbacRolePermission')){
        $roleKey=tamasyaRbacRoleKeyForUser($user);
        $dbValue=tamasyaRbacRolePermission($roleKey,'desktopTabs',(string)$tab);
        if($dbValue!==null)return $dbValue;
    }
    return in_array($user['role'] ?? '', $fallbackRoles, true);
}

/** Workspace Kamar: baseline role, menu Kamar (desktopTabs) eksplisit, atau
 *  centangan sell_room_access/manage_stay_access per akun. Centangan capability
 *  menang atas desktopTabs.rooms=false supaya centang di editor staf selalu
 *  tembus ke UI, API, dan proyeksi data (070).
 */
function tamasyaHasRoomsWorkspaceAccess($user): bool {
    if (!is_array($user)) return false;
    if (tamasyaIsOwnerRole($user)) return true;
    $permissions=$user['permissions']??null;
    if(is_string($permissions))$permissions=json_decode($permissions,true);
    $caps=is_array($permissions)&&is_array($permissions['capabilities']??null)?$permissions['capabilities']:null;
    if($caps!==null&&(($caps['sell_room_access']??null)===true||($caps['manage_stay_access']??null)===true))return true;
    $override=tamasyaPermissionOverride($user['permissions']??null,'desktopTabs','rooms');
    if($override!==null)return $override===true;
    if(function_exists('tamasyaRbacRolePermission')){
        $roleKey=tamasyaRbacRoleKeyForUser($user);
        $dbValue=tamasyaRbacRolePermission($roleKey,'desktopTabs','rooms');
        if($dbValue!==null)return $dbValue;
    }
    return in_array(strtolower(trim((string)($user['role']??''))),['admin','manager','receptionist','owner'],true);
}

/** Source line 2201: requireDesktopTabAccess */
function requireDesktopTabAccess($user, $tab, $fallbackRoles = []) {
    if (!hasDesktopTabAccess($user, $tab, $fallbackRoles)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden: akses menu '.$tab.' diperlukan.']);
        exit;
    }
}
