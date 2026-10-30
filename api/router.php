<?php
/** Ordered modular route dispatcher. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
$routeHandled=false;
// TAMASYA_ROUTE_EXCEPTION_BOUNDARY_15_36: every route must return a valid,
// role-safe JSON response and must not leave an open transaction.
try {
    require __DIR__ . '/routes/010_cluster_status.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/013_audit_logs.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/014_permission_inspector.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/015_public_website.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/016_public_site_admin.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/017_public_support_inbox.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/018_public_reservation_inbox.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/019_property_setup.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/020_hotel_booking.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/030_ota_cleanup_audit.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/040_transactions_sync.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/050_auth_staff.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/052_role_presets.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/058_biometric_attendance.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/060_workforce_inventory.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/062_employee_self_service.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/065_pos_minibar.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/070_config_telegram_admin.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/075_communication_channels.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/076_communication_webhook.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/080_telegram_webhook.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/086_system_health.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/087_guest_reviews.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/088_wholesale_rates.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/089_exchange_rates.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/090_operations_communications.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/091_receipt_storage_health.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/095_canonical_reports.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/096_facility_management.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/099_universal_catalog.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/100_catalog_downloads.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/110_growth_suite.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/112_enterprise_completion.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/115_multi_property_foundation.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/118_internal_memos.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/119_tenant_billing.php';
    if ($routeHandled) { return; }
    require __DIR__ . '/routes/120_promotion_codes.php';
    if ($routeHandled) { return; }
    http_response_code(404);
    echo json_encode([
        'success'=>false,
        'message'=>'Endpoint API tidak ditemukan! Gunakan action seperti: ?action=hotel-data, ?action=bookings, atau ?action=rooms'
    ]);
} catch (Throwable $routeError) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $rollbackError) {
            error_log(clientExceptionMessage('[router] rollback gagal', $rollbackError));
        }
    }
    if (ob_get_level() > 0) { @ob_clean(); }
    tamasyaApplyExceptionHttpStatus($routeError, 500);
    echo json_encode([
        'success' => false,
        'error' => clientExceptionMessage('Operasi gagal', $routeError),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $routeHandled = true;
}
