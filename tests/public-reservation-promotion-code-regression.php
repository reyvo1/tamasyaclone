<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$public = file_get_contents($root . '/api/routes/015_public_website.php');
$booking = file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
$db = file_get_contents($root . '/database_setup.sql');
$migrationFiles = glob($root . '/migrations/*');
$migrationText = '';
foreach ($migrationFiles as $migrationFile) {
    if (is_file($migrationFile)) $migrationText .= "\n" . file_get_contents($migrationFile);
}
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($public) && $public !== '', 'public website route readable');
verify(is_string($booking) && $booking !== '', 'booking finance readable');
verify(is_string($db) && $db !== '', 'database setup readable');
verify(str_contains($db, "`promo_code` varchar(80)"), 'schema promo_code column present');
verify(str_contains($migrationText, 'public_reservation_requests') && str_contains($migrationText, 'promo_code'), 'migration promo_code column present');
verify(str_contains($public, "\$promoCode = strtoupper(trim((string)(\$input['promoCode']"), 'public promoCode accepted');
verify(str_contains($public, "Kode promo tidak berlaku untuk reservasi booking."), 'public promo applies_to=booking validated');
verify(str_contains($public, "notes,promo_code,source,status"), 'promo_code stored in public insert');
verify(str_contains($booking, "promo_code FROM public_reservation_requests"), 'booking reads stored promo_code');
verify(str_contains($booking, "\$promoCodeInput=trim((string)\$publicRow['promo_code'])"), 'stored promo passed to booking creation');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
